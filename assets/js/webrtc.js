/**
 * VoiceTransmission — WebRTC peer connection manager.
 *
 * Roles:
 *  SENDER   – calls startTransmission(), records + streams audio to peer
 *  RECEIVER – detected via poll, auto-connects and plays incoming stream
 *
 * Signaling is done via polling (HTTP POST) since CI3 has no native WebSocket.
 * Poll interval: 1 000 ms while a transmission is active.
 */
'use strict';

class VoiceTransmission {

    constructor () {
        this.pc             = null;   // RTCPeerConnection
        this.localStream    = null;   // MediaStream from getUserMedia
        this.mediaRecorder  = null;
        this.audioChunks    = [];
        this.transmissionId = null;
        this.isTransmitting = false;  // I am the SENDER
        this.isReceiving    = false;  // I am the RECEIVER
        this.startTime      = null;
        this._sigPoll       = null;   // signaling poll interval handle
        this._icePending    = [];     // buffered ICE candidates until remote desc set
        this._remoteDescSet = false;
        this._onStopped     = null;   // callback when remote hangs up

        this.iceConfig = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' },
            ],
        };
    }

    // ================================================================
    // PUBLIC — SENDER side
    // ================================================================

    /**
     * Start transmitting to a conversation / receiver.
     * Returns { success, transmissionId, isLive }
     */
    async startTransmission (conversationId, receiverId) {
        if (this.isTransmitting || this.isReceiving) {
            return { success: false, message: 'Already in a transmission.' };
        }

        // Reset signaling state for a fresh transmission session.
        this._remoteDescSet = false;
        this._icePending    = [];

        // 1. Ask server to create the transmission record
        const res = await api('transmission/start', {
            conversation_id: conversationId,
            receiver_id:     receiverId,
        });
        if (!res.success) {
            return { success: false, message: res.message || res.error || 'Server error' };
        }

        this.transmissionId = res.transmission_id;
        this.isTransmitting = true;
        this.startTime      = null;

        // 2. Capture microphone
        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        } catch (e) {
            await this._abortTransmission();
            return { success: false, message: 'Microphone access denied: ' + e.message };
        }

        // 3. Start recording (always, even for pending transmissions)
        this._startRecording();

        // 4. If live, establish WebRTC peer connection and send offer
        if (res.is_live) {
            await this._createSenderPeerConnection();
        }

        return { success: true, transmissionId: this.transmissionId, isLive: res.is_live };
    }

    /**
     * Stop the current outgoing transmission.
     */
    async stopTransmission () {
        if (!this.isTransmitting) return;

        this.isTransmitting = false;

        // Tell server
        await api('transmission/stop', { transmission_id: this.transmissionId });

        // Stop recording and upload
        await this._stopRecordingAndUpload();

        // Close peer connection
        this._destroyPC();

        // Stop signaling poll
        this._stopSigPoll();
    }

    // ================================================================
    // PUBLIC — RECEIVER side
    // ================================================================

    /**
     * Called by the app when the incoming-transmission poll returns a live tx.
     * Returns true if we started receiving, false if busy.
     */
    async acceptIncoming (transmission) {
        if (this.isTransmitting || this.isReceiving) {
            return false;
        }

        this.isReceiving    = true;
        this.transmissionId = parseInt(transmission.id, 10);
        this._remoteDescSet = false;
        this._icePending    = [];

        await this._createReceiverPeerConnection();
        this._startSigPoll();
        return true;
    }

    /**
     * True if currently in any transmission (sender or receiver).
     */
    isBusy () {
        return this.isTransmitting || this.isReceiving;
    }

    // ================================================================
    // PRIVATE — peer connection helpers
    // ================================================================

    async _createSenderPeerConnection () {
        this.pc = new RTCPeerConnection(this.iceConfig);

        this.localStream.getTracks().forEach(t => this.pc.addTrack(t, this.localStream));

        this.pc.onicecandidate = e => {
            if (e.candidate) {
                api('signaling/send', {
                    transmission_id: this.transmissionId,
                    message_type:    'ice_candidate',
                    payload:         JSON.stringify(e.candidate),
                });
            }
        };

        this.pc.oniceconnectionstatechange = () => {
            if (['failed', 'disconnected', 'closed'].includes(this.pc?.iceConnectionState)) {
                console.warn('[WebRTC] ICE state:', this.pc?.iceConnectionState);
            }
        };

        const offer = await this.pc.createOffer({ offerToReceiveAudio: false });
        await this.pc.setLocalDescription(offer);

        await api('signaling/send', {
            transmission_id: this.transmissionId,
            message_type:    'offer',
            payload:         JSON.stringify(offer),
        });

        // Poll for answer
        this._startSigPoll();
    }

    async _createReceiverPeerConnection () {
        this.pc = new RTCPeerConnection(this.iceConfig);

        this.pc.ontrack = e => {
            const audio = document.getElementById('remote-audio');
            if (audio.srcObject !== e.streams[0]) {
                audio.srcObject = e.streams[0];
            }
            // Try to play; browsers may require a prior user gesture
            audio.play().catch(err => {
                console.warn('[autoplay]', err.message);
                // Show autoplay banner as fallback
                const banner = document.getElementById('autoplay-banner');
                if (banner) banner.classList.remove('d-none');
            });
        };

        this.pc.onicecandidate = e => {
            if (e.candidate) {
                api('signaling/send', {
                    transmission_id: this.transmissionId,
                    message_type:    'ice_candidate',
                    payload:         JSON.stringify(e.candidate),
                });
            }
        };

        this.pc.oniceconnectionstatechange = () => {
            if (['failed', 'disconnected', 'closed'].includes(this.pc?.iceConnectionState)) {
                this._handleRemoteHangUp();
            }
        };
    }

    // ================================================================
    // PRIVATE — recording
    // ================================================================

    _startRecording () {
        this.audioChunks = [];
        // Prefer OGG Opus when available; some browsers produce more reliable
        // duration/playback metadata for saved voice notes than WebM.
        const mimeType   = MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')
                           ? 'audio/ogg;codecs=opus'
                           : (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                              ? 'audio/webm;codecs=opus'
                              : '');
        const opts = mimeType ? { mimeType } : {};
        this.mediaRecorder = new MediaRecorder(this.localStream, opts);
        this.mediaRecorder.ondataavailable = e => {
            if (e.data && e.data.size > 0) this.audioChunks.push(e.data);
        };
        // Duration should reflect actual recording time, not setup time.
        this.startTime = Date.now();
        // Record as a single final blob to avoid segmented WebM playback truncation.
        this.mediaRecorder.start();
    }

    async _stopRecordingAndUpload () {
        if (!this.mediaRecorder || this.mediaRecorder.state === 'inactive') {
            return;
        }
        return new Promise(resolve => {
            this.mediaRecorder.onstop = async () => {
                const startedAt = this.startTime || Date.now();
                const duration  = Math.max(1, Math.round((Date.now() - startedAt) / 1000));
                // Snapshot chunks in case state mutates before upload starts.
                const chunks   = this.audioChunks.slice();
                const blob     = new Blob(chunks, { type: this.mediaRecorder.mimeType || 'audio/webm' });
                const ext      = (this.mediaRecorder.mimeType || '').includes('ogg') ? 'ogg' : 'webm';

                const form = new FormData();
                form.append('audio',          blob, `voice.${ext}`);
                form.append('transmission_id', this.transmissionId);
                form.append('duration',        duration);

                try {
                    const r = await fetch(BASE_URL + 'api/transmission/upload', {
                        method: 'POST',
                        body:   form,
                    });
                    await r.json();
                } catch (e) {
                    console.error('[upload]', e);
                }
                resolve();
            };
            // Flush buffered data right before stopping.
            if (typeof this.mediaRecorder.requestData === 'function') {
                try { this.mediaRecorder.requestData(); } catch {}
            }
            this.mediaRecorder.stop();
        });
    }

    // ================================================================
    // PRIVATE — signaling poll
    // ================================================================

    _startSigPoll () {
        if (this._sigPoll) return;
        this._sigPoll = setInterval(() => this._pollSignaling(), 1000);
    }

    _stopSigPoll () {
        if (this._sigPoll) { clearInterval(this._sigPoll); this._sigPoll = null; }
    }

    async _pollSignaling () {
        if (!this.transmissionId) return;
        try {
            const res = await api('signaling/poll', { transmission_id: this.transmissionId });
            for (const msg of (res.messages || [])) {
                await this._handleSignalingMsg(msg);
            }
        } catch (e) {
            console.warn('[sig poll]', e);
        }
    }

    async _handleSignalingMsg (msg) {
        let payload;
        try { payload = JSON.parse(msg.payload); } catch { return; }

        switch (msg.message_type) {

            // ----- SENDER receives ANSWER from receiver -----
            case 'answer':
                if (this.pc && !this._remoteDescSet) {
                    await this.pc.setRemoteDescription(new RTCSessionDescription(payload));
                    this._remoteDescSet = true;
                    await this._flushIcePending();
                }
                break;

            // ----- RECEIVER receives OFFER from sender -----
            case 'offer':
                if (this.pc && !this._remoteDescSet) {
                    await this.pc.setRemoteDescription(new RTCSessionDescription(payload));
                    this._remoteDescSet = true;
                    const answer = await this.pc.createAnswer();
                    await this.pc.setLocalDescription(answer);
                    await api('signaling/send', {
                        transmission_id: this.transmissionId,
                        message_type:    'answer',
                        payload:         JSON.stringify(answer),
                    });
                    await this._flushIcePending();
                }
                break;

            // ----- Both sides receive ICE candidates -----
            case 'ice_candidate':
                if (!this._remoteDescSet || !this.pc?.remoteDescription) {
                    this._icePending.push(payload);
                } else {
                    await this._addIce(payload);
                }
                break;

            // ----- RECEIVER gets hang_up from sender -----
            case 'hang_up':
                await this._handleRemoteHangUp();
                break;
        }
    }

    async _addIce (candidate) {
        if (this.pc) {
            try { await this.pc.addIceCandidate(new RTCIceCandidate(candidate)); }
            catch (e) { console.warn('[ICE add]', e.message); }
        }
    }

    async _flushIcePending () {
        if (!this.pc?.remoteDescription) return;
        while (this._icePending.length > 0) {
            await this._addIce(this._icePending.shift());
        }
    }

    // ================================================================
    // PRIVATE — cleanup
    // ================================================================

    async _handleRemoteHangUp () {
        this._stopSigPoll();
        this.isReceiving = false;

        const audio = document.getElementById('remote-audio');
        if (audio) { audio.srcObject = null; }

        this._destroyPC();

        if (typeof this._onStopped === 'function') {
            this._onStopped();
            this._onStopped = null;
        }
    }

    _destroyPC () {
        if (this.pc) {
            try { this.pc.close(); } catch {}
            this.pc = null;
        }
        if (this.localStream) {
            this.localStream.getTracks().forEach(t => t.stop());
            this.localStream = null;
        }
        this._remoteDescSet = false;
        this._icePending    = [];
    }

    async _abortTransmission () {
        this.isTransmitting = false;
        if (this.transmissionId) {
            await api('transmission/stop', { transmission_id: this.transmissionId }).catch(() => {});
        }
        this._destroyPC();
    }
}

// ================================================================
// Shared API helper — all endpoints expect/return JSON
// ================================================================
async function api (path, body = {}) {
    const res = await fetch(BASE_URL + 'api/' + path, {
        method:  'POST',
        headers: {
            'Content-Type':     'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
    if (res.status === 401) {
        window.location.href = BASE_URL + 'auth';
        return {};
    }
    return res.json();
}

async function apiGet (path) {
    const res = await fetch(BASE_URL + 'api/' + path, {
        method:  'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (res.status === 401) {
        window.location.href = BASE_URL + 'auth';
        return {};
    }
    return res.json();
}
