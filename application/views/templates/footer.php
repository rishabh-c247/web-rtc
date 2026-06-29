    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const BASE_URL    = '<?= base_url() ?>';
        const CURRENT_USER_ID = <?= (int) $this->session->userdata('user_id') ?>;
        const CURRENT_USER_NAME = '<?= htmlspecialchars($this->session->userdata('first_name') . ' ' . $this->session->userdata('last_name')) ?>';
    </script>
    <script src="<?= base_url('assets/js/webrtc.js') ?>"></script>
    <script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
