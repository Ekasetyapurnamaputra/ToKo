<?php
/**
 * Modul Autentikasi: Halaman Login
 * Desain modern, bersih, dan responsif
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';

Session::start();

// Jika sudah login, langsung alihkan ke dashboard
if (Auth::check()) {
    header('Location: index.php?page=dashboard');
    exit;
}

$error = '';
$usernameVal = '';

// Proses Form POST Login
if (is_post()) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        $error = 'Validasi keamanan (CSRF Token) gagal. Silakan muat ulang halaman.';
    } else {
        $username = clean($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $usernameVal = $username;

        if (empty($username) || empty($password)) {
            $error = 'Username dan password wajib diisi.';
        } else {
            $loginResult = Auth::login($username, $password);
            if ($loginResult['success']) {
                Session::setFlash('success', 'Selamat datang kembali, ' . Auth::user()['nama_lengkap'] . '!');
                header('Location: index.php?page=dashboard');
                exit;
            } else {
                $error = $loginResult['message'];
            }
        }
    }
}

$pageTitle = 'Login Pengguna';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        body {
            background: var(--canvas-bg-gradient, linear-gradient(135deg, #2b49c0 0%, #3b5bdb 50%, #2f4fcb 100%));
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
        }
        .login-wrapper {
            width: 100%;
            max-width: 440px;
        }
        .login-card {
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 40px 36px;
        }
        .brand-icon {
            width: 58px;
            height: 58px;
            background: #eff6ff;
            color: #3b82f6;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px auto;
        }
        .demo-chip {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding: 7px 12px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 11px;
            display: inline-block;
            margin-top: 4px;
            font-weight: 600;
        }
        .demo-chip:hover {
            background: #3b82f6;
            color: #ffffff;
            border-color: #3b82f6;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <!-- Logo & Header -->
        <div style="text-align: center; margin-bottom: 28px;">
            <div class="brand-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <path d="M16 10a4 4 0 0 1-8 0"></path>
                </svg>
            </div>
            <h1 style="font-size: 22px; color: #0f172a; margin-bottom: 6px;"><?= e(APP_NAME) ?> System</h1>
            <p style="color: #64748b; font-size: 13px; margin: 0;">Sistem Informasi Penjualan Toko</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px;">
                <span>&#9888; <?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (Session::hasFlash('danger')): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px;">
                <span>&#9888; <?= e(Session::getFlash('danger')) ?></span>
            </div>
        <?php endif; ?>

        <?php if (Session::hasFlash('info')): ?>
            <div class="alert alert-info" style="margin-bottom: 20px;">
                <span>&#8505; <?= e(Session::getFlash('info')) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php?page=login">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" 
                       value="<?= e($usernameVal) ?>" required autofocus autocomplete="username" 
                       placeholder="Masukkan username Anda">
            </div>

            <div class="form-group">
                <label for="password">Kata Sandi</label>
                <input type="password" id="password" name="password" class="form-control" 
                       required autocomplete="current-password" placeholder="••••••••">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 11px; margin-top: 10px; font-size: 14px;">
                Masuk ke Sistem
            </button>
        </form>

        <div style="margin-top: 24px; padding-top: 18px; border-top: 1px dashed #e2e8f0; font-size: 12px; color: #64748b;">
            <div style="font-weight: 700; color: #334155; margin-bottom: 6px;">Akun Cepat (Klik untuk Mengisi):</div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <span class="demo-chip" onclick="fillLogin('admin', 'admin123')">
                    &#128100; <strong>Admin:</strong> admin / admin123
                </span>
                <span class="demo-chip" onclick="fillLogin('kasir', 'admin123')">
                    &#128100; <strong>Kasir:</strong> kasir / admin123
                </span>
            </div>
        </div>
    </div>
</div>

<script>
    function fillLogin(u, p) {
        document.getElementById('username').value = u;
        document.getElementById('password').value = p;
        document.getElementById('password').focus();
    }
</script>

</body>
</html>
