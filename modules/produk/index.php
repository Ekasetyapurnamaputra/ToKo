<?php
/**
 * Modul Manajemen Produk
 * Mendukung CRUD produk, pengaturan harga, penyesuaian stok, dan proteksi transaksi masa lalu
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/Produk.php';
require_once dirname(dirname(__DIR__)) . '/models/Kategori.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';

if (Auth::hasRole('pelanggan')) {
    header('Location: index.php?page=katalog');
    exit;
}

Auth::requireRole(['admin', 'kasir']);

$action = $_GET['action'] ?? 'list';
$error = '';

// ====================================================================
// 1. PROSES FORM POST
// ====================================================================
if (is_post()) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        Session::setFlash('danger', 'Validasi keamanan (CSRF Token) gagal.');
        header('Location: index.php?page=produk');
        exit;
    }

    $postAction = $_POST['post_action'] ?? '';

    // Tambah Produk
    if ($postAction === 'create') {
        $nama          = clean($_POST['nama_produk'] ?? '');
        $kode          = clean($_POST['kode_produk'] ?? '');
        $barcodePabrik = clean($_POST['barcode_pabrik'] ?? '');
        $kategori      = (int)($_POST['id_kategori'] ?? 0);
        $harga         = (float)($_POST['harga'] ?? 0);
        $hargaBeli     = (float)($_POST['harga_beli'] ?? 0);
        $stok          = (int)($_POST['stok'] ?? 0);
        $status        = clean($_POST['status'] ?? 'aktif');

        if (empty($nama) || $harga < 0 || $stok < 0 || $hargaBeli < 0) {
            $error = 'Nama produk wajib diisi, serta harga dan stok tidak boleh bernilai negatif.';
        } else {
            $res = Produk::create([
                'kode_produk'    => $kode,
                'barcode_pabrik' => $barcodePabrik,
                'nama_produk'    => $nama,
                'id_kategori'    => $kategori > 0 ? $kategori : null,
                'harga'          => $harga,
                'harga_beli'     => $hargaBeli,
                'stok'           => $stok,
                'status'         => $status
            ]);

            if ($res['success']) {
                Session::setFlash('success', "Produk '{$nama}' berhasil ditambahkan.");
                header('Location: index.php?page=produk');
                exit;
            } else {
                $error = $res['message'];
            }
        }
    }

    // Ubah Produk
    if ($postAction === 'edit') {
        $id            = (int)($_POST['id_produk'] ?? 0);
        $nama          = clean($_POST['nama_produk'] ?? '');
        $kode          = clean($_POST['kode_produk'] ?? '');
        $barcodePabrik = clean($_POST['barcode_pabrik'] ?? '');
        $kategori      = (int)($_POST['id_kategori'] ?? 0);
        $harga         = (float)($_POST['harga'] ?? 0);
        $hargaBeli     = (float)($_POST['harga_beli'] ?? 0);
        $stok          = (int)($_POST['stok'] ?? 0);
        $status        = clean($_POST['status'] ?? 'aktif');

        if ($id <= 0 || empty($nama) || $harga < 0 || $stok < 0 || $hargaBeli < 0) {
            $error = 'Nama produk wajib diisi, serta harga dan stok tidak boleh bernilai negatif.';
        } else {
            $res = Produk::update($id, [
                'kode_produk'    => $kode,
                'barcode_pabrik' => $barcodePabrik,
                'nama_produk'    => $nama,
                'id_kategori'    => $kategori > 0 ? $kategori : null,
                'harga'          => $harga,
                'harga_beli'     => $hargaBeli,
                'stok'           => $stok,
                'status'         => $status
            ]);

            if ($res['success']) {
                Session::setFlash('success', "Data produk '{$nama}' berhasil diperbarui.");
                header('Location: index.php?page=produk');
                exit;
            } else {
                $error = $res['message'];
            }
        }
    }

    // Penyesuaian Stok Manual
    if ($postAction === 'adjust_stock') {
        $id       = (int)($_POST['id_produk'] ?? 0);
        $newStock = (int)($_POST['stok_baru'] ?? 0);
        $alasan   = clean($_POST['alasan'] ?? 'Penyesuaian stok opname');

        $res = Produk::adjustStok($id, $newStock, $alasan);
        if ($res['success']) {
            Session::setFlash('success', $res['message']);
        } else {
            Session::setFlash('danger', $res['message']);
        }
        header('Location: index.php?page=produk');
        exit;
    }

    // Hapus atau Nonaktifkan Produk
    if ($postAction === 'delete') {
        $id = (int)($_POST['id_produk'] ?? 0);
        $res = Produk::deleteOrDeactivate($id);
        Session::setFlash($res['success'] ? 'success' : 'danger', $res['message']);
        header('Location: index.php?page=produk');
        exit;
    }
}

// ====================================================================
// 2. FORM PENYESUAIAN STOK
// ====================================================================
if ($action === 'adjust_stock') {
    $id = (int)($_GET['id'] ?? 0);
    $produk = Produk::getById($id);

    if (!$produk) {
        Session::setFlash('danger', 'Produk tidak ditemukan.');
        header('Location: index.php?page=produk');
        exit;
    }

    $pageTitle = 'Penyesuaian Stok Produk';
    require_once dirname(dirname(__DIR__)) . '/templates/header.php';
    ?>
    <div style="max-width: 500px; margin: 0 auto;">
        <div class="card">
            <h3 style="margin-top: 0;">Penyesuaian Stok Produk</h3>
            <p style="font-size: 13px; color: #6c757d;">Produk: <strong><?= e($produk['nama_produk']) ?></strong> (<?= e($produk['kode_produk']) ?>)</p>
            
            <form method="POST" action="index.php?page=produk">
                <?= csrf_field() ?>
                <input type="hidden" name="post_action" value="adjust_stock">
                <input type="hidden" name="id_produk" value="<?= e($produk['id_produk']) ?>">

                <div class="form-group">
                    <label>Stok Saat Ini</label>
                    <input type="text" class="form-control" value="<?= e($produk['stok']) ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="stok_baru">Stok Baru yang Seharusnya *</label>
                    <input type="number" id="stok_baru" name="stok_baru" class="form-control" 
                           min="0" value="<?= e($produk['stok']) ?>" required autofocus>
                </div>

                <div class="form-group">
                    <label for="alasan">Alasan Penyesuaian</label>
                    <input type="text" id="alasan" name="alasan" class="form-control" 
                           placeholder="Contoh: Stok opname, barang rusak, restock tambahan">
                </div>

                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">Simpan Stok Baru</button>
                    <a href="index.php?page=produk" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>
    </div>
    <?php
    require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
    exit;
}

// ====================================================================
// 3. FORM TAMBAH / UBAH PRODUK
// ====================================================================
if ($action === 'create' || $action === 'edit') {
    $isEdit = ($action === 'edit');
    $id = (int)($_GET['id'] ?? 0);
    $produk = $isEdit ? Produk::getById($id) : null;
    $kategoriList = Kategori::getAll();

    if ($isEdit && !$produk) {
        Session::setFlash('danger', 'Produk tidak ditemukan.');
        header('Location: index.php?page=produk');
        exit;
    }

    $defaultKode = $isEdit ? $produk['kode_produk'] : Produk::generateKode();
    $pageTitle = $isEdit ? 'Ubah Data Produk' : 'Tambah Produk Baru';
    require_once dirname(dirname(__DIR__)) . '/templates/header.php';
    ?>
    <div style="max-width: 650px; margin: 0 auto;">
        <div class="card">
            <h3 style="margin-top: 0;"><?= $isEdit ? 'Ubah Produk' : 'Tambah Produk Baru' ?></h3>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="index.php?page=produk">
                <?= csrf_field() ?>
                <input type="hidden" name="post_action" value="<?= $isEdit ? 'edit' : 'create' ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id_produk" value="<?= e($produk['id_produk']) ?>">
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="kode_produk">Kode Produk *</label>
                        <input type="text" id="kode_produk" name="kode_produk" class="form-control" 
                               value="<?= e($produk['kode_produk'] ?? $defaultKode) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="barcode_pabrik">Barcode Pabrik (EAN/UPC)</label>
                        <input type="text" id="barcode_pabrik" name="barcode_pabrik" class="form-control" 
                               value="<?= e($produk['barcode_pabrik'] ?? '') ?>" placeholder="Scan atau ketik barcode kemasan">
                    </div>
                </div>

                <div class="form-group">
                    <label for="nama_produk">Nama Produk *</label>
                    <input type="text" id="nama_produk" name="nama_produk" class="form-control" 
                           value="<?= e($produk['nama_produk'] ?? '') ?>" required autofocus>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="id_kategori">Kategori</label>
                        <select id="id_kategori" name="id_kategori" class="form-control">
                            <option value="">-- Tanpa Kategori --</option>
                            <?php foreach ($kategoriList as $k): ?>
                                <option value="<?= e($k['id_kategori']) ?>" 
                                    <?= ($produk['id_kategori'] ?? '') == $k['id_kategori'] ? 'selected' : '' ?>>
                                    <?= e($k['nama_kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">Status Produk</label>
                        <select id="status" name="status" class="form-control">
                            <option value="aktif" <?= ($produk['status'] ?? 'aktif') === 'aktif' ? 'selected' : '' ?>>Aktif (Tersedia untuk Dijual)</option>
                            <option value="nonaktif" <?= ($produk['status'] ?? '') === 'nonaktif' ? 'selected' : '' ?>>Nonaktif (Tidak Dijual)</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="harga_beli">Harga Beli Pabrik (Rp)</label>
                        <input type="number" id="harga_beli" name="harga_beli" class="form-control" 
                               min="0" step="100" value="<?= e($produk['harga_beli'] ?? 0) ?>">
                    </div>

                    <div class="form-group">
                        <label for="harga">Harga Jual (Rp) *</label>
                        <input type="number" id="harga" name="harga" class="form-control" 
                               min="0" step="100" value="<?= e($produk['harga'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="stok">Stok Awal *</label>
                        <input type="number" id="stok" name="stok" class="form-control" 
                               min="0" value="<?= e($produk['stok'] ?? 0) ?>" required>
                    </div>
                </div>

                <?php if ($isEdit): ?>
                    <p style="font-size: 12px; color: #6c757d; background: #f8f9fa; padding: 10px; border-radius: 4px;">
                        <em>Catatan Keuangan:</em> Perubahan harga jual saat ini hanya berlaku untuk transaksi baru ke depan. Transaksi masa lalu tetap tersimpan utuh sesuai nominal saat transaksi berlangsung.
                    </p>
                <?php endif; ?>

                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan Perubahan' : 'Tambah Produk' ?></button>
                    <a href="index.php?page=produk" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>
    </div>
    <?php
    require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
    exit;
}

// ====================================================================
// 4. DAFTAR PRODUK (DEFAULT)
// ====================================================================
$keyword     = clean($_GET['q'] ?? '');
$kategoriId  = !empty($_GET['kategori_id']) ? (int)$_GET['kategori_id'] : null;
$status      = clean($_GET['status'] ?? '');

$produkList   = Produk::getAll($keyword, $kategoriId, $status);
$kategoriList = Kategori::getAll();

$pageTitle = 'Manajemen Produk';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="margin: 0;">Manajemen Produk & Stok</h2>
        <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">Kelola katalog produk, harga jual, stok gudang, dan pengelompokan kategori.</p>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="index.php?page=scan_pabrik" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/></svg>
            <span>Scan Produk Pabrik</span>
        </a>
        <a href="index.php?page=produk&action=create" class="btn btn-primary">+ Tambah Produk</a>
    </div>
</div>

<div class="card">
    <!-- Form Pencarian & Filter -->
    <form method="GET" action="index.php" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
        <input type="hidden" name="page" value="produk">

        <input type="text" name="q" class="form-control" style="max-width: 240px;" 
               placeholder="Cari kode atau nama..." value="<?= e($keyword) ?>">

        <select name="kategori_id" class="form-control" style="max-width: 200px;">
            <option value="">-- Semua Kategori --</option>
            <?php foreach ($kategoriList as $k): ?>
                <option value="<?= e($k['id_kategori']) ?>" <?= $kategoriId == $k['id_kategori'] ? 'selected' : '' ?>>
                    <?= e($k['nama_kategori']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="form-control" style="max-width: 160px;">
            <option value="">-- Semua Status --</option>
            <option value="aktif" <?= $status === 'aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="nonaktif" <?= $status === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
        </select>

        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if (!empty($keyword) || !empty($kategoriId) || !empty($status)): ?>
            <a href="index.php?page=produk" class="btn btn-secondary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="product-grid" aria-label="Daftar produk dalam bentuk kartu">
        <?php if (empty($produkList)): ?>
            <div class="product-empty">Tidak ada produk untuk ditampilkan.</div>
        <?php else: ?>
            <?php foreach ($produkList as $p): ?>
                <?php
                    $stok = (int)$p['stok'];
                    $stockClass = $stok <= 0 ? 'stock-critical' : ($stok <= 5 ? 'stock-low' : 'stock-ok');
                    $stockLabel = $stok <= 0 ? 'Habis' : ($stok <= 5 ? 'Rendah' : 'Aman');
                ?>
                <article class="product-card" tabindex="0" role="button" aria-label="Produk <?= e($p['nama_produk']) ?>, harga <?= format_rupiah($p['harga']) ?>, stok <?= $stok ?> unit" data-id="<?= e($p['id_produk']) ?>">
                    <button type="button" class="quick-add" title="Tambah ke keranjang" aria-label="Tambah <?= e($p['nama_produk']) ?> ke keranjang" data-id="<?= (int)$p['id_produk'] ?>" onclick="ToKo.addToCart(<?= (int)$p['id_produk'] ?>)">+</button>
                    <div class="product-card-top">
                        <span class="product-icon" aria-hidden="true">📦</span>
                        <span class="badge <?= $p['status'] === 'aktif' ? 'badge-success' : 'badge-danger' ?>">
                            <?= $p['status'] === 'aktif' ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </div>
                    <div class="product-card-code">
                        <?= e($p['kode_produk']) ?>
                        <?php if (!empty($p['barcode_pabrik'])): ?>
                            <span style="font-size: 11px; color: #64748b; margin-left: 4px;" title="Barcode Pabrik">| 🏭 <?= e($p['barcode_pabrik']) ?></span>
                        <?php endif; ?>
                    </div>
                    <h3><?= e($p['nama_produk']) ?></h3>
                    <p class="product-category"><?= e($p['nama_kategori'] ?? 'Tanpa kategori') ?></p>
                    <div class="product-card-meta">
                        <strong><?= format_rupiah($p['harga']) ?></strong>
                        <span class="stock-pill <?= $stockClass ?>"><?= $stockLabel ?> <?= $stok ?></span>
                    </div>
                    <div class="product-card-actions">
                        <a href="index.php?page=produk&action=adjust_stock&id=<?= e($p['id_produk']) ?>" class="btn btn-secondary btn-sm">Stok</a>
                        <a href="index.php?page=produk&action=edit&id=<?= e($p['id_produk']) ?>" class="btn btn-primary btn-sm">Ubah</a>
                        <?php if ($p['status'] === 'aktif'): ?>
                            <form method="POST" action="index.php?page=produk" style="display: inline;" onsubmit="return ToKo.confirmDelete(this, '<?= e($p['nama_produk']) ?>');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="post_action" value="delete">
                                <input type="hidden" name="id_produk" value="<?= e($p['id_produk']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Nonaktifkan <?= e($p['nama_produk']) ?>">Hapus</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 100px;">Kode</th>
                    <th>Nama Produk</th>
                    <th>Kategori</th>
                    <th style="width: 140px;">Harga Jual</th>
                    <th style="width: 100px; text-align: center;">Stok</th>
                    <th style="width: 110px; text-align: center;">Status</th>
                    <th style="width: 240px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($produkList)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            <?= (!empty($keyword) || !empty($kategoriId) || !empty($status)) ? 'Tidak ada produk yang cocok dengan penyaringan.' : 'Belum ada data produk.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($produkList as $p): ?>
                        <tr>
                            <td><code><?= e($p['kode_produk']) ?></code></td>
                            <td><strong><?= e($p['nama_produk']) ?></strong></td>
                            <td><?= e($p['nama_kategori'] ?? '<span style="color: var(--text-muted);">-</span>') ?></td>
                            <td><strong><?= format_rupiah($p['harga']) ?></strong></td>
                            <td style="text-align: center;">
                                <?php if ((int)$p['stok'] <= 5): ?>
                                    <span class="badge badge-danger"><?= (int)$p['stok'] ?> unit</span>
                                <?php else: ?>
                                    <span class="badge badge-success"><?= (int)$p['stok'] ?> unit</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($p['status'] === 'aktif'): ?>
                                    <span class="badge badge-success">Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <a href="index.php?page=produk&action=adjust_stock&id=<?= e($p['id_produk']) ?>" 
                                   class="btn btn-secondary btn-sm">Stok</a>
                                <a href="index.php?page=produk&action=edit&id=<?= e($p['id_produk']) ?>" 
                                   class="btn btn-primary btn-sm">Ubah</a>
                                <form method="POST" action="index.php?page=produk" style="display: inline;" 
                                      onsubmit="return confirm('Apakah Anda yakin ingin menghapus/menonaktifkan produk <?= e($p['nama_produk']) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="post_action" value="delete">
                                    <input type="hidden" name="id_produk" value="<?= e($p['id_produk']) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
