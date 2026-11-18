<?php
/**
 * Public ToKo landing page and live product catalog.
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Database.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';

// Pengguna dapat melihat halaman home tanpa dipaksa redirect
$isLoggedIn = Auth::check();
$dashboardUrl = $isLoggedIn ? Auth::dashboardUrl() : 'index.php?page=login';

$products = Database::getInstance()->fetchAll(
    "SELECT p.id_produk, p.nama_produk, p.harga, p.stok, p.kode_produk, k.nama_kategori
     FROM tbl_produk p
     LEFT JOIN tbl_kategori k ON p.id_kategori = k.id_kategori
     WHERE p.status = 'aktif' AND p.stok > 0
     ORDER BY p.nama_produk ASC
     LIMIT 8"
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ToKo — Belanja mudah dengan kartu member</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= APP_VERSION ?>">
</head>
<body class="public-home">
    <header class="public-header">
        <a class="public-brand" href="index.php?page=home" aria-label="ToKo beranda">
            <span class="public-brand-mark" aria-hidden="true">T</span>
            <span>ToKo</span>
        </a>
        <nav class="public-nav" aria-label="Navigasi utama">
            <a href="#produk">Produk</a>
            <a href="#member">Kartu member</a>
        </nav>
        <div class="public-header-actions">
            <?php if ($isLoggedIn): ?>
                <a class="btn btn-secondary" href="index.php?page=katalog">Katalog Lengkap</a>
                <a class="btn btn-primary" href="<?= e($dashboardUrl) ?>"><?= Auth::hasRole('pelanggan') ? 'Ruang Member' : 'Dashboard' ?></a>
            <?php else: ?>
                <a class="btn btn-secondary" href="index.php?page=login">Masuk</a>
                <a class="btn btn-primary" href="index.php?page=register">Daftar member</a>
            <?php endif; ?>
        </div>
    </header>

    <main>
        <section class="public-hero">
            <div class="public-hero-copy">
                <span class="eyebrow">BELANJA LEBIH PRAKTIS</span>
                <h1>Kebutuhan harian, pengalaman belanja yang lebih mudah.</h1>
                <p>Jelajahi produk pilihan dan gunakan kartu member ToKo untuk pembayaran saldo yang praktis di kasir.</p>
                <div class="public-hero-actions">
                    <a class="btn btn-primary" href="index.php?page=register">Buat akun member</a>
                    <a class="public-text-link" href="#produk">Lihat katalog <span aria-hidden="true">→</span></a>
                </div>
                <div class="public-trust-note"><span aria-hidden="true">✓</span> Saldo hanya ditambahkan setelah pembayaran diverifikasi.</div>
            </div>
            <div class="public-hero-card" aria-hidden="true">
                <div class="hero-card-top"><span>ToKo Member</span><span class="hero-card-dot"></span></div>
                <div class="hero-card-chip"></div>
                <div class="hero-card-number">MEMBER · BELANJA · MUDAH</div>
                <div class="hero-card-bottom"><strong>ToKo</strong><span>MEMBER CARD</span></div>
            </div>
        </section>

        <section class="public-feature-row" id="member">
            <article><span class="feature-index">01</span><div><h2>Saldo member</h2><p>Ajukan top-up dan cek saldo dari akun Anda.</p></div></article>
            <article><span class="feature-index">02</span><div><h2>Bayar di kasir</h2><p>Pembayaran diproses langsung dari saldo kartu member.</p></div></article>
            <article><span class="feature-index">03</span><div><h2>Riwayat tercatat</h2><p>Lihat pembelian dan mutasi saldo kapan saja.</p></div></article>
        </section>

        <section class="public-products" id="produk">
            <div class="public-section-heading">
                <div><span class="eyebrow">KATALOG TOKO</span><h2>Produk yang tersedia</h2></div>
                <span>Harga dan stok terkini</span>
            </div>
            <div class="public-product-grid">
                <?php if (!$products): ?>
                    <div class="public-empty">Belum ada produk tersedia saat ini.</div>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <article class="public-product-card">
                            <span class="product-category"><?= e($product['nama_kategori'] ?? 'Pilihan toko') ?></span>
                            <h3><?= e($product['nama_produk']) ?></h3>
                            <div class="public-product-meta">
                                <strong><?= e(format_rupiah($product['harga'])) ?></strong>
                                <span><?= (int)$product['stok'] ?> tersedia</span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer class="public-footer">
        <a class="public-brand" href="index.php?page=home"><span class="public-brand-mark">T</span><span>ToKo</span></a>
        <span>&copy; <?= date('Y') ?> ToKo. Belanja nyaman untuk setiap hari.</span>
    </footer>
</body>
</html>
