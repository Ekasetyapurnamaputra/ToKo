<?php
/**
 * Master Front Controller & Router
 * Sistem Informasi Penjualan ToKo
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Session.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Logger.php';
require_once __DIR__ . '/helpers/security_helper.php';
require_once __DIR__ . '/helpers/format_helper.php';

Session::start();

// Tangkap parameter halaman
$page = $_GET['page'] ?? '';

// Jika tidak ada parameter page, arahkan sesuai status login
if (empty($page)) {
    if (Auth::check()) {
        $page = 'dashboard';
    } else {
        $page = 'login';
    }
}

// Peta Routing Modul Aplikasi
$routes = [
    'login'     => __DIR__ . '/modules/auth/login.php',
    'logout'    => __DIR__ . '/modules/auth/logout.php',
    'dashboard' => __DIR__ . '/modules/dashboard/index.php',
    'pelanggan' => __DIR__ . '/modules/pelanggan/index.php',
    'produk'    => __DIR__ . '/modules/produk/index.php',
    'kategori'  => __DIR__ . '/modules/kategori/index.php',
    'transaksi' => __DIR__ . '/modules/transaksi/index.php',
    'laporan'   => __DIR__ . '/modules/laporan/index.php',
    'log'       => __DIR__ . '/modules/log/index.php',
    'pengaturan'=> __DIR__ . '/modules/pengaturan/index.php',
];

if (array_key_exists($page, $routes)) {
    $targetFile = $routes[$page];
    if (file_exists($targetFile)) {
        require_once $targetFile;
    } else {
        // Halaman modul belum dibuat (akan dibuat pada tahap berikutnya)
        Auth::requireLogin();
        $pageTitle = 'Tahap Pengembangan';
        require_once __DIR__ . '/templates/header.php';
        echo '<div class="card">';
        echo '<h3>Modul ' . ucfirst(e($page)) . '</h3>';
        echo '<p>Modul ini dijadwalkan pada tahap pengerjaan berikutnya sesuai rencana kerja master prompt.</p>';
        echo '<a href="index.php?page=dashboard" class="btn btn-primary">&laquo; Kembali ke Dashboard</a>';
        echo '</div>';
        require_once __DIR__ . '/templates/footer.php';
    }
} else {
    // 404 Not Found
    http_response_code(404);
    $pageTitle = 'Halaman Tidak Ditemukan (404)';
    require_once __DIR__ . '/templates/header.php';
    echo '<div class="card" style="text-align: center; padding: 40px 20px;">';
    echo '<h1 style="font-size: 48px; margin: 0; color: #dc3545;">404</h1>';
    echo '<p style="font-size: 16px; color: #6c757d;">Halaman yang Anda tuju tidak ditemukan.</p>';
    echo '<a href="index.php" class="btn btn-primary">Kembali ke Beranda</a>';
    echo '</div>';
    require_once __DIR__ . '/templates/footer.php';
}
