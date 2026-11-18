<?php
/**
 * Modul Katalog Produk untuk Pelanggan / Member
 * Menampilkan katalog produk aktif, harga real-time, ketersediaan stok,
 * penyaringan kategori, pencarian produk, dan ringkasan saldo member.
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/core/Database.php';
require_once dirname(dirname(__DIR__)) . '/models/Produk.php';
require_once dirname(dirname(__DIR__)) . '/models/Kategori.php';
require_once dirname(dirname(__DIR__)) . '/models/MemberAccount.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';

Auth::requireLogin('index.php?page=login');

$isPelanggan = Auth::hasRole('pelanggan');
$member = null;
if ($isPelanggan) {
    $member = MemberAccount::getMemberForUser((int)Auth::id());
}

$keyword    = clean($_GET['q'] ?? '');
$kategoriId = !empty($_GET['kategori_id']) ? (int)$_GET['kategori_id'] : null;

// Ambil hanya produk aktif untuk pelanggan
$produkList   = Produk::getAll($keyword, $kategoriId, 'aktif');
$kategoriList = Kategori::getAll();

$pageTitle = 'Katalog Produk Toko';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<!-- Header Info & Ringkasan Member -->
<?php if ($isPelanggan && $member): ?>
<div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #fff; padding: 20px 24px; border: none; box-shadow: 0 4px 14px rgba(37,99,235,0.25);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
        <div>
            <span style="font-size: 11px; font-weight: 700; letter-spacing: 1px; opacity: 0.8; text-transform: uppercase;">KARTU MEMBER ANDA</span>
            <h2 style="margin: 2px 0 4px 0; color: #fff; font-size: 20px;"><?= e($member['nama_pelanggan']) ?></h2>
            <div style="font-size: 13px; opacity: 0.9; display: flex; align-items: center; gap: 8px;">
                <span>ID Member: <strong style="font-family: monospace; letter-spacing: 0.5px;"><?= e($member['kode_member'] ?: '-') ?></strong></span>
                <span>•</span>
                <span>Kota: <?= e($member['kota']) ?></span>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 20px; background: rgba(255,255,255,0.12); padding: 12px 20px; border-radius: 10px; backdrop-filter: blur(4px);">
            <div>
                <small style="font-size: 11px; opacity: 0.85; text-transform: uppercase; font-weight: 600;">Saldo Belanja Tersedia</small>
                <div style="font-size: 22px; font-weight: 800; color: #fff;"><?= format_rupiah($member['saldo']) ?></div>
            </div>
            <a href="index.php?page=topup" class="btn btn-primary" style="background: #10b981; border-color: #10b981; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; box-shadow: 0 2px 6px rgba(0,0,0,0.15);">
                <span>+ Top-up Saldo</span>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="margin: 0; display: flex; align-items: center; gap: 8px;">
            <span>🛍️ Katalog Produk Toko</span>
            <span class="badge badge-primary"><?= count($produkList) ?> Produk</span>
        </h2>
        <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">
            Jelajahi harga dan ketersediaan stok produk terbaru di ToKo. Tunjukkan kartu member saat berbelanja di kasir.
        </p>
    </div>
    <?php if ($isPelanggan): ?>
    <div style="display: flex; gap: 8px;">
        <a href="index.php?page=member" class="btn btn-secondary">Beranda Member</a>
        <a href="index.php?page=topup" class="btn btn-primary">Top-up Saldo (DANA / GoPay / Bank)</a>
    </div>
    <?php endif; ?>
</div>

<!-- Card Filter & Pencarian -->
<div class="card" style="margin-bottom: 20px;">
    <form method="GET" action="index.php" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
        <input type="hidden" name="page" value="katalog">

        <div style="flex: 1; min-width: 220px;">
            <input type="text" name="q" class="form-control" 
                   placeholder="Cari nama produk atau kode..." value="<?= e($keyword) ?>" autocomplete="off">
        </div>

        <div style="min-width: 180px;">
            <select name="kategori_id" class="form-control">
                <option value="">-- Semua Kategori --</option>
                <?php foreach ($kategoriList as $k): ?>
                    <option value="<?= e($k['id_kategori']) ?>" <?= $kategoriId == $k['id_kategori'] ? 'selected' : '' ?>>
                        <?= e($k['nama_kategori']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-secondary">Cari Produk</button>
        <?php if (!empty($keyword) || !empty($kategoriId)): ?>
            <a href="index.php?page=katalog" class="btn btn-secondary">Reset</a>
        <?php endif; ?>
    </form>
</div>

<!-- Grid Tampilan Produk -->
<div class="product-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px;">
    <?php if (empty($produkList)): ?>
        <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 50px 20px; color: #94a3b8;">
            <div style="font-size: 40px; margin-bottom: 10px;">📦</div>
            <h3 style="margin: 0; color: #475569;">Tidak ada produk yang ditemukan</h3>
            <p style="font-size: 13px; margin: 6px 0 15px 0;">Coba gunakan kata kunci pencarian lain atau pilih kategori yang berbeda.</p>
            <a href="index.php?page=katalog" class="btn btn-primary btn-sm">Tampilkan Semua Produk</a>
        </div>
    <?php else: ?>
        <?php foreach ($produkList as $p): ?>
            <?php
                $stok = (int)$p['stok'];
                $stockClass = $stok <= 0 ? 'stock-critical' : ($stok <= 5 ? 'stock-low' : 'stock-ok');
                $stockLabel = $stok <= 0 ? 'Habis' : ($stok <= 5 ? 'Stok Terbatas' : 'Tersedia');
            ?>
            <article class="product-card" style="display: flex; flex-direction: column; justify-content: space-between; height: 100%; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; background: #fff; transition: transform 0.15s ease, box-shadow 0.15s ease;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; background: #f1f5f9; padding: 2px 8px; border-radius: 4px;">
                            <?= e($p['nama_kategori'] ?? 'Umum') ?>
                        </span>
                        <span class="stock-pill <?= $stockClass ?>" style="font-size: 11px;">
                            <?= $stockLabel ?> (<?= $stok ?>)
                        </span>
                    </div>

                    <div style="font-family: monospace; font-size: 11px; color: #94a3b8; margin-bottom: 4px;">
                        <?= e($p['kode_produk']) ?>
                    </div>

                    <h3 style="font-size: 15px; margin: 0 0 8px 0; color: #1e293b; line-height: 1.35;">
                        <?= e($p['nama_produk']) ?>
                    </h3>
                </div>

                <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <small style="color: #64748b; font-size: 11px; display: block;">Harga</small>
                        <strong style="font-size: 16px; color: #1e3a8a; font-weight: 700;">
                            <?= format_rupiah($p['harga']) ?>
                        </strong>
                    </div>

                    <?php if ($isPelanggan): ?>
                        <span style="font-size: 11px; color: #059669; font-weight: 600; background: #ecfdf5; padding: 4px 8px; border-radius: 6px; border: 1px solid #a7f3d0;">
                            Bayar di Kasir
                        </span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Informasi Belanja Member -->
<div class="card" style="margin-top: 25px; background: #f8fafc; border-left: 4px solid #3b82f6;">
    <h4 style="margin: 0 0 6px 0; color: #1e3a8a;">💡 Cara Berbelanja Menggunakan Kartu Member ToKo</h4>
    <ol style="margin: 0; padding-left: 20px; font-size: 13px; color: #475569; line-height: 1.6;">
        <li>Pilih produk yang Anda butuhkan di toko ToKo.</li>
        <li>Tunjukkan <strong>Kode Member (<?= e($member['kode_member'] ?? 'MEM-XXXX') ?>)</strong> kepada kasir saat pembayaran.</li>
        <li>Kasir akan memproses pembayaran langsung menggunakan <strong>Saldo Kartu Member</strong> Anda.</li>
        <li>Pastikan saldo kartu mencukupi. Anda dapat mengisi ulang saldo kapan saja melalui <strong>DANA, GoPay, Bank BRI, dan Bank BCA</strong> pada menu <a href="index.php?page=topup" style="color: #2563eb; font-weight: 600;">Saldo &amp; Top-up</a>.</li>
    </ol>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
?>
