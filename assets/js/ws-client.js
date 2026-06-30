/**
 * VoiceLink WebSocket Client
 *
 * Manages the lifecycle of a single WebSocket connection:
 *   - Authenticates immediately on open
 *   - Auto-reconnects with exponential back-off (1s → 30s)
 *   - Sends a heartbeat ping every 5 s (vs. the old 1-second HTTP poll)
 *   - Queues outbound messages while not yet authenticated
 *   - Provides a typed event subscription API (on/off/once)
 *
 * Usage (in app.js):
 *   const ws = new WsClient({ url: WS_URL, userId: CURRENT_USER_ID, token: SESSION_TOKEN });
 *   ws.on('presence_update', data => { ... });
 *   ws.connect();
 */
class WsClient {
    /**
     * @param {Object} config
     * @param {string} config.url      WebSocket URL (ws:// or wss://)
     * @param {number} config.userId   Authenticated user ID
     * @param {string} config.token    CI session_token for WS auth
     * @param {number} [config.heartbeatMs=5000]   Heartbeat interval
     * @param {number} [config.maxReconnectMs=30000] Max reconnect delay
     */
    constructor (config) {
        this._url            = config.url;
        this._userId         = config.userId;
        this._token          = config.token;
        this._heartbeatMs    = config.heartbeatMs    ?? 5000;
        this._maxReconnectMs = config.maxReconnectMs ?? 30000;

        this._ws              = null;
        this._listeners       = {};       // event → [callback, ...]
        this._queue           = [];       // messages queued before auth completes
        this._reconnectDelay  = 1000;     // current delay, doubles on each attempt
        this._reconnectTimer  = null;
        this._heartbeatTimer  = null;
        this._connected       = false;    // TCP open
        this._authed          = false;    // auth_ok received
        this._shouldReconnect = true;     // set false on clean close
        this.hasEverConnected = false;    // first successful auth?
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    connect () {
        if (this._ws && this._ws.readyState < WebSocket.CLOSING) {
            return; // already open or connecting
        }
        try {
            this._ws = new WebSocket(this._url);
        } catch (e) {
            console.error('[WS] Failed to create WebSocket:', e);
            this._scheduleReconnect();
            return;
        }
        this._ws.onopen    = ()  => this._onOpen();
        this._ws.onmessage = (e) => this._onMessage(e);
        this._ws.onclose   = (e) => this._onClose(e);
        this._ws.onerror   = (e) => this._onError(e);
    }

    /** Permanently close — will NOT reconnect. */
    disconnect () {
        this._shouldReconnect = false;
        this._cleanup();
        this._ws?.close(1000, 'Client disconnect');
    }

    /**
     * Send an event to the server.
     * Messages are queued if the connection is not yet authenticated.
     */
    send (event, data = {}) {
        const msg = JSON.stringify({ event, data });
        if (this._authed && this._ws?.readyState === WebSocket.OPEN) {
            this._ws.send(msg);
        } else {
            this._queue.push(msg);
        }
    }

    /**
     * Subscribe to a server-side event.
     * Returns `this` for chaining.
     */
    on (event, callback) {
        if (!this._listeners[event]) this._listeners[event] = [];
        this._listeners[event].push(callback);
        return this;
    }

    /** Unsubscribe a specific callback. */
    off (event, callback) {
        if (this._listeners[event]) {
            this._listeners[event] = this._listeners[event].filter(cb => cb !== callback);
        }
    }

    /** Subscribe once — auto-removes after first call. */
    once (event, callback) {
        const wrapper = (data) => {
            callback(data);
            this.off(event, wrapper);
        };
        return this.on(event, wrapper);
    }

    /** True when authenticated and connection is open. */
    get isConnected () {
        return this._authed && this._ws?.readyState === WebSocket.OPEN;
    }

    // ── Internal lifecycle ─────────────────────────────────────────────────────

    _onOpen () {
        this._connected     = true;
        this._reconnectDelay = 1000; // reset back-off on successful open
        console.log('[WS] Connected — authenticating…');

        // Authenticate immediately — server expects this within 15 s
        this._ws.send(JSON.stringify({
            event: 'auth',
            data: { user_id: this._userId, token: this._token },
        }));
    }

    _onMessage (e) {
        let msg;
        try {
            msg = JSON.parse(e.data);
        } catch {
            console.warn('[WS] Non-JSON message received:', e.data);
            return;
        }

        const { event, data } = msg;

        if (event === 'auth_ok') {
            this._authed          = true;
            this.hasEverConnected = true;
            this._startHeartbeat();
            this._flushQueue();
            console.log('[WS] Authenticated as user', data?.user_id);
        }

        if (event === 'auth_error') {
            console.error('[WS] Auth failed:', data?.message);
            // Do not reconnect — session is invalid
            this._shouldReconnect = false;
        }

        // Dispatch to all registered listeners
        this._emit(event, data ?? {});
    }

    _onClose (e) {
        const wasAuthed = this._authed;
        this._connected = false;
        this._authed    = false;
        this._cleanup();

        console.warn(`[WS] Closed — code=${e.code} reason=${e.reason || '(none)'} wasAuthed=${wasAuthed}`);
        this._emit('disconnected', { code: e.code, reason: e.reason, wasAuthed });

        if (this._shouldReconnect) {
            this._scheduleReconnect();
        }
    }

    _onError () {
        // onError fires before onClose; the actual close will trigger reconnect
        console.error('[WS] WebSocket error (connection will close)');
        this._emit('ws_error', {});
    }

    _emit (event, data) {
        const listeners = this._listeners[event];
        if (!listeners) return;
        listeners.forEach(cb => {
            try { cb(data); }
            catch (err) { console.error(`[WS] Listener error for [${event}]:`, err); }
        });
    }

    _scheduleReconnect () {
        if (this._reconnectTimer) return;
        const delay = this._reconnectDelay;
        console.log(`[WS] Reconnecting in ${delay}ms…`);
        this._reconnectTimer = setTimeout(() => {
            this._reconnectTimer = null;
            this.connect();
        }, delay);
        // Exponential back-off with jitter (±10 %)
        const jitter = 1 + (Math.random() * 0.2 - 0.1);
        this._reconnectDelay = Math.min(delay * 2 * jitter, this._maxReconnectMs);
    }

    _startHeartbeat () {
        this._stopHeartbeat();
        this._heartbeatTimer = setInterval(() => {
            if (this._authed && this._ws?.readyState === WebSocket.OPEN) {
                this._ws.send(JSON.stringify({ event: 'heartbeat', data: {} }));
            }
        }, this._heartbeatMs);

        // Resume immediately after tab becomes visible (overcome browser throttling)
        document.addEventListener('visibilitychange', this._visibilityHandler ??= () => {
            if (!document.hidden && this._authed) {
                this._ws?.send(JSON.stringify({ event: 'heartbeat', data: {} }));
            }
        });
    }

    _stopHeartbeat () {
        if (this._heartbeatTimer) {
            clearInterval(this._heartbeatTimer);
            this._heartbeatTimer = null;
        }
    }

    _flushQueue () {
        while (this._queue.length > 0 && this._ws?.readyState === WebSocket.OPEN) {
            this._ws.send(this._queue.shift());
        }
    }

    _cleanup () {
        this._stopHeartbeat();
    }
}
