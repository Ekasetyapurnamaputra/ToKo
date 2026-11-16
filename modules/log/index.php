<?php
/**
 * Modul Audit Trail & Log Aktivitas
 * Menyajikan rekaman audit aktivitas sistem dan inspeksi trigger database
 * Khusus diakses oleh pengguna dengan hak akses role: ADMIN
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/Log.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';

// Hak Akses Ketat: Hanya Admin
Auth::requireRole('admin');

$tab        = $_GET['tab'] ?? 'sistem';
$tipeFilter = clean($_GET['tipe'] ?? '');
$keyword    = clean($_GET['q'] ?? '');
$tglMulai   = clean($_GET['tgl_mulai'] ?? '');
$tglSelesai = clean($_GET['tgl_selesai'] ?? '');

$statistik = Log::getStatistik();
$tipeList  = Log::getTipeList();

$pageTitle = 'Audit Trail & Log Aktivitas';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="margin: 0;">Audit Trail & Log Aktivitas Sistem</h2>
        <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">Pemeriksaan riwayat audit aktivitas aplikasi dan log pemicu otomatis (*Database Trigger*).</p>
    </div>
    <div class="no-print">
        <button onclick="window.print()" class="btn btn-secondary">&#128438; Cetak Log</button>
    </div>
</div>

<!-- Kartu Ringkasan Metrik Log -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px;">
    <div class="card" style="margin-bottom: 0; border-left: 4px solid #212529;">
        <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; font-weight: bold;">Total Aktivitas</div>
        <div style="font-size: 22px; font-weight: bold; margin-top: 5px; color: #212529;"><?= format_angka($statistik['total_log']) ?></div>
    </div>
    <div class="card" style="margin-bottom: 0; border-left: 4px solid #0d6efd;">
        <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; font-weight: bold;">Log Transaksi</div>
        <div style="font-size: 22px; font-weight: bold; margin-top: 5px; color: #0d6efd;"><?= format_angka($statistik['total_transaksi']) ?></div>
    </div>
    <div class="card" style="margin-bottom: 0; border-left: 4px solid #198754;">
        <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; font-weight: bold;">Log Otentikasi</div>
        <div style="font-size: 22px; font-weight: bold; margin-top: 5px; color: #198754;"><?= format_angka($statistik['total_login']) ?></div>
    </div>
    <div class="card" style="margin-bottom: 0; border-left: 4px solid #ffc107;">
        <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; font-weight: bold;">Log Master Produk</div>
        <div style="font-size: 22px; font-weight: bold; margin-top: 5px; color: #b78103;"><?= format_angka($statistik['total_produk']) ?></div>
    </div>
    <div class="card" style="margin-bottom: 0; border-left: 4px solid #0dcaf0;">
        <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; font-weight: bold;">Log Pelanggan</div>
        <div style="font-size: 22px; font-weight: bold; margin-top: 5px; color: #055160;"><?= format_angka($statistik['total_pelanggan']) ?></div>
    </div>
</div>

<!-- Navigasi Tab -->
<div class="no-print" style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px;">
    <a href="index.php?page=log&tab=sistem" class="btn <?= $tab === 'sistem' ? 'btn-primary' : 'btn-secondary' ?>" style="border-radius: var(--radius-pill);">
        Log Aktivitas Sistem (tbl_log)
    </a>
    <a href="index.php?page=log&tab=trigger" class="btn <?= $tab === 'trigger' ? 'btn-primary' : 'btn-secondary' ?>" style="border-radius: var(--radius-pill);">
        Audit Trigger Database (log_pelanggan & perubahannya)
    </a>
</div>

<?php
// ====================================================================
// TAB 1: LOG AKTIVITAS SISTEM TERPADU (tbl_log)
// ====================================================================
if ($tab === 'sistem'):
    $logs = Log::getLogs($tipeFilter, $keyword, $tglMulai, $tglSelesai, 100);
?>
    <div class="card">
        <!-- Form Filter -->
        <form method="GET" action="index.php" class="no-print" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; align-items: flex-end;">
            <input type="hidden" name="page" value="log">
            <input type="hidden" name="tab" value="sistem">

            <div>
                <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px;">Tipe Aktivitas</label>
                <select name="tipe" class="form-control" style="width: 180px;">
                    <option value="">-- Semua Tipe --</option>
                    <?php foreach ($tipeList as $t): ?>
                        <option value="<?= e($t) ?>" <?= $tipeFilter === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px;">Kata Kunci</label>
                <input type="text" name="q" class="form-control" style="width: 200px;" 
                       placeholder="Cari deskripsi, user, IP..." value="<?= e($keyword) ?>">
            </div>

            <div>
                <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px;">Dari Tanggal</label>
                <input type="date" name="tgl_mulai" class="form-control" value="<?= e($tglMulai) ?>">
            </div>

            <div>
                <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px;">Sampai Tanggal</label>
                <input type="date" name="tgl_selesai" class="form-control" value="<?= e($tglSelesai) ?>">
            </div>

            <div>
                <button type="submit" class="btn btn-secondary">Filter</button>
                <?php if (!empty($tipeFilter) || !empty($keyword) || !empty($tglMulai) || !empty($tglSelesai)): ?>
                    <a href="index.php?page=log&tab=sistem" class="btn btn-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <h3 style="margin-top: 0; font-size: 16px;">
            Rekaman Audit Aktivitas (Menampilkan <?= count($logs) ?> rekaman terbaru)
        </h3>

        <div class="table-responsive">
        <table class="table" style="font-size: 13px;">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th style="width: 150px;">Waktu Kejadian</th>
                    <th style="width: 160px;">Tipe Aktivitas</th>
                    <th>Keterangan / Detail Aktivitas</th>
                    <th style="width: 160px;">Pengguna (Aktor)</th>
                    <th style="width: 110px;">IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="6" style="text-align: center; color: #6c757d; padding: 25px;">Tidak ada rekaman log yang cocok.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): 
                        $tipe = $log['tipe_aktivitas'];
                        $badgeColor = '#6c757d';
                        if (str_starts_with($tipe, 'TRANSAKSI_SUKSES')) $badgeColor = '#198754';
                        elseif (str_starts_with($tipe, 'TRANSAKSI_GAGAL')) $badgeColor = '#dc3545';
                        elseif (str_starts_with($tipe, 'LOGIN_GAGAL')) $badgeColor = '#dc3545';
                        elseif (str_starts_with($tipe, 'LOGIN')) $badgeColor = '#0d6efd';
                        elseif (str_starts_with($tipe, 'PRODUK')) $badgeColor = '#b78103';
                        elseif (str_starts_with($tipe, 'PELANGGAN')) $badgeColor = '#055160';
                        elseif (str_starts_with($tipe, 'KATEGORI')) $badgeColor = '#6610f2';
                    ?>
                        <tr>
                            <td>#<?= e($log['id_log']) ?></td>
                            <td><?= format_tanggal($log['waktu'], true) ?></td>
                            <td>
                                <span class="badge" style="background: <?= $badgeColor ?>; color: #fff; font-size: 11px;">
                                    <?= e($tipe) ?>
                                </span>
                            </td>
                            <td><?= e($log['deskripsi']) ?></td>
                            <td>
                                <?php if (!empty($log['username'])): ?>
                                    <strong><?= e($log['nama_lengkap']) ?></strong><br>
                                    <small style="color: #6c757d;">@<?= e($log['username']) ?> (<?= e($log['role']) ?>)</small>
                                <?php else: ?>
                                    <span style="color: #6c757d;">Sistem / Tamu</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= e($log['ip_address'] ?? '127.0.0.1') ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php
// ====================================================================
// TAB 2: AUDIT TRIGGER DATABASE
// ====================================================================
elseif ($tab === 'trigger'):
    $triggerInserts = Log::getTriggerInsertPelanggan();
    $triggerUpdates = Log::getTriggerPerubahanPelanggan();
?>
    <div class="card" style="background: #e7f1ff; border: 1px solid #b6d4fe; margin-bottom: 20px;">
        <h4 style="margin: 0 0 5px 0; color: #084298;">Mekanisme Audit Database Trigger</h4>
        <p style="margin: 0; font-size: 13px; color: #055160;">
            Data di bawah ini dicatat secara otomatis oleh MariaDB/MySQL engine melalui 
            <strong>Trigger <code>after_insert_pelanggan</code></strong> dan 
            <strong>Trigger <code>after_update_pelanggan</code></strong>. 
            Audit ini berjalan langsung pada level database sehingga menjamin integritas data audit meskipun perubahan dilakukan dari luar aplikasi PHP.
        </p>
    </div>

    <!-- Riwayat Perubahan Nama (after_update_pelanggan) -->
    <div class="card">
        <h3 style="margin-top: 0; font-size: 16px;">
            1. Riwayat Perubahan Nama Pelanggan (Tabel <code>log_perubahan_pelanggan</code> via Trigger <code>after_update_pelanggan</code>)
        </h3>
        <div class="table-responsive">
        <table class="table" style="font-size: 13px;">
            <thead>
                <tr>
                    <th style="width: 60px;">ID Log</th>
                    <th style="width: 150px;">Waktu Perubahan</th>
                    <th style="width: 110px;">ID Pelanggan</th>
                    <th>Nama Sebelum Perubahan (OLD)</th>
                    <th>Nama Setelah Perubahan (NEW)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($triggerUpdates)): ?>
                    <tr><td colspan="5" style="text-align: center; color: #6c757d; padding: 20px;">Belum ada riwayat perubahan nama pelanggan.</td></tr>
                <?php else: ?>
                    <?php foreach ($triggerUpdates as $u): ?>
                        <tr>
                            <td>#<?= e($u['id_log']) ?></td>
                            <td><?= format_tanggal($u['waktu'], true) ?></td>
                            <td>#<?= e($u['id_pelanggan']) ?></td>
                            <td><span style="color: #dc3545; text-decoration: line-through;"><?= e($u['nama_lama']) ?></span></td>
                            <td><strong style="color: #198754;"><?= e($u['nama_baru']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Riwayat Penambahan Pelanggan (after_insert_pelanggan) -->
    <div class="card">
        <h3 style="margin-top: 0; font-size: 16px;">
            2. Riwayat Penambahan Pelanggan (Tabel <code>log_pelanggan</code> via Trigger <code>after_insert_pelanggan</code>)
        </h3>
        <div class="table-responsive">
        <table class="table" style="font-size: 13px;">
            <thead>
                <tr>
                    <th style="width: 60px;">ID Log</th>
                    <th style="width: 150px;">Waktu Eksekusi</th>
                    <th style="width: 160px;">Aktivitas Trigger</th>
                    <th style="width: 110px;">ID Pelanggan</th>
                    <th>Nama Pelanggan</th>
                    <th>Kota</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($triggerInserts)): ?>
                    <tr><td colspan="6" style="text-align: center; color: #6c757d; padding: 20px;">Belum ada riwayat penambahan pelanggan.</td></tr>
                <?php else: ?>
                    <?php foreach ($triggerInserts as $i): ?>
                        <tr>
                            <td>#<?= e($i['id_log']) ?></td>
                            <td><?= format_tanggal($i['waktu'], true) ?></td>
                            <td><span class="badge" style="background: #198754; color: #fff;"><?= e($i['aktivitas']) ?></span></td>
                            <td>#<?= e($i['id_pelanggan']) ?></td>
                            <td><strong><?= e($i['nama_pelanggan'] ?? 'Pelanggan #' . $i['id_pelanggan']) ?></strong></td>
                            <td><?= e($i['kota'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php endif; ?>

<style>
    @media print {
        .navbar, .no-print, footer { display: none !important; }
        body { background: #fff !important; }
        .main-container { margin: 0; padding: 0; max-width: 100%; }
        .card { border: 0 !important; box-shadow: none !important; padding: 0 !important; }
    }
</style>

<?php
require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
