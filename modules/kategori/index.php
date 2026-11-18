<?php
/**
 * Modul Manajemen Kategori
 * Mendukung penambahan, pengubahan, penghapusan dengan validasi relasi produk
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/Kategori.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';

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
        header('Location: index.php?page=kategori');
        exit;
    }

    $postAction = $_POST['post_action'] ?? '';

    // Tambah Kategori
    if ($postAction === 'create') {
        $nama = clean($_POST['nama_kategori'] ?? '');
        $deskripsi = clean($_POST['deskripsi'] ?? '');

        if (empty($nama)) {
            $error = 'Nama kategori wajib diisi.';
        } else {
            Kategori::create($nama, $deskripsi);
            Session::setFlash('success', "Kategori '{$nama}' berhasil ditambahkan.");
            header('Location: index.php?page=kategori');
            exit;
        }
    }

    // Ubah Kategori
    if ($postAction === 'edit') {
        $id = (int)($_POST['id_kategori'] ?? 0);
        $nama = clean($_POST['nama_kategori'] ?? '');
        $deskripsi = clean($_POST['deskripsi'] ?? '');

        if ($id <= 0 || empty($nama)) {
            $error = 'Nama kategori wajib diisi.';
        } else {
            Kategori::update($id, $nama, $deskripsi);
            Session::setFlash('success', "Kategori '{$nama}' berhasil diperbarui.");
            header('Location: index.php?page=kategori');
            exit;
        }
    }

    // Hapus Kategori
    if ($postAction === 'delete') {
        $id = (int)($_POST['id_kategori'] ?? 0);
        $result = Kategori::delete($id);
        if ($result['success']) {
            Session::setFlash('success', $result['message']);
        } else {
            Session::setFlash('danger', $result['message']);
        }
        header('Location: index.php?page=kategori');
        exit;
    }
}

// ====================================================================
// 2. FORM TAMBAH / UBAH KATEGORI
// ====================================================================
if ($action === 'create' || $action === 'edit') {
    $isEdit = ($action === 'edit');
    $id = (int)($_GET['id'] ?? 0);
    $kategori = $isEdit ? Kategori::getById($id) : null;

    if ($isEdit && !$kategori) {
        Session::setFlash('danger', 'Kategori tidak ditemukan.');
        header('Location: index.php?page=kategori');
        exit;
    }

    $pageTitle = $isEdit ? 'Ubah Kategori' : 'Tambah Kategori Baru';
    require_once dirname(dirname(__DIR__)) . '/templates/header.php';
    ?>
    <div style="max-width: 600px; margin: 0 auto;">
        <div class="card">
            <h3 style="margin-top: 0;"><?= $isEdit ? 'Ubah Kategori' : 'Tambah Kategori Baru' ?></h3>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="index.php?page=kategori">
                <?= csrf_field() ?>
                <input type="hidden" name="post_action" value="<?= $isEdit ? 'edit' : 'create' ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id_kategori" value="<?= e($kategori['id_kategori']) ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="nama_kategori">Nama Kategori *</label>
                    <input type="text" id="nama_kategori" name="nama_kategori" class="form-control" 
                           value="<?= e($kategori['nama_kategori'] ?? '') ?>" required autofocus>
                </div>

                <div class="form-group">
                    <label for="deskripsi">Deskripsi</label>
                    <textarea id="deskripsi" name="deskripsi" class="form-control" rows="3" 
                              placeholder="Keterangan singkat kategori..."><?= e($kategori['deskripsi'] ?? '') ?></textarea>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan Perubahan' : 'Tambah Kategori' ?></button>
                    <a href="index.php?page=kategori" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>
    </div>
    <?php
    require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
    exit;
}

// ====================================================================
// 3. DAFTAR KATEGORI (DEFAULT)
// ====================================================================
$keyword = clean($_GET['q'] ?? '');
$kategoriList = Kategori::getAll($keyword);

$pageTitle = 'Manajemen Kategori';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="margin: 0;">Manajemen Kategori</h2>
        <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">Kelola pengelompokan produk dengan pemeriksaan relasi keamanan.</p>
    </div>
    <div>
        <a href="index.php?page=kategori&action=create" class="btn btn-primary">+ Tambah Kategori</a>
    </div>
</div>

<div class="card">
    <form method="GET" action="index.php" style="display: flex; gap: 10px; margin-bottom: 15px;">
        <input type="hidden" name="page" value="kategori">
        <input type="text" name="q" class="form-control" style="max-width: 300px;" 
               placeholder="Cari kategori..." value="<?= e($keyword) ?>">
        <button type="submit" class="btn btn-secondary">Cari</button>
        <?php if (!empty($keyword)): ?>
            <a href="index.php?page=kategori" class="btn btn-secondary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th>Nama Kategori</th>
                    <th>Deskripsi</th>
                    <th style="width: 140px; text-align: center;">Jumlah Produk</th>
                    <th style="width: 240px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($kategoriList)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            <?= !empty($keyword) ? 'Tidak ada kategori yang cocok.' : 'Belum ada data kategori.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($kategoriList as $k): ?>
                        <tr>
                            <td><strong>#<?= e($k['id_kategori']) ?></strong></td>
                            <td><strong><?= e($k['nama_kategori']) ?></strong></td>
                            <td><?= e($k['deskripsi'] ?: '<span style="color: var(--text-muted);">-</span>') ?></td>
                            <td style="text-align: center;">
                                <span class="badge badge-info">
                                    <?= (int)$k['total_produk'] ?> produk
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <a href="index.php?page=produk&kategori_id=<?= e($k['id_kategori']) ?>" 
                                   class="btn btn-secondary btn-sm">Lihat Produk</a>
                                <a href="index.php?page=kategori&action=edit&id=<?= e($k['id_kategori']) ?>" 
                                   class="btn btn-primary btn-sm">Ubah</a>
                                <form method="POST" action="index.php?page=kategori" style="display: inline;" 
                                      onsubmit="return confirm('Apakah Anda yakin ingin menghapus kategori <?= e($k['nama_kategori']) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="post_action" value="delete">
                                    <input type="hidden" name="id_kategori" value="<?= e($k['id_kategori']) ?>">
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
