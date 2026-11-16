<?php
/**
 * Preferensi tampilan aplikasi ToKo.
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';

Auth::requireLogin();
$currentUser = Auth::user();
$pageTitle = 'Pengaturan';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<div class="page-intro">
    <div>
        <h2>Pengaturan</h2>
        <p>Sesuaikan kenyamanan tampilan ToKo di perangkat ini.</p>
    </div>
</div>

<div class="settings-grid">
    <section class="card settings-card">
        <div class="card-header">
            <div>
                <div class="card-title">Preferensi tampilan</div>
                <small>Pengaturan ini disimpan di browser perangkat Anda.</small>
            </div>
        </div>

        <label class="settings-option" for="compact-sidebar">
            <span>
                <strong>Sidebar ringkas</strong>
                <small>Tampilkan navigasi ikon saja pada layar desktop.</small>
            </span>
            <input class="settings-switch" type="checkbox" id="compact-sidebar" data-preference="sidebar-compact">
        </label>

        <label class="settings-option" for="compact-tables">
            <span>
                <strong>Tabel lebih padat</strong>
                <small>Kurangi jarak baris untuk menampilkan lebih banyak data.</small>
            </span>
            <input class="settings-switch" type="checkbox" id="compact-tables" data-preference="tables-compact">
        </label>
    </section>

    <section class="card settings-card">
        <div class="card-header">
            <div>
                <div class="card-title">Informasi akun</div>
                <small>Akun yang sedang digunakan untuk mengakses sistem.</small>
            </div>
            <span class="badge badge-<?= e($currentUser['role']) ?>"><?= e(ucfirst($currentUser['role'])) ?></span>
        </div>

        <dl class="settings-details">
            <div><dt>Nama pengguna</dt><dd><?= e($currentUser['nama_lengkap']) ?></dd></div>
            <div><dt>Username</dt><dd><?= e($currentUser['username']) ?></dd></div>
            <div><dt>Hak akses</dt><dd><?= e(ucfirst($currentUser['role'])) ?></dd></div>
            <div><dt>Versi aplikasi</dt><dd><?= e(APP_VERSION) ?></dd></div>
        </dl>
    </section>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
