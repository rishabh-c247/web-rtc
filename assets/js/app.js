/**
 * app.js — Dashboard UI orchestrator.
 *
 * Responsibilities:
 *  - Load & render conversations / voice cards
 *  - Handle Transmit / Stop button
 *  - Poll for incoming live transmissions (receiver side)
 *  - Heartbeat for presence
 *  - Build voice card HTML
 */
'use strict';

// ── Singleton WebRTC manager (from webrtc.js)
const vt = new VoiceTransmission();

// ── State
let activeConvId     = null;   // currently selected conversation id
let activeReceiverId = null;   // the other user in the selected conversation
let currentIncomingTxId = null; // transmission id we are currently receiving
let cardRefreshInterval  = null;
let incomingPollInterval = null;

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

// ================================================================
// Boot
// ================================================================
document.addEventListener('DOMContentLoaded', () => {
    unlockAutoplay();
    loadConversations();
    startIncomingPoll();
    startHeartbeat();
    wireNewConvModal();
});

// ================================================================
// Autoplay unlock
// ================================================================
function unlockAutoplay () {
    const banner = document.getElementById('autoplay-banner');
    if (!banner) return;

    // Show the banner so the user knows they should click
    banner.classList.remove('d-none');

    const unlock = () => {
        const audio = document.getElementById('remote-audio');
        if (audio) {
            // Play + immediately pause a silent snippet to unlock autoplay
            audio.src = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';
            audio.play().then(() => { audio.pause(); audio.src = ''; }).catch(() => {});
        }
        banner.classList.add('d-none');
        document.removeEventListener('click', unlock);
    };
    document.addEventListener('click', unlock, { once: true });
    banner.addEventListener('click', unlock, { once: true });
}

// ================================================================
// Conversations
// ================================================================
async function loadConversations () {
    const data = await apiGet('conversations');
    renderConvList(data.conversations || []);
}

function renderConvList (convs) {
    if (!convs.length) {
        convList.innerHTML = `
            <div class="conv-placeholder">
                <i class="bi bi-chat-dots" style="font-size:1.8rem;opacity:0.3;"></i>
                <div class="mt-1" style="font-size:0.8rem;opacity:0.4;">No conversations yet</div>
            </div>`;
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
                <div class="sub">${c.is_online ? 'Online' : 'Offline'}</div>
            </div>
            <span class="${c.is_online ? 'online-dot' : 'offline-dot'} status-dot"></span>
        </div>
    `).join('');

    convList.querySelectorAll('.conv-item').forEach(el => {
        el.addEventListener('click', () => selectConversation(
            parseInt(el.dataset.convId),
            parseInt(el.dataset.otherId),
            el.dataset.otherName
        ));
    });
}

function selectConversation (convId, otherId, otherName) {
    activeConvId     = convId;
    activeReceiverId = otherId;

    convPanel.classList.remove('d-none');
    emptyState.classList.add('d-none');

    convPeerName.textContent   = otherName;
    convPeerStatus.textContent = 'Loading…';

    // Highlight sidebar item
    convList.querySelectorAll('.conv-item').forEach(el => {
        el.classList.toggle('active', parseInt(el.dataset.convId) === convId);
    });

    // Enable transmit if not busy
    updateTransmitButton();

    // Load voice cards
    loadVoiceCards(convId);

    // Refresh cards every 3 s while this conversation is open
    if (cardRefreshInterval) clearInterval(cardRefreshInterval);
    cardRefreshInterval = setInterval(() => {
        loadVoiceCards(convId);
        // Also refresh peer online status in sidebar
        loadConversations();
    }, 3000);
}

// ================================================================
// Voice cards
// ================================================================
async function loadVoiceCards (convId) {
    if (!convId) return;
    const data = await apiGet('voice/' + convId);
    renderVoiceCards(data.messages || []);
    updatePeerStatus(data.messages);
}

function renderVoiceCards (messages) {
    if (!messages.length) {
        voiceCardsEl.innerHTML = `
            <div class="text-center mt-4" style="opacity:0.3;font-size:0.85rem;">
                No voice messages yet
            </div>`;
        return;
    }

    const html = messages.map(m => buildCard(m)).join('');
    const prev = voiceCardsEl.innerHTML;
    // Only re-render if changed to avoid interrupting audio
    if (prev !== html) {
        voiceCardsEl.innerHTML = html;
    }

    // Scroll to bottom
    voiceCardsEl.scrollTop = voiceCardsEl.scrollHeight;
}

function buildCard (m) {
    const isMine    = parseInt(m.sender_id) === CURRENT_USER_ID;
    const direction = isMine ? 'outgoing' : 'incoming';
    const senderName = isMine
        ? 'You'
        : `${esc(m.sender_first)} ${esc(m.sender_last)}`;
    const timeStr = formatTime(m.created_at);
    const dur     = m.duration ? formatDur(m.duration) : '';

    // ---- Pending card (receiver was busy; audio still recording) ----
    if (parseInt(m.is_pending) === 1) {
        return `
        <div class="voice-card ${direction} pending-card">
            <div class="card-bubble">
                <div class="card-sender">${senderName}</div>
                <div class="pending-audio-placeholder">
                    <div class="spinner-border spinner-border-sm text-warning" style="width:14px;height:14px;"></div>
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
        ? `<span class="badge" style="background:var(--purple);font-size:0.65rem;">New</span>`
        : '';

    const audioEl = audioUrl
        ? `<audio class="card-audio" controls src="${esc(audioUrl)}"
               onplay="markPlayed(${m.id})"></audio>`
        : `<div class="text-muted-light" style="font-size:0.78rem;">
               <i class="bi bi-exclamation-circle me-1"></i>Audio unavailable
           </div>`;

    return `
    <div class="voice-card ${direction}">
        <div class="card-bubble">
            <div class="card-sender">${senderName} ${unplayedBadge}</div>
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
        return;
    }

    if (result.isLive) {
        setStatus('Transmitting live…');
        liveIndicator.classList.remove('d-none');
        addLiveCard();
    } else {
        setStatus('Receiver is busy — recording for later delivery…');
    }

    updateTransmitButton();
}

async function handleStop () {
    setStatus('Stopping…');
    btnTransmit.disabled = true;
    liveIndicator.classList.add('d-none');

    await vt.stopTransmission();

    setStatus('Saved as voice card.');
    updateTransmitButton();
    removeLiveCard();

    // Refresh cards after a short delay for upload to finish
    setTimeout(() => loadVoiceCards(activeConvId), 1500);
}

function setStatus (msg) {
    txStatusLabel.textContent = msg;
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
            <div class="card-sender">You <span class="live-indicator d-inline-flex ms-1" style="font-size:0.65rem;padding:0.1rem 0.45rem;">
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

// ================================================================
// Incoming transmission poll (RECEIVER side)
// ================================================================
function startIncomingPoll () {
    if (incomingPollInterval) return;
    incomingPollInterval = setInterval(pollIncoming, 1500);
}

async function pollIncoming () {
    try {
        const res = await api('transmission/poll', {});

        // ---- Live incoming ----
        const inc = res.incoming;
        if (inc && parseInt(inc.id) !== currentIncomingTxId && !vt.isBusy()) {
            currentIncomingTxId = parseInt(inc.id);

            vt._onStopped = () => {
                currentIncomingTxId = null;
                liveIndicator.classList.add('d-none');
                updateTransmitButton();
                if (activeConvId) loadVoiceCards(activeConvId);
            };

            const accepted = await vt.acceptIncoming(inc);
            if (accepted) {
                liveIndicator.classList.remove('d-none');
                updateTransmitButton();

                // Switch to the conversation where this came from
                const convId      = parseInt(inc.conversation_id);
                const senderId    = parseInt(inc.sender_id);
                const senderName  = `${inc.sender_first} ${inc.sender_last}`;
                if (activeConvId !== convId) {
                    selectConversation(convId, senderId, senderName);
                }
            }
        }

        // ---- Pending (non-live) incomings for card refresh ----
        if (Array.isArray(res.pending) && res.pending.length > 0 && activeConvId) {
            // Silently refresh cards so the disabled pending cards appear
            loadVoiceCards(activeConvId);
        }

    } catch (e) {
        // Network hiccup — ignore
    }
}

// ================================================================
// New conversation modal
// ================================================================
function wireNewConvModal () {
    const btnNew   = document.getElementById('btn-new-conv');
    const modal    = new bootstrap.Modal(document.getElementById('modal-new-conv'));
    const picker   = document.getElementById('user-picker');

    btnNew.addEventListener('click', async () => {
        picker.innerHTML = `<div class="text-center py-3" style="opacity:0.4;">
            <div class="spinner-border spinner-border-sm me-2"></div> Loading users…
        </div>`;
        modal.show();

        const data  = await apiGet('users');
        const users = data.users || [];

        if (!users.length) {
            picker.innerHTML = `<div class="text-center py-3" style="opacity:0.4;">
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
                    <div style="font-weight:600;">${esc(u.first_name)} ${esc(u.last_name)}</div>
                    <small style="color:var(--text-muted);">
                        ${u.is_online
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
                modal.hide();
                const res = await api('conversations/create', { other_user_id: otherId });
                if (res.success) {
                    await loadConversations();
                    selectConversation(res.conversation_id, otherId, otherName);
                }
            });
        });
    });
}

// ================================================================
// Heartbeat (keep is_online alive)
// ================================================================
function startHeartbeat () {
    api('heartbeat', {}).catch(() => {});
    setInterval(() => api('heartbeat', {}).catch(() => {}), 30000);
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
