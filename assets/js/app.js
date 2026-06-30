/**
 * app.js — Dashboard UI orchestrator.
 *
 * Responsibilities:
 *  - Load & render conversations / voice cards
 *  - Handle Transmit / Stop button
 *  - WebSocket connection management and event dispatch
 *  - Fallback HTTP polling when WebSocket is unavailable
 *  - Presence updates (pushed via WS; pulled via HTTP fallback)
 *  - Incoming transmission notifications (pushed via WS; polled as fallback)
 *  - Heartbeat for presence (via WS; HTTP as fallback)
 *  - Network loss detection and reconnecting overlay
 *  - Build voice card HTML
 */
'use strict';

// ── Singleton WebRTC manager (from webrtc.js)
const vt = new VoiceTransmission();

// ── State
let activeConvId        = null;   // currently selected conversation id
let activeReceiverId    = null;   // the other user in the selected conversation
let currentIncomingTxId = null;   // transmission id we are currently receiving
let cardRefreshInterval = null;   // used only in HTTP fallback mode
let txPopupTimer        = null;
let txPopupStartedAt    = null;
let heartbeatInterval   = null;   // HTTP fallback heartbeat interval
let ws                  = null;   // WsClient instance
let wsEverConnected     = false;  // true after first successful WS auth

// ── Network / reconnect overlay state
let isOfflineOverlayVisible = false;
let reconnectStartTime      = null;
let reconnectTimerInterval  = null;

// ── DOM refs
const btnTransmit   = document.getElementById('btn-transmit');
const txStatusLabel = document.getElementById('tx-status-label');
const liveIndicator = document.getElementById('live-indicator');
const convPanel     = document.getElementById('conv-panel');
const emptyState    = document.getElementById('empty-state');
const convList      = document.getElementById('conv-list');
const convPeerName  = document.getElementById('conv-peer-name');
const convPeerStatus= document.getElementById('conv-peer-status');
const voiceCardsEl  = document.getElementById('voice-cards');
const txPopup       = document.getElementById('tx-popup');
const txPopupStatus = document.getElementById('tx-popup-status');
const txPopupTimerEl= document.getElementById('tx-popup-timer');
const btnPopupStop  = document.getElementById('btn-popup-stop');
const txPopupModeEl = document.getElementById('tx-popup-mode');
const appShell      = document.getElementById('app-shell');
const btnMobileBack = document.getElementById('btn-mobile-back');
const sidebarHint   = document.getElementById('sidebar-hint');
const resumeConvBar = document.getElementById('resume-conv-bar');
const btnResumeConv = document.getElementById('btn-resume-conv');
const resumeConvName= document.getElementById('resume-conv-name');

function wireMobileNav () {
    if (!appShell) return;

    if (btnMobileBack) {
        btnMobileBack.addEventListener('click', () => {
            appShell.classList.remove('conv-open');
            updateMobileChrome();
        });
    }

    if (btnResumeConv) {
        btnResumeConv.addEventListener('click', () => {
            openConvPanel();
            updateMobileChrome();
        });
    }
}

function openConvPanel () {
    if (appShell) appShell.classList.add('conv-open');
    updateMobileChrome();
}

function updateMobileChrome () {
    const isMobile  = window.matchMedia('(max-width: 767.98px)').matches;
    const hasConvs  = convList.querySelectorAll('.conv-item').length > 0;
    const isConvOpen = appShell && appShell.classList.contains('conv-open');

    if (sidebarHint) {
        sidebarHint.classList.toggle('d-none', !isMobile || !hasConvs || isConvOpen);
    }
    if (resumeConvBar) {
        resumeConvBar.classList.toggle('d-none', !isMobile || !activeConvId || isConvOpen);
    }
    if (resumeConvName && convPeerName) {
        const name = convPeerName.textContent;
        if (name && name !== '—') resumeConvName.textContent = name;
    }
}

// ================================================================
// Boot
// ================================================================
document.addEventListener('DOMContentLoaded', () => {
    unlockAutoplay();
    loadConversations();
    wireNewConvModal();
    wireVoiceCardAudioBehavior();
    wireMobileNav();
    initWebSocket();           // replaces startHeartbeat + startIncomingPoll + startPresenceRefresh
    window.addEventListener('resize', updateMobileChrome);
});

// ================================================================
// WebSocket — primary real-time channel
// ================================================================

/**
 * Initialise the WebSocket client and register all event handlers.
 * Falls back to HTTP polling when the WS server is unavailable.
 */
function initWebSocket () {
    ws = new WsClient({
        url:    WS_URL,
        userId: CURRENT_USER_ID,
        token:  SESSION_TOKEN,
    });

    // ── Connection events ──────────────────────────────────────────

    ws.on('auth_ok', () => {
        wsEverConnected = true;
        stopFallbackPolling();
        if (isOfflineOverlayVisible) {
            handleReconnected();
        }
    });

    ws.on('disconnected', ({ wasAuthed }) => {
        // Only show the overlay if we were fully connected before — not on first connect attempt
        if (wsEverConnected && wasAuthed) {
            handleNetworkLoss();
        }
        // Always start fallback polling so the app stays functional during WS outage
        startFallbackPolling();
    });

    ws.on('kicked', () => {
        // Session was invalidated (new login on another device)
        window.location.href = BASE_URL + 'auth';
    });

    // ── Presence ───────────────────────────────────────────────────

    /**
     * Incremental presence update — no full conversation list reload required.
     * Updates the sidebar item and header status for the affected user.
     */
    ws.on('presence_update', ({ user_id, is_online }) => {
        const userId   = parseInt(user_id, 10);
        const isOnline = parseInt(is_online, 10) === 1;

        // Update sidebar items
        convList.querySelectorAll('.conv-item').forEach(el => {
            if (parseInt(el.dataset.otherId, 10) !== userId) return;
            const dot = el.querySelector('.status-dot');
            const sub = el.querySelector('.sub');
            if (dot) {
                dot.className = (isOnline ? 'online-dot' : 'offline-dot') + ' status-dot';
            }
            if (sub) {
                sub.textContent = isOnline ? 'Online' : 'Offline';
            }
        });

        // Update conversation header if this is the active peer
        if (activeReceiverId === userId) {
            convPeerStatus.textContent = isOnline ? 'Online' : 'Offline';
        }
    });

    /**
     * Initial snapshot of who is online — sent by the server right after auth_ok.
     * Applies the online status to already-rendered sidebar items.
     */
    ws.on('presence_snapshot', ({ online_user_ids }) => {
        const onlineSet = new Set((online_user_ids || []).map(Number));
        convList.querySelectorAll('.conv-item').forEach(el => {
            const userId = parseInt(el.dataset.otherId, 10);
            const isOnline = onlineSet.has(userId);
            const dot = el.querySelector('.status-dot');
            const sub = el.querySelector('.sub');
            if (dot) dot.className = (isOnline ? 'online-dot' : 'offline-dot') + ' status-dot';
            if (sub) sub.textContent = isOnline ? 'Online' : 'Offline';
        });
    });

    // ── Incoming transmission ──────────────────────────────────────

    ws.on('transmission_incoming', async (data) => {
        if (!data) return;
        const txId = parseInt(data.id, 10);
        if (txId === currentIncomingTxId || vt.isBusy()) return;

        currentIncomingTxId = txId;

        vt._onStopped = () => {
            currentIncomingTxId = null;
            liveIndicator.classList.add('d-none');
            updateTransmitButton();
            if (activeConvId) loadVoiceCards(activeConvId);
        };

        const accepted = await vt.acceptIncoming(data);
        if (accepted) {
            liveIndicator.classList.remove('d-none');
            updateTransmitButton();

            const convId     = parseInt(data.conversation_id, 10);
            const senderId   = parseInt(data.sender_id, 10);
            const senderName = `${data.sender_first} ${data.sender_last}`;
            if (activeConvId !== convId) {
                selectConversation(convId, senderId, senderName);
            }
        }
    });

    ws.on('transmission_pending', () => {
        // A non-live transmission was just created for us; refresh cards to show the pending card
        if (activeConvId) loadVoiceCards(activeConvId);
    });

    // ── Voice card notification ────────────────────────────────────

    ws.on('voice_card_ready', ({ conversation_id }) => {
        const convId = parseInt(conversation_id, 10);
        if (activeConvId === convId) {
            loadVoiceCards(convId);
        }
    });

    // ── Heartbeat response ─────────────────────────────────────────

    ws.on('pong', () => {
        // WS heartbeat successful — nothing needed, ws-client handles everything
    });

    ws.connect();
}

// ── Fallback HTTP polling ─────────────────────────────────────────────────────

/**
 * Start HTTP polling — activated when the WS connection drops.
 * Maintains functionality while WS reconnects.
 */
function startFallbackPolling () {
    // Heartbeat (every 3s — less aggressive than the old 1s, WS is primary)
    if (!heartbeatInterval) {
        sendHeartbeat();
        heartbeatInterval = setInterval(sendHeartbeat, 3000);
        document.addEventListener('visibilitychange', _onVisibilityChange);
        window.addEventListener('pagehide', _onPageHide);
    }

    // Card refresh for open conversation (every 5s — WS events are primary)
    if (!cardRefreshInterval && activeConvId) {
        cardRefreshInterval = setInterval(() => {
            loadVoiceCards(activeConvId);
            loadConversations();           // presence fallback
            refreshUserPickerIfOpen();
        }, 5000);
    }
}

/**
 * Stop HTTP polling — called when WS reconnects successfully.
 */
function stopFallbackPolling () {
    if (heartbeatInterval) {
        clearInterval(heartbeatInterval);
        heartbeatInterval = null;
        document.removeEventListener('visibilitychange', _onVisibilityChange);
    }
    if (cardRefreshInterval) {
        clearInterval(cardRefreshInterval);
        cardRefreshInterval = null;
    }
}

function _onVisibilityChange () {
    if (!document.hidden) {
        clearInterval(heartbeatInterval);
        sendHeartbeat();
        heartbeatInterval = setInterval(sendHeartbeat, 3000);
    }
}

function _onPageHide (e) {
    if (!e.persisted) sendOfflineBeacon();
}

// ── Heartbeat (HTTP fallback only) ────────────────────────────────────────────

function sendHeartbeat () {
    // When WS is connected the WS client sends a heartbeat every 5s.
    // This HTTP version only runs as a fallback while WS is unavailable.
    if (ws && ws.isConnected) return;
    api('heartbeat', {}).catch(() => {});
}

function sendOfflineBeacon () {
    const blob = new Blob(['{}'], { type: 'application/json' });
    navigator.sendBeacon(BASE_URL + 'api/offline', blob);
}

// ── Network loss / reconnecting overlay ───────────────────────────────────────

/**
 * Called when the WS connection drops after being established.
 * Shows the blocking reconnecting overlay and aborts any active transmission.
 */
async function handleNetworkLoss () {
    if (isOfflineOverlayVisible) return;
    showReconnectingOverlay();

    // Abort any active WebRTC transmission immediately
    if (vt.isBusy()) {
        await vt.forceStop();
        liveIndicator.classList.add('d-none');
        removeLiveCard();
        hideTxPopup();
        updateTransmitButton();
    }
}

/**
 * Called when the WS reconnects and auth_ok is received.
 */
function handleReconnected () {
    hideReconnectingOverlay();
    // Refresh state that may have changed during the outage
    loadConversations();
    if (activeConvId) loadVoiceCards(activeConvId);
}

function showReconnectingOverlay () {
    isOfflineOverlayVisible = true;
    reconnectStartTime      = Date.now();
    const overlay = document.getElementById('reconnect-overlay');
    if (overlay) overlay.classList.remove('d-none');

    if (reconnectTimerInterval) clearInterval(reconnectTimerInterval);
    reconnectTimerInterval = setInterval(() => {
        const el = document.getElementById('reconnect-timer');
        if (el && reconnectStartTime) {
            const secs = Math.floor((Date.now() - reconnectStartTime) / 1000);
            const m    = Math.floor(secs / 60);
            const s    = secs % 60;
            el.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        }
    }, 1000);
}

function hideReconnectingOverlay () {
    isOfflineOverlayVisible = false;
    reconnectStartTime      = null;
    if (reconnectTimerInterval) {
        clearInterval(reconnectTimerInterval);
        reconnectTimerInterval = null;
    }
    const overlay = document.getElementById('reconnect-overlay');
    if (overlay) overlay.classList.add('d-none');
}

// ================================================================
// Autoplay unlock
// ================================================================
function unlockAutoplay () {
    const banner    = document.getElementById('autoplay-banner');
    const audio     = document.getElementById('remote-audio');
    if (!audio) return;

    // The login form submission is a user gesture on the same origin.
    // Browsers carry that permission forward through same-origin navigation,
    // so audio.play() succeeds immediately — no click required.
    // We attempt the silent unlock straight away and only surface the banner
    // as a fallback for browsers that still block it.
    const silentSrc = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';
    audio.src = silentSrc;
    audio.play()
        .then(() => {
            audio.pause();
            audio.src = '';
            // Unlocked silently — banner stays hidden.
        })
        .catch(() => {
            // Browser blocked the attempt (strict policy or no prior gesture).
            // Fall back to the click-to-unlock banner.
            if (banner) banner.classList.remove('d-none');

            const unlock = () => {
                audio.src = silentSrc;
                audio.play()
                    .then(() => { audio.pause(); audio.src = ''; })
                    .catch(() => {});
                if (banner) banner.classList.add('d-none');
            };
            document.addEventListener('click', unlock, { once: true });
            if (banner) banner.addEventListener('click', unlock, { once: true });
        });
}

// ================================================================
// Conversations
// ================================================================
async function loadConversations () {
    const data = await apiGet('conversations');
    renderConvList(data.conversations || []);
    updatePeerStatus([]);
}

function isUserOnline (value) {
    return Number(value) === 1;
}

function renderConvList (convs) {
    if (!convs.length) {
        convList.innerHTML = `
            <div class="conv-placeholder">
                <i class="bi bi-chat-dots icon-muted"></i>
                <div class="mt-2 text-faint">No conversations yet</div>
            </div>`;
        updateMobileChrome();
        return;
    }
    convList.innerHTML = convs.map(c => `
        <div class="conv-item ${c.id == activeConvId ? 'active' : ''}"
             data-conv-id="${c.id}"
             data-other-id="${c.other_user_id}"
             data-other-name="${esc(c.first_name + ' ' + c.last_name)}">
            <div class="avatar">${initials(c.first_name, c.last_name)}</div>
            <div class="info">
                <div class="name">${esc(c.first_name)} ${esc(c.last_name)}</div>
                <div class="sub">${isUserOnline(c.is_online) ? 'Online' : 'Offline'}</div>
            </div>
            <span class="${isUserOnline(c.is_online) ? 'online-dot' : 'offline-dot'} status-dot"></span>
            <i class="bi bi-chevron-right conv-chevron" aria-hidden="true"></i>
        </div>
    `).join('');

    convList.querySelectorAll('.conv-item').forEach(el => {
        el.addEventListener('click', () => selectConversation(
            parseInt(el.dataset.convId),
            parseInt(el.dataset.otherId),
            el.dataset.otherName
        ));
    });
    updateMobileChrome();
}

function selectConversation (convId, otherId, otherName) {
    activeConvId     = convId;
    activeReceiverId = otherId;

    convPanel.classList.remove('d-none');
    emptyState.classList.add('d-none');
    openConvPanel();

    convPeerName.textContent   = otherName;
    convPeerStatus.textContent = 'Loading…';

    // Highlight sidebar item
    convList.querySelectorAll('.conv-item').forEach(el => {
        el.classList.toggle('active', parseInt(el.dataset.convId) === convId);
    });

    // Enable transmit if not busy
    updateTransmitButton();

    // Load voice cards immediately
    loadVoiceCards(convId);

    // Determine peer status from sidebar (WS presence keeps this live)
    convList.querySelectorAll('.conv-item').forEach(el => {
        if (parseInt(el.dataset.convId) === convId) {
            const sub = el.querySelector('.sub');
            convPeerStatus.textContent = sub ? sub.textContent : '';
        }
    });

    updateMobileChrome();
}

// ================================================================
// Voice cards
// ================================================================
async function loadVoiceCards (convId) {
    if (!convId) return;
    // Avoid re-render interruptions while a card is being listened to.
    if (isAnyCardAudioPlaying()) return;
    const data = await apiGet('voice/' + convId);
    renderVoiceCards(data.messages || []);
    updatePeerStatus(data.messages);
}

function renderVoiceCards (messages) {
    const activeAudio = Array.from(voiceCardsEl.querySelectorAll('audio.card-audio'))
        .find(audio => !audio.paused && !audio.ended && audio.currentSrc);
    const playingState = activeAudio && !activeAudio.paused && activeAudio.currentSrc
        ? { src: activeAudio.currentSrc, time: activeAudio.currentTime }
        : null;
    const wasNearBottom = isNearBottom();

    if (!messages.length) {
        voiceCardsEl.innerHTML = `
            <div class="text-center mt-4 text-faint">
                No voice messages yet
            </div>`;
        if (vt.isTransmitting) addLiveCard();
        return;
    }

    const html = messages.map(m => buildCard(m)).join('');
    const prev = voiceCardsEl.innerHTML;
    // Only re-render if changed to avoid interrupting audio
    if (prev !== html) {
        voiceCardsEl.innerHTML = html;
    }

    // Keep the temporary transmitting card visible even while cards refresh.
    if (vt.isTransmitting) addLiveCard();

    restorePlayback(playingState);

    // Avoid forced jumps while user is reading older messages.
    if (wasNearBottom) {
        voiceCardsEl.scrollTop = voiceCardsEl.scrollHeight;
    }
}

function buildCard (m) {
    const isMine    = parseInt(m.sender_id) === CURRENT_USER_ID;
    const direction = isMine ? 'outgoing' : 'incoming';
    const senderName = isMine
        ? 'You'
        : `${esc(m.sender_first)} ${esc(m.sender_last)}`;
    const timeStr = formatTime(m.created_at);
    const dur     = m.duration ? formatDur(m.duration) : '';
    const durationSecs = Number.parseInt(m.duration || '0', 10);

    // ---- Pending card (receiver was busy; audio still recording) ----
    if (parseInt(m.is_pending) === 1) {
        return `
        <div class="voice-card ${direction} pending-card">
            <div class="card-bubble">
                <div class="card-sender">${senderName}</div>
                <div class="pending-audio-placeholder">
                    <div class="spinner-border spinner-border-sm text-warning spinner-tiny"></div>
                    <span>Recording in progress…</span>
                </div>
                <div class="card-meta">
                    <span class="pending-badge">
                        <i class="bi bi-hourglass-split"></i> Pending
                    </span>
                    <span>${timeStr}</span>
                </div>
            </div>
        </div>`;
    }

    // ---- Normal completed card ----
    const audioUrl = m.audio_file_path
        ? BASE_URL + 'voice-file/' + m.audio_file_path.replace('uploads/voice/', '')
        : null;

    const unplayedBadge = (parseInt(m.sender_id) !== CURRENT_USER_ID && m.playback_state === 'unplayed')
        ? `<span class="voice-new-badge">New</span>`
        : '';

    const audioEl = audioUrl
        ? `<div class="voice-player" data-audio-id="${m.id}">
               <button class="voice-play-btn" type="button" aria-label="Play voice message">
                   <i class="bi bi-play-fill"></i>
               </button>
               <div class="voice-track-wrap">
                   <input class="voice-progress" type="range" min="0" max="100" value="0" step="0.1">
                   <div class="voice-time-row">
                       <span class="voice-current">0:00</span>
                       <span class="voice-total">${formatClockTime(durationSecs)}</span>
                   </div>
               </div>
               <audio class="card-audio" src="${esc(audioUrl)}" preload="metadata"
                      data-message-id="${m.id}" data-audio-id="${m.id}"
                      data-expected-duration="${durationSecs > 0 ? durationSecs : ''}"></audio>
           </div>`
        : `<div class="text-muted-light small">
               <i class="bi bi-exclamation-circle me-1"></i>Audio unavailable
           </div>`;

    return `
    <div class="voice-card ${direction}">
        <div class="card-bubble">
            <div class="card-head">
                <div class="card-sender">${senderName}</div>
                ${unplayedBadge ? `<div class="card-badge-wrap">${unplayedBadge}</div>` : ''}
            </div>
            ${audioEl}
            <div class="card-meta">
                <i class="bi bi-mic"></i>
                ${dur ? `<span>${dur}</span>` : ''}
                <span>${timeStr}</span>
            </div>
        </div>
    </div>`;
}

async function markPlayed (messageId) {
    // Fire-and-forget
    await api('voice/mark_played', { id: messageId }).catch(() => {});
}

function wireVoiceCardAudioBehavior () {
    if (!voiceCardsEl) return;
    voiceCardsEl.addEventListener('click', e => {
        const playBtn = e.target.closest('.voice-play-btn');
        if (!playBtn) return;
        const player = playBtn.closest('.voice-player');
        if (!player) return;
        const audio = player.querySelector('audio.card-audio');
        if (!audio) return;
        if (audio.paused) {
            pauseOtherCardAudios(audio);
            audio.play().catch(() => {});
        } else {
            audio.pause();
        }
    });

    voiceCardsEl.addEventListener('input', e => {
        const progress = e.target.closest('.voice-progress');
        if (!progress) return;
        const player = progress.closest('.voice-player');
        if (!player) return;
        const audio = player.querySelector('audio.card-audio');
        if (!audio) return;
        const expectedDuration = Number.parseInt(audio.dataset.expectedDuration || '0', 10);
        const usableDuration = (Number.isFinite(audio.duration) && audio.duration > 0)
            ? audio.duration
            : (expectedDuration > 0 ? expectedDuration : 0);
        if (usableDuration <= 0) return;
        const pct = Number(progress.value) / 100;
        audio.currentTime = Math.max(0, Math.min(usableDuration, usableDuration * pct));
        updateVoicePlayerUI(audio);
    });

    // Use capture because native "play" does not bubble.
    voiceCardsEl.addEventListener('play', e => {
        const target = e.target;
        if (!(target instanceof HTMLAudioElement) || !target.classList.contains('card-audio')) {
            return;
        }
        pauseOtherCardAudios(target);
        updateVoicePlayerUI(target);
        const messageId = Number.parseInt(target.dataset.messageId || '', 10);
        if (messageId > 0) {
            markPlayed(messageId).catch(() => {});
        }
    }, true);

    ['pause', 'timeupdate', 'loadedmetadata', 'durationchange', 'ended'].forEach(evt => {
        voiceCardsEl.addEventListener(evt, e => {
            const target = e.target;
            if (!(target instanceof HTMLAudioElement) || !target.classList.contains('card-audio')) {
                return;
            }
            updateVoicePlayerUI(target);
        }, true);
    });
}

function pauseOtherCardAudios (currentAudio) {
    voiceCardsEl.querySelectorAll('audio.card-audio').forEach(audio => {
        if (audio !== currentAudio && !audio.paused) {
            audio.pause();
        }
    });
}

function updatePeerStatus (messages) {
    // Reflect online status from last conv list data
    const convItems = convList.querySelectorAll('.conv-item');
    convItems.forEach(el => {
        if (parseInt(el.dataset.convId) === activeConvId) {
            const sub = el.querySelector('.sub');
            convPeerStatus.textContent = sub ? sub.textContent : '';
        }
    });
}

// ================================================================
// Transmit / Stop button
// ================================================================
function updateTransmitButton () {
    if (!activeConvId) {
        btnTransmit.disabled = true;
        btnTransmit.innerHTML = '<i class="bi bi-mic-fill me-1"></i> Transmit';
        btnTransmit.classList.remove('stop-mode');
        return;
    }
    if (vt.isReceiving) {
        btnTransmit.disabled = true;
        setStatus('Receiving — cannot transmit now');
        return;
    }
    if (vt.isTransmitting) {
        btnTransmit.disabled = false;
        btnTransmit.innerHTML = '<i class="bi bi-stop-fill me-1"></i> Stop';
        btnTransmit.classList.add('stop-mode');
    } else {
        btnTransmit.disabled = false;
        btnTransmit.innerHTML = '<i class="bi bi-mic-fill me-1"></i> Transmit';
        btnTransmit.classList.remove('stop-mode');
    }
}

btnTransmit.addEventListener('click', async () => {
    if (vt.isTransmitting) {
        await handleStop();
    } else {
        await handleTransmit();
    }
});

async function handleTransmit () {
    if (!activeConvId || !activeReceiverId) return;

    setStatus('Requesting microphone…');
    btnTransmit.disabled = true;

    const result = await vt.startTransmission(activeConvId, activeReceiverId);

    if (!result.success) {
        setStatus('Error: ' + result.message);
        btnTransmit.disabled = false;
        hideTxPopup();
        return;
    }

    if (result.isLive) {
        setStatus('Transmitting live…');
        liveIndicator.classList.remove('d-none');
        addLiveCard();
        showTxPopup('live');
    } else {
        setStatus('Receiver is busy — recording for later delivery…');
        showTxPopup('pending');
    }

    updateTransmitButton();
}

async function handleStop () {
    setStatus('Stopping live stream…');
    btnTransmit.disabled = true;
    liveIndicator.classList.add('d-none');

    setStatus('Uploading voice note…');
    await vt.stopTransmission();

    setStatus('Saved as voice card.');
    updateTransmitButton();
    removeLiveCard();
    hideTxPopup();

    // Refresh cards after a short delay for upload to finish
    // WS voice_card_ready event will also trigger this, but a local timeout
    // ensures the sender's view updates even if WS push lags.
    setTimeout(() => loadVoiceCards(activeConvId), 1500);
}

function setStatus (msg) {
    txStatusLabel.textContent = msg;
    if (txPopup && txPopupStatus && !txPopup.classList.contains('d-none')) {
        txPopupStatus.textContent = msg;
    }
}

// ---- Temporary "live card" shown while transmitting ----
function addLiveCard () {
    const existing = document.getElementById('live-tx-card');
    if (existing) return;

    const div = document.createElement('div');
    div.id        = 'live-tx-card';
    div.className = 'voice-card outgoing live-card';
    div.innerHTML = `
        <div class="card-bubble">
            <div class="card-sender">You <span class="live-indicator compact d-inline-flex ms-1">
                <span class="live-dot"></span> LIVE
            </span></div>
            <div class="waveform-bars mt-1">
                <span></span><span></span><span></span><span></span><span></span>
            </div>
            <div class="card-meta mt-1">
                <i class="bi bi-broadcast"></i>
                <span>Transmitting…</span>
            </div>
        </div>`;
    voiceCardsEl.appendChild(div);
    voiceCardsEl.scrollTop = voiceCardsEl.scrollHeight;
}

function removeLiveCard () {
    const el = document.getElementById('live-tx-card');
    if (el) el.remove();
}

function showTxPopup (mode) {
    if (!txPopup) return;
    txPopup.classList.remove('d-none');
    txPopup.classList.toggle('pending-mode', mode === 'pending');
    txPopup.classList.toggle('live-mode', mode !== 'pending');
    if (txPopupModeEl) {
        txPopupModeEl.textContent = mode === 'pending' ? 'Recording' : 'Live';
    }
    txPopupStartedAt = Date.now();
    txPopupTimerEl.textContent = '00:00';
    txPopupStatus.textContent = mode === 'pending'
        ? 'Receiver is busy. Capturing voice note safely.'
        : 'Streaming your microphone in real time.';
    if (txPopupTimer) clearInterval(txPopupTimer);
    txPopupTimer = setInterval(() => {
        if (!txPopupStartedAt || !txPopupTimerEl) return;
        const seconds = Math.max(0, Math.floor((Date.now() - txPopupStartedAt) / 1000));
        txPopupTimerEl.textContent = formatDurationClock(seconds);
    }, 1000);
}

function hideTxPopup () {
    if (!txPopup) return;
    txPopup.classList.add('d-none');
    txPopup.classList.remove('pending-mode', 'live-mode');
    if (txPopupTimer) {
        clearInterval(txPopupTimer);
        txPopupTimer = null;
    }
    txPopupStartedAt = null;
}

// ================================================================
// New conversation modal
// ================================================================
function wireNewConvModal () {
    const btnNew   = document.getElementById('btn-new-conv');
    const modal    = new bootstrap.Modal(document.getElementById('modal-new-conv'));
    const picker   = document.getElementById('user-picker');

    btnNew.addEventListener('click', async () => {
        picker.innerHTML = `<div class="text-center py-3 text-faint">
            <div class="spinner-border spinner-border-sm me-2"></div> Loading users…
        </div>`;
        modal.show();
        await renderUserPicker(picker);
    });
}

async function renderUserPicker (pickerEl) {
    const picker = pickerEl || document.getElementById('user-picker');
    if (!picker) return;

    const data  = await apiGet('users');
    const users = data.users || [];

    if (!users.length) {
        picker.innerHTML = `<div class="text-center py-3 text-faint">
            No other users have joined yet.
        </div>`;
        return;
    }

    picker.innerHTML = users.map(u => `
        <div class="user-pick-item"
             data-user-id="${u.id}"
             data-name="${esc(u.first_name + ' ' + u.last_name)}">
            <div class="user-pick-avatar">${initials(u.first_name, u.last_name)}</div>
            <div>
                <div class="user-pick-name">${esc(u.first_name)} ${esc(u.last_name)}</div>
                <small class="text-muted-light">
                    ${isUserOnline(u.is_online)
                        ? '<span class="online-dot me-1"></span>Online'
                        : '<span class="offline-dot me-1"></span>Offline'}
                </small>
            </div>
        </div>
    `).join('');

    picker.querySelectorAll('.user-pick-item').forEach(el => {
        el.addEventListener('click', async () => {
            const otherId   = parseInt(el.dataset.userId);
            const otherName = el.dataset.name;
            bootstrap.Modal.getInstance(document.getElementById('modal-new-conv')).hide();
            const res = await api('conversations/create', { other_user_id: otherId });
            if (res.success) {
                await loadConversations();
                selectConversation(res.conversation_id, otherId, otherName);
            }
        });
    });
}

async function refreshUserPickerIfOpen () {
    const modal = document.getElementById('modal-new-conv');
    if (!modal || !modal.classList.contains('show')) return;
    await renderUserPicker();
}

if (btnPopupStop) {
    btnPopupStop.addEventListener('click', async () => {
        if (vt.isTransmitting) {
            await handleStop();
        }
    });
}

// ================================================================
// Utilities
// ================================================================
function esc (str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function initials (first, last) {
    return ((first[0] || '') + (last[0] || '')).toUpperCase();
}

function formatTime (iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T') + 'Z');
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatDur (secs) {
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function formatDurationClock (secs) {
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

function isNearBottom () {
    const distance = voiceCardsEl.scrollHeight - voiceCardsEl.scrollTop - voiceCardsEl.clientHeight;
    return distance < 80;
}

function restorePlayback (state) {
    if (!state?.src) return;
    const match = Array.from(voiceCardsEl.querySelectorAll('audio.card-audio'))
        .find(a => a.currentSrc === state.src || a.src === state.src);
    if (!match) return;
    try {
        match.currentTime = state.time || 0;
        match.play().catch(() => {});
        updateVoicePlayerUI(match);
    } catch (_) {}
}

function isAnyCardAudioPlaying () {
    return Array.from(voiceCardsEl.querySelectorAll('audio.card-audio'))
        .some(audio => !audio.paused && !audio.ended);
}

function updateVoicePlayerUI (audio) {
    const player = audio.closest('.voice-player');
    if (!player) return;
    const playBtnIcon = player.querySelector('.voice-play-btn i');
    const progress = player.querySelector('.voice-progress');
    const currentEl = player.querySelector('.voice-current');
    const totalEl = player.querySelector('.voice-total');
    const expectedDuration = Number.parseInt(audio.dataset.expectedDuration || '0', 10);
    const usableDuration = (Number.isFinite(audio.duration) && audio.duration > 0)
        ? audio.duration
        : (expectedDuration > 0 ? expectedDuration : 0);

    if (playBtnIcon) {
        playBtnIcon.className = audio.paused ? 'bi bi-play-fill' : 'bi bi-pause-fill';
    }
    if (progress && usableDuration > 0) {
        const pct = (audio.currentTime / usableDuration) * 100;
        progress.value = String(Math.max(0, Math.min(100, pct)));
    } else if (progress) {
        progress.value = '0';
    }
    if (currentEl) {
        currentEl.textContent = formatClockTime(audio.currentTime || 0);
    }
    if (totalEl) {
        totalEl.textContent = formatClockTime(usableDuration);
    }
}

function formatClockTime (seconds) {
    const safe = Math.max(0, Math.floor(seconds));
    const m = Math.floor(safe / 60);
    const s = safe % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}
