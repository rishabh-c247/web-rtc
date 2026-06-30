    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const BASE_URL          = '<?= base_url() ?>';
        const CURRENT_USER_ID   = <?= (int) $this->session->userdata('user_id') ?>;
        const CURRENT_USER_NAME = '<?= htmlspecialchars($this->session->userdata('first_name') . ' ' . $this->session->userdata('last_name')) ?>';
        /**
         * SESSION_TOKEN is the single-device session token stored in the CI session.
         * The WS server validates this against the users.session_token column on connect.
         * It is exposed here only to same-origin JavaScript — equivalent exposure to
         * the CI session cookie that already carries this value.
         */
        const SESSION_TOKEN = '<?= htmlspecialchars($this->session->userdata('session_token') ?? '') ?>';
        /**
         * WS_URL is the public WebSocket endpoint.
         * In development: ws://localhost:8080
         * In production:  wss://yourdomain.com/ws  (Apache proxies /ws → localhost:8080)
         */
        const WS_URL = '<?= rtrim($_ENV['WS_PUBLIC_URL'] ?? 'ws://localhost:8080', '/') ?>';
    </script>
    <script src="<?= base_url('assets/js/ws-client.js') ?>"></script>
    <script src="<?= base_url('assets/js/webrtc.js') ?>"></script>
    <script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
