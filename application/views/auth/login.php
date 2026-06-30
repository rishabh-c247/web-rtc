<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f4f6fb">
    <title>VoiceLink — Sign In</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="text-center mb-4">
        <div class="brand-icon mb-2"><i class="bi bi-broadcast-pin"></i></div>
        <h1>VoiceLink</h1>
        <p class="sub">Real-time voice transmission</p>
    </div>

    <?php if ($this->session->flashdata('error')): ?>
        <div class="alert alert-danger py-2">
            <i class="bi bi-exclamation-circle me-1"></i>
            <?= htmlspecialchars($this->session->flashdata('error')) ?>
        </div>
    <?php endif; ?>

    <?= form_open('auth/login', ['class' => 'needs-validation', 'novalidate' => '']) ?>
        <div class="mb-3">
            <label class="form-label">Email Address</label>
            <input type="email" class="form-control" name="email"
                   placeholder="e.g. alice@example.com" required autofocus
                   autocomplete="email">
        </div>
        <div class="name-row">
            <div class="mb-3">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" name="first_name"
                       placeholder="Alice" required autocomplete="given-name">
            </div>
            <div class="mb-3">
                <label class="form-label">Last Name</label>
                <input type="text" class="form-control" name="last_name"
                       placeholder="Smith" required autocomplete="family-name">
            </div>
        </div>
        <button type="submit" class="btn btn-primary-gradient btn-primary w-100 mt-1">
            <i class="bi bi-box-arrow-in-right me-2"></i>Enter Dashboard
        </button>
    <?= form_close() ?>

    <p class="text-center mt-3 mb-0 footnote">
        Your email identifies your account. No password required.
    </p>
</div>
</body>
</html>
