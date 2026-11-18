<?php
/**
 * Shared Application Shell & Navigation
 * Sistem Informasi Penjualan ToKoKita - Modern SaaS Design
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Session.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/helpers/security_helper.php';
require_once dirname(__DIR__) . '/helpers/format_helper.php';

Session::start();
$currentUser = Auth::user();
$currentPage = $_GET['page'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= APP_VERSION ?>">
    <!-- React dipakai sebagai enhancement UI tanpa memutus backend PHP. -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
</head>
<body>

<?php if (Auth::check()): ?>
<!-- 1. Canvas Top Header -->
<header class="canvas-header no-print">
    <h1 class="canvas-title">Sistem Penjualan ToKo</h1>
    <?php if (!Auth::hasRole('pelanggan')): ?>
    <a href="index.php?page=transaksi&action=create" class="canvas-mode-pill" style="text-decoration: none;">
        <span>+ Kasir POS</span>
    </a>
    <?php else: ?>
    <a href="index.php?page=topup" class="canvas-mode-pill" style="text-decoration: none;">
        <span>Kelola saldo</span>
    </a>
    <?php endif; ?>
</header>

<!-- 2. Main Floating White Window Shell -->
<div class="app-shell">
    
    <!-- Left Royal Blue Sidebar -->
    <aside class="sidebar no-print">
        <!-- Far Left Icon Strip -->
        <div class="sidebar-strip">
            <svg class="sidebar-strip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" title="Dashboard"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            <svg class="sidebar-strip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" title="Produk"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>
            <svg class="sidebar-strip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" title="Scan Pabrik"><path d="M3 7V5a2 2 0 0 1 2-2h2"></path><path d="M17 3h2a2 2 0 0 1 2 2v2"></path><path d="M21 17v2a2 2 0 0 1-2 2h-2"></path><path d="M7 21H5a2 2 0 0 1-2-2v-2"></path><line x1="7" y1="12" x2="17" y2="12"></line></svg>
            <svg class="sidebar-strip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" title="Kategori"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            <svg class="sidebar-strip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" title="Pelanggan"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            <svg class="sidebar-strip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" title="Transaksi Kasir"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
            <svg class="sidebar-strip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" title="Laporan"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
            <svg class="sidebar-strip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" title="Audit Log"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </div>

        <!-- Sidebar Navigation Menu -->
        <div class="sidebar-main">
            <div>
                <!-- Brand: ToKoKita -->
                <a href="index.php?page=dashboard" class="sidebar-brand">
                    <div class="sidebar-brand-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    </div>
                    <span><?= Auth::hasRole('pelanggan') ? 'ToKo Member' : 'ToKo' ?></span>
                </a>

                <!-- Navigation links follow the active account role. -->
                <ul class="sidebar-menu">
                    <?php if (Auth::hasRole('pelanggan')): ?>
                        <li><a href="index.php?page=member" class="sidebar-link <?= $currentPage === 'member' ? 'active' : '' ?>"><span>Beranda Member</span></a></li>
                        <li><a href="index.php?page=katalog" class="sidebar-link <?= $currentPage === 'katalog' ? 'active' : '' ?>"><span>Katalog Produk</span></a></li>
                        <li><a href="index.php?page=topup" class="sidebar-link <?= $currentPage === 'topup' ? 'active' : '' ?>"><span>Pembayaran &amp; Top-up</span></a></li>
                    <?php else: ?>
                    <li>
                        <a href="index.php?page=dashboard" class="sidebar-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="index.php?page=produk" class="sidebar-link <?= $currentPage === 'produk' ? 'active' : '' ?>" title="Master Data Produk">
                            <span>Produk</span>
                        </a>
                    </li>
                    <li>
                        <a href="index.php?page=scan_pabrik" class="sidebar-link <?= $currentPage === 'scan_pabrik' ? 'active' : '' ?>" title="Scan & Penerimaan Produk dari Pabrik">
                            <span>Scan Pabrik</span>
                        </a>
                    </li>
                    <li>
                        <a href="index.php?page=kategori" class="sidebar-link <?= $currentPage === 'kategori' ? 'active' : '' ?>" title="Master Kategori Produk">
                            <span>Kategori</span>
                        </a>
                    </li>
                    <li>
                        <a href="index.php?page=pelanggan" class="sidebar-link <?= $currentPage === 'pelanggan' ? 'active' : '' ?>" title="Data Pelanggan Toko">
                            <span>Pelanggan</span>
                        </a>
                    </li>
                    <li>
                        <a href="index.php?page=transaksi" class="sidebar-link <?= $currentPage === 'transaksi' ? 'active' : '' ?>" title="Kasir & Riwayat Transaksi">
                            <span>Transaksi</span>
                        </a>
                    </li>
                    <li>
                        <a href="index.php?page=laporan" class="sidebar-link <?= $currentPage === 'laporan' ? 'active' : '' ?>" title="Laporan Omzet & Analitik">
                            <span>Laporan</span>
                        </a>
                    </li>
                    <?php if (Auth::hasRole('admin')): ?>
                        <li>
                            <a href="index.php?page=log" class="sidebar-link <?= $currentPage === 'log' ? 'active' : '' ?>" title="Audit Log & Database Trigger">
                                <span>Audit Log</span>
                            </a>
                        </li>
                        <li>
                            <a href="index.php?page=topup" class="sidebar-link <?= $currentPage === 'topup' ? 'active' : '' ?>" title="Verifikasi saldo member">
                                <span>Verifikasi Top-up</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <li>
                        <a href="index.php?page=pengaturan" class="sidebar-link <?= $currentPage === 'pengaturan' ? 'active' : '' ?>">
                            <span>Pengaturan</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Sidebar Bottom: Log Out -->
            <div class="sidebar-footer">
                <a href="index.php?page=logout" class="btn-sidebar-logout" onclick="return confirm('Apakah Anda yakin ingin keluar?')">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    <span>Log Out</span>
                </a>
            </div>
        </div>
    </aside>
    <button class="sidebar-backdrop" id="sidebar-backdrop" type="button" aria-label="Tutup menu"></button>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Topbar -->
        <header class="topbar no-print">
            <button class="mobile-toggle" id="mobile-menu-toggle" type="button" aria-label="Buka menu" aria-expanded="false">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
            <!-- Search Pill -->
            <?php if (!Auth::hasRole('pelanggan')): ?>
            <form method="GET" action="index.php" class="topbar-search">
                <input type="hidden" name="page" value="produk">
                <input type="text" name="q" placeholder="Cari produk, transaksi, pelanggan...">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </form>
            <?php else: ?>
                <div class="topbar-member-label">Area member ToKo</div>
            <?php endif; ?>

            <!-- Topbar Right Actions -->
            <div class="topbar-actions">
                <div class="lang-pill" title="Bahasa Sistem">
                    <span>IDN</span>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </div>

                <?php if (!Auth::hasRole('pelanggan')): ?>
                <a href="index.php?page=laporan" class="topbar-icon-btn" title="Laporan Penjualan">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                </a>
                <?php endif; ?>

                <a href="index.php?page=<?= Auth::hasRole('admin') ? 'log' : (Auth::hasRole('pelanggan') ? 'topup' : 'transaksi') ?>" class="topbar-icon-btn" title="<?= Auth::hasRole('pelanggan') ? 'Saldo member' : 'Notifikasi & Audit Log' ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                        <path d="M10 21h4"></path>
                    </svg>
                </a>

                <div class="topbar-user" title="Profil Pengguna Aktif">
                    <div class="topbar-avatar">
                        <?= strtoupper(substr($currentUser['nama_lengkap'] ?? $currentUser['username'] ?? 'U', 0, 1)) ?>
                    </div>
                    <div>
                        <span class="topbar-user-name"><?= e($currentUser['nama_lengkap'] ?? $currentUser['username']) ?></span>
                        <span style="font-size: 11px; color: var(--text-muted); display: block; line-height: 1;">(<?= ucfirst($currentUser['role'] ?? 'user') ?>)</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Page Body Container -->
        <div class="page-body">
            <!-- Flash Alerts -->
            <?php if (Session::hasFlash('success')): ?>
                <div class="alert alert-success">
                    <span>&#10004; <?= e(Session::getFlash('success')) ?></span>
                    <span style="cursor: pointer;" onclick="this.parentElement.remove()">&times;</span>
                </div>
            <?php endif; ?>
            <?php if (Session::hasFlash('danger')): ?>
                <div class="alert alert-danger">
                    <span>&#9888; <?= e(Session::getFlash('danger')) ?></span>
                    <span style="cursor: pointer;" onclick="this.parentElement.remove()">&times;</span>
                </div>
            <?php endif; ?>
<?php endif; ?>
