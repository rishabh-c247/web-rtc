<!-- Hidden audio element for incoming live transmissions -->
<audio id="remote-audio" autoplay playsinline style="display:none;"></audio>

<!-- Autoplay unlock banner (shown once until user clicks) -->
<div id="autoplay-banner" class="autoplay-banner d-none">
    <i class="bi bi-volume-up me-2"></i>
    Click anywhere to enable audio autoplay for incoming transmissions.
</div>

<!-- Network reconnecting overlay — shown on WS disconnect, blocks all UI interaction -->
<div id="reconnect-overlay" class="reconnect-overlay d-none"
     aria-live="assertive" aria-atomic="true" role="alert">
    <div class="reconnect-card">
        <div class="spinner-border text-primary mb-3 spinner-reconnect" role="status">
            <span class="visually-hidden">Reconnecting…</span>
        </div>
        <div class="reconnect-title">Connection Lost</div>
        <div class="reconnect-sub">Reconnecting to the server…</div>
        <div class="reconnect-timer" id="reconnect-timer">00:00</div>
    </div>
</div>

<!-- ===================== APP SHELL ===================== -->
<div class="app-shell" id="app-shell">

    <!-- ====== LEFT SIDEBAR ====== -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-broadcast-pin text-purple" style="font-size:1.35rem;"></i>
                <span class="fw-bold fs-5">VoiceLink</span>
            </div>
            <div class="d-flex align-items-center gap-2 mt-2">
                <span class="online-dot"></span>
                <small class="text-muted-light"><?= htmlspecialchars($this->session->userdata('first_name') . ' ' . $this->session->userdata('last_name')) ?></small>
            </div>
        </div>

        <div class="sidebar-section-title">
            Conversations
            <button class="btn-icon-sm ms-auto" id="btn-new-conv" title="New conversation" aria-label="New conversation">
                <i class="bi bi-plus-lg"></i>
            </button>
        </div>

        <div id="conv-list" class="conv-list">
            <div class="conv-placeholder">
                <i class="bi bi-chat-dots icon-muted"></i>
                <div class="mt-2 text-faint">No conversations yet</div>
            </div>
        </div>

        <div id="sidebar-hint" class="sidebar-hint d-none">
            <i class="bi bi-hand-index-thumb"></i>
            Tap a conversation to review messages and transmit voice
        </div>

        <div id="resume-conv-bar" class="resume-conv-bar d-none">
            <button type="button" class="btn-resume-conv" id="btn-resume-conv">
                <i class="bi bi-mic-fill"></i>
                Open <span id="resume-conv-name">conversation</span>
            </button>
        </div>

        <div class="sidebar-footer">
            <a href="<?= site_url('auth/logout') ?>" class="btn-logout">
                <i class="bi bi-box-arrow-right me-1"></i> Logout
            </a>
        </div>
    </aside>

    <!-- ====== MAIN PANEL ====== -->
    <main class="main-panel">

        <!-- Empty state -->
        <div id="empty-state" class="empty-state">
            <i class="bi bi-broadcast icon-muted-lg"></i>
            <div class="mt-3 fw-semibold text-faint">Select a conversation to start</div>
            <div class="hint">or click <strong>+</strong> to create one</div>
        </div>

        <!-- Active conversation panel -->
        <div id="conv-panel" class="conv-panel d-none">

            <!-- Top bar -->
            <div class="conv-topbar">
                <button type="button" class="btn-mobile-back" id="btn-mobile-back" aria-label="Back to conversations">
                    <i class="bi bi-arrow-left"></i>
                </button>
                <div class="conv-topbar-info">
                    <div class="fw-semibold" id="conv-peer-name">—</div>
                    <small id="conv-peer-status" class="text-muted-light">—</small>
                </div>
                <div id="live-indicator" class="live-indicator d-none">
                    <span class="live-dot"></span> LIVE
                </div>
            </div>

            <!-- Voice cards area -->
            <div id="voice-cards" class="voice-cards">
                <div class="text-center mt-4 text-faint">
                    No voice messages yet
                </div>
            </div>

            <!-- Bottom transmit bar -->
            <div class="transmit-bar">
                <div id="tx-status-label" class="tx-status-label">Ready</div>
                <button id="btn-transmit" class="btn-transmit" disabled>
                    <i class="bi bi-mic-fill me-1"></i> Transmit
                </button>
            </div>
        </div>
    </main>
</div>

<!-- Floating transmission popup -->
<div id="tx-popup" class="tx-popup d-none">
    <div class="tx-popup-head">
        <div class="tx-popup-title-wrap">
            <div class="tx-popup-orb">
                <i class="bi bi-mic-fill"></i>
            </div>
            <div>
                <div class="tx-popup-title">Voice Transmission</div>
                <div id="tx-popup-mode" class="tx-popup-mode">Live</div>
            </div>
        </div>
        <div id="tx-popup-timer" class="tx-popup-timer">00:00</div>
    </div>
    <div id="tx-popup-status" class="tx-popup-status">Initializing…</div>
    <div class="tx-popup-wave">
        <span></span><span></span><span></span><span></span><span></span>
    </div>
    <button id="btn-popup-stop" class="tx-popup-stop">
        <i class="bi bi-stop-fill me-1"></i> Stop & Save
    </button>
</div>

<!-- ===== NEW CONVERSATION MODAL ===== -->
<div class="modal fade" id="modal-new-conv" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-app">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-person-plus me-2 text-purple"></i>New Conversation
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted-light small mb-3">Select a user to start a conversation with.</p>
                <div id="user-picker" class="user-picker">
                    <div class="text-center py-3 text-faint">
                        <div class="spinner-border spinner-border-sm me-2"></div> Loading users…
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
