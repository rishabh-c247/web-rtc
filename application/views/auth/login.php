<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VoiceLink — Sign In</title>
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
        .login-card {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 20px;
            padding: 2.5rem 2.5rem 2rem;
            width: 100%;
            max-width: 420px;
            color: #fff;
        }
        .login-card .brand-icon {
            font-size: 3rem;
            color: #7b61ff;
        }
        .login-card h1 { font-size: 1.75rem; font-weight: 700; }
        .login-card p.sub { color: rgba(255,255,255,0.55); font-size: 0.9rem; }
        .form-control {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff;
            border-radius: 10px;
        }
        .form-control:focus {
            background: rgba(255,255,255,0.12);
            border-color: #7b61ff;
            color: #fff;
            box-shadow: 0 0 0 0.2rem rgba(123,97,255,.35);
        }
        .form-control::placeholder { color: rgba(255,255,255,0.35); }
        .form-label { color: rgba(255,255,255,0.75); font-size: 0.85rem; font-weight: 500; }
        .btn-enter {
            background: linear-gradient(90deg, #7b61ff, #5a3fe0);
            border: none;
            border-radius: 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
            padding: 0.65rem;
            transition: opacity 0.2s;
        }
        .btn-enter:hover { opacity: 0.88; }
        .alert-danger {
            background: rgba(220,53,69,0.2);
            border-color: rgba(220,53,69,0.4);
            color: #ff8a8a;
            border-radius: 10px;
        }
    </style>
</head>
<body>
<div class="login-card shadow-lg">
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
            <label class="form-label">First Name</label>
            <input type="text" class="form-control" name="first_name"
                   placeholder="e.g. Alice" required autofocus>
        </div>
        <div class="mb-4">
            <label class="form-label">Last Name</label>
            <input type="text" class="form-control" name="last_name"
                   placeholder="e.g. Smith" required>
        </div>
        <button type="submit" class="btn btn-enter btn-primary w-100">
            <i class="bi bi-box-arrow-in-right me-2"></i>Enter Dashboard
        </button>
    <?= form_close() ?>

    <p class="text-center mt-3 mb-0" style="font-size:0.78rem;color:rgba(255,255,255,0.35);">
        No password required — enter your name to join.
    </p>
</div>
</body>
</html>
