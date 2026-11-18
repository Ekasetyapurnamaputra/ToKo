<?php
/**
 * Public registration for a new member account and card.
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/MemberAccount.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';

Session::start();
if (Auth::check()) {
    header('Location: ' . Auth::dashboardUrl());
    exit;
}

$error = '';
$name = '';
$city = '';
$username = '';

if (is_post()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Form sudah kedaluwarsa. Muat ulang halaman, lalu coba lagi.';
    } else {
        $name = clean($_POST['nama_lengkap'] ?? '');
        $city = clean($_POST['kota'] ?? '');
        $username = clean($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if ($password !== $passwordConfirm) {
            $error = 'Konfirmasi kata sandi tidak cocok.';
        } else {
            $result = MemberAccount::register($name, $city, $username, $password);
            if ($result['success']) {
                $login = Auth::login($username, $password);
                if ($login['success']) {
                    Session::setFlash('success', 'Akun dan kartu member berhasil dibuat. Selamat datang di ToKo!');
                    header('Location: index.php?page=member');
                    exit;
                }
                $error = 'Akun berhasil dibuat. Silakan masuk dengan username dan kata sandi Anda.';
            } else {
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar Member — ToKo</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= APP_VERSION ?>">
</head>
<body class="public-auth">
    <main class="auth-panel">
        <a class="public-brand auth-brand" href="index.php?page=home"><span class="public-brand-mark">T</span><span>ToKo</span></a>
        <span class="eyebrow">GABUNG MENJADI MEMBER</span>
        <h1>Buat akun ToKo</h1>
        <p class="auth-intro">Akun Anda terhubung langsung dengan kartu member dan saldo belanja.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php?page=register">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="nama_lengkap">Nama lengkap</label>
                <input class="form-control" id="nama_lengkap" name="nama_lengkap" maxlength="100" value="<?= e($name) ?>" required autocomplete="name">
            </div>
            <div class="form-group">
                <label for="kota">Kota</label>
                <input class="form-control" id="kota" name="kota" maxlength="100" value="<?= e($city) ?>" required autocomplete="address-level2">
            </div>
            <div class="form-group">
                <label for="username">Username</label>
                <input class="form-control" id="username" name="username" minlength="3" maxlength="50" pattern="[\w.\x2d]{3,50}" value="<?= e($username) ?>" required autocomplete="username">
                <small class="auth-hint">Gunakan 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.</small>
            </div>
            <div class="form-group">
                <label for="password">Kata sandi</label>
                <input class="form-control" type="password" id="password" name="password" minlength="8" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="password_confirm">Ulangi kata sandi</label>
                <input class="form-control" type="password" id="password_confirm" name="password_confirm" minlength="8" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary auth-submit">Buat akun member</button>
        </form>

        <p class="auth-switch">Sudah memiliki akun? <a href="index.php?page=login">Masuk di sini</a></p>
        <a class="auth-home-link" href="index.php?page=home">← Kembali ke beranda</a>
    </main>
</body>
</html>
