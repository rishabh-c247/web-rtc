<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VoiceLink — Active Session Detected</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .confirm-card {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 20px;
            padding: 2.5rem 2.5rem 2rem;
            width: 100%;
            max-width: 440px;
            color: #fff;
        }
        .warn-icon {
            font-size: 3rem;
            color: #f5a623;
        }
        .confirm-card h1 { font-size: 1.55rem; font-weight: 700; }
        .confirm-card .sub { color: rgba(255,255,255,0.55); font-size: 0.9rem; }
        .user-badge {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 12px;
            padding: 0.85rem 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.9rem;
        }
        .user-badge .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7b61ff, #5a3fe0);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .user-badge .name { font-weight: 600; font-size: 0.95rem; }
        .user-badge .email { font-size: 0.8rem; color: rgba(255,255,255,0.5); }
        .notice-box {
            background: rgba(245,166,35,0.1);
            border: 1px solid rgba(245,166,35,0.3);
            border-radius: 12px;
            padding: 0.9rem 1rem;
            font-size: 0.875rem;
            color: rgba(255,255,255,0.8);
            line-height: 1.5;
        }
        .notice-box i { color: #f5a623; }
        .btn-confirm {
            background: linear-gradient(90deg, #f5a623, #d4891a);
            border: none;
            border-radius: 10px;
            font-weight: 600;
            letter-spacing: 0.4px;
            padding: 0.65rem;
            color: #fff;
            transition: opacity 0.2s;
        }
        .btn-confirm:hover { opacity: 0.88; color: #fff; }
        .btn-cancel {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 10px;
            font-weight: 500;
            padding: 0.65rem;
            color: rgba(255,255,255,0.75);
            transition: background 0.2s;
        }
        .btn-cancel:hover {
            background: rgba(255,255,255,0.13);
            color: #fff;
        }
        .divider {
            border-top: 1px solid rgba(255,255,255,0.08);
            margin: 1.5rem 0 1.25rem;
        }
    </style>
</head>
<body>
<div class="confirm-card shadow-lg">

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

    <div class="divider"></div>
    <p class="text-center mb-0" style="font-size:0.75rem;color:rgba(255,255,255,0.3);">
        VoiceLink enforces a single active session per account.
    </p>

</div>
</body>
</html>
