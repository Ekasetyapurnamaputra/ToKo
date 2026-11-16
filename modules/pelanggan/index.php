<?php
/**
 * Modul Manajemen Pelanggan
 * Mendukung CRUD, pencarian, riwayat transaksi, pemanggilan Stored Procedure & integrasi Trigger
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/Pelanggan.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';

Auth::requireLogin();

$action = $_GET['action'] ?? 'list';
$error = '';

// ====================================================================
// 1. PROSES AKSI (POST)
// ====================================================================
if (is_post()) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        Session::setFlash('danger', 'Validasi keamanan (CSRF Token) gagal.');
        header('Location: index.php?page=pelanggan');
        exit;
    }

    $postAction = $_POST['post_action'] ?? '';

    // Tambah Pelanggan
    if ($postAction === 'create') {
        $nama = clean($_POST['nama_pelanggan'] ?? '');
        $kota = clean($_POST['kota'] ?? '');

        if (empty($nama) || empty($kota)) {
            $error = 'Nama pelanggan dan kota wajib diisi.';
        } else {
            // Memanggil Stored Procedure TambahPelanggan (dan memicu trigger after_insert_pelanggan)
            Pelanggan::create($nama, $kota);
            Session::setFlash('success', "Pelanggan '{$nama}' berhasil ditambahkan (dicatat ke log_pelanggan oleh Trigger database).");
            header('Location: index.php?page=pelanggan');
            exit;
        }
    }

    // Ubah Pelanggan
    if ($postAction === 'edit') {
        $id   = (int)($_POST['id_pelanggan'] ?? 0);
        $nama = clean($_POST['nama_pelanggan'] ?? '');
        $kota = clean($_POST['kota'] ?? '');

        if ($id <= 0 || empty($nama) || empty($kota)) {
            $error = 'Data yang dikirim tidak lengkap.';
        } else {
            // Memperbarui pelanggan (memicu trigger after_update_pelanggan ke log_perubahan_pelanggan)
            Pelanggan::update($id, $nama, $kota);
            Session::setFlash('success', "Data pelanggan ID #{$id} berhasil diperbarui (perubahan dicatat oleh Trigger database).");
            header('Location: index.php?page=pelanggan');
            exit;
        }
    }

    // Hapus Pelanggan
    if ($postAction === 'delete') {
        $id = (int)($_POST['id_pelanggan'] ?? 0);
        $result = Pelanggan::delete($id);
        if ($result['success']) {
            Session::setFlash('success', $result['message']);
        } else {
            Session::setFlash('danger', $result['message']);
        }
        header('Location: index.php?page=pelanggan');
        exit;
    }
}

// ====================================================================
// 2. TAMPILAN SESUAI ACTION
// ====================================================================

// A. Riwayat Transaksi Pelanggan (Memanfaatkan Stored Procedure LaporanTransaksi)
if ($action === 'riwayat') {
    $id = (int)($_GET['id'] ?? 0);
    $pelanggan = Pelanggan::getById($id);
    if (!$pelanggan) {
        Session::setFlash('danger', 'Pelanggan tidak ditemukan.');
        header('Location: index.php?page=pelanggan');
        exit;
    }

    $riwayat = Pelanggan::getRiwayatTransaksi($id);
    $pageTitle = 'Riwayat Transaksi - ' . $pelanggan['nama_pelanggan'];
    require_once dirname(dirname(__DIR__)) . '/templates/header.php';
    ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0;">Riwayat Belanja: <?= e($pelanggan['nama_pelanggan']) ?></h2>
            <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">Kota: <strong><?= e($pelanggan['kota']) ?></strong> | Data ditarik melalui Stored Procedure <code>LaporanTransaksi(:id)</code></p>
        </div>
        <div>
            <a href="index.php?page=pelanggan" class="btn btn-secondary">&laquo; Kembali ke Daftar Pelanggan</a>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 50px;">No.</th>
                        <th style="width: 140px;">ID Transaksi</th>
                        <th>Tanggal Belanja</th>
                        <th style="text-align: right;">Total Belanja</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($riwayat)): ?>
                        <tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 25px;">Pelanggan ini belum pernah melakukan transaksi.</td></tr>
                    <?php else: ?>
                        <?php $no = 1; $grandTotal = 0; foreach ($riwayat as $trx): $grandTotal += (float)$trx['total_belanja']; ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><strong>#<?= e($trx['id_transaksi']) ?></strong></td>
                                <td><?= format_tanggal($trx['tanggal']) ?></td>
                                <td style="text-align: right;"><strong><?= format_rupiah($trx['total_belanja']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background-color: var(--background); font-weight: bold;">
                            <td colspan="3" style="text-align: right;">Total Akumulasi Pembelian:</td>
                            <td style="text-align: right; color: var(--success); font-size: 15px;"><?= format_rupiah($grandTotal) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
    exit;
}

// B. Form Tambah atau Edit Pelanggan
if ($action === 'create' || $action === 'edit') {
    $isEdit = ($action === 'edit');
    $id = (int)($_GET['id'] ?? 0);
    $pelanggan = $isEdit ? Pelanggan::getById($id) : null;

    if ($isEdit && !$pelanggan) {
        Session::setFlash('danger', 'Pelanggan tidak ditemukan.');
        header('Location: index.php?page=pelanggan');
        exit;
    }

    $pageTitle = $isEdit ? 'Ubah Data Pelanggan' : 'Tambah Pelanggan Baru';
    require_once dirname(dirname(__DIR__)) . '/templates/header.php';
    ?>
    <div style="max-width: 600px; margin: 0 auto;">
        <div class="card">
            <h3 style="margin-top: 0;"><?= $isEdit ? 'Ubah Pelanggan' : 'Tambah Pelanggan Baru' ?></h3>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="index.php?page=pelanggan">
                <?= csrf_field() ?>
                <input type="hidden" name="post_action" value="<?= $isEdit ? 'edit' : 'create' ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id_pelanggan" value="<?= e($pelanggan['id_pelanggan']) ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="nama_pelanggan">Nama Pelanggan *</label>
                    <input type="text" id="nama_pelanggan" name="nama_pelanggan" class="form-control" 
                           value="<?= e($pelanggan['nama_pelanggan'] ?? '') ?>" required autofocus>
                </div>

                <div class="form-group">
                    <label for="kota">Kota / Asal *</label>
                    <input type="text" id="kota" name="kota" class="form-control" 
                           value="<?= e($pelanggan['kota'] ?? '') ?>" required placeholder="Contoh: Ponorogo, Madiun">
                </div>

                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan Perubahan' : 'Tambah Pelanggan' ?></button>
                    <a href="index.php?page=pelanggan" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>
    </div>
    <?php
    require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
    exit;
}

// C. Daftar Seluruh Pelanggan (Default List)
$keyword = clean($_GET['q'] ?? '');
$pelangganList = Pelanggan::getAll($keyword);

$pageTitle = 'Manajemen Pelanggan';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="margin: 0;">Manajemen Pelanggan</h2>
        <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">Daftar pelanggan terhubung dengan <code>view_total_belanja</code>, Stored Procedure, dan Trigger audit log.</p>
    </div>
    <div>
        <a href="index.php?page=pelanggan&action=create" class="btn btn-primary">+ Tambah Pelanggan</a>
    </div>
</div>

<div class="card">
    <!-- Form Pencarian -->
    <form method="GET" action="index.php" style="display: flex; gap: 10px; margin-bottom: 15px;">
        <input type="hidden" name="page" value="pelanggan">
        <input type="text" name="q" class="form-control" style="max-width: 300px;" 
               placeholder="Cari nama atau kota..." value="<?= e($keyword) ?>">
        <button type="submit" class="btn btn-secondary">Cari</button>
        <?php if (!empty($keyword)): ?>
            <a href="index.php?page=pelanggan" class="btn btn-secondary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th>Nama Pelanggan</th>
                    <th>Kota</th>
                    <th style="width: 140px; text-align: center;">Total Transaksi</th>
                    <th style="width: 170px; text-align: right;">Akumulasi Belanja</th>
                    <th style="width: 220px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pelangganList)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            <?= !empty($keyword) ? 'Tidak ada pelanggan yang cocok dengan kata kunci pencarian.' : 'Belum ada data pelanggan.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pelangganList as $p): ?>
                        <tr>
                            <td><strong>#<?= e($p['id_pelanggan']) ?></strong></td>
                            <td><strong><?= e($p['nama_pelanggan']) ?></strong></td>
                            <td><?= e($p['kota']) ?></td>
                            <td style="text-align: center;">
                                <?php if ((int)$p['total_transaksi'] > 0): ?>
                                    <span class="badge badge-info"><?= e($p['total_transaksi']) ?> transaksi</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">0 transaksi</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;"><strong><?= format_rupiah($p['total_belanja']) ?></strong></td>
                            <td style="text-align: center;">
                                <a href="index.php?page=pelanggan&action=riwayat&id=<?= e($p['id_pelanggan']) ?>" 
                                   class="btn btn-secondary btn-sm">Riwayat</a>
                                <a href="index.php?page=pelanggan&action=edit&id=<?= e($p['id_pelanggan']) ?>" 
                                   class="btn btn-primary btn-sm">Ubah</a>
                                <form method="POST" action="index.php?page=pelanggan" style="display: inline;" 
                                      onsubmit="return confirm('Apakah Anda yakin ingin menghapus pelanggan <?= e($p['nama_pelanggan']) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="post_action" value="delete">
                                    <input type="hidden" name="id_pelanggan" value="<?= e($p['id_pelanggan']) ?>">
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
