<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f4f6fb">
    <title>VoiceLink — Active Session Detected</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body class="auth-page">
<div class="auth-card wide">

    <div class="text-center mb-4">
        <div class="warn-icon mb-2"><i class="bi bi-shield-exclamation"></i></div>
        <h1>Active Session Detected</h1>
        <p class="sub mt-1">You are already signed in on another device.</p>
    </div>

    <!-- Who is trying to sign in -->
    <div class="user-badge mb-3">
        <div class="avatar">
            <?= strtoupper(substr($pending['first_name'], 0, 1) . substr($pending['last_name'], 0, 1)) ?>
        </div>
        <div>
            <div class="name">
                <?= htmlspecialchars($pending['first_name'] . ' ' . $pending['last_name']) ?>
            </div>
            <div class="email"><?= htmlspecialchars($pending['email']) ?></div>
        </div>
    </div>

    <div class="notice-box mb-4">
        <i class="bi bi-info-circle me-2"></i>
        Signing in here will <strong>immediately sign out</strong> the other device.
        Any active voice transmission on that device will be interrupted.
    </div>

    <!-- Confirm → POST -->
    <?= form_open('auth/confirm') ?>
        <button type="submit" class="btn btn-confirm w-100 mb-2">
            <i class="bi bi-box-arrow-in-right me-2"></i>Yes, sign in on this device
        </button>
    <?= form_close() ?>

    <!-- Cancel → GET (no side effects) -->
    <a href="<?= site_url('auth/confirm/cancel') ?>" class="btn btn-cancel w-100 d-block text-center text-decoration-none">
        <i class="bi bi-x-circle me-2"></i>Cancel — stay signed in on the other device
    </a>

    <div class="auth-divider"></div>
    <p class="text-center mb-0 footnote">
        VoiceLink enforces a single active session per account.
    </p>

</div>
</body>
</html>
