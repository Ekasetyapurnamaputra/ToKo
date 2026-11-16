<?php
/**
 * Modul Laporan Penjualan & Analisis
 * Menyajikan laporan analitik penjualan menggunakan VIEW, JOIN, dan SUBQUERY
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/Laporan.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';

Auth::requireLogin();

$tab = $_GET['tab'] ?? 'rentang';
$ringkasan = Laporan::getRingkasanPendapatan();

$pageTitle = 'Laporan Penjualan & Analitik';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="margin: 0;">Laporan Penjualan & Analitik Toko</h2>
        <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">Data laporan ditarik secara real-time memanfaatkan <strong>VIEW</strong>, <strong>JOIN</strong>, dan <strong>SUBQUERY</strong> database <code>db_tokokita</code>.</p>
    </div>
    <div class="no-print">
        <button onclick="window.print()" class="btn btn-secondary">&#128438; Cetak Laporan</button>
    </div>
</div>

<!-- Kartu KPI Ringkasan Pendapatan -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px;">
    <div class="card" style="margin-bottom: 0; border-left: 4px solid #0d6efd;">
        <div style="font-size: 12px; color: #6c757d; text-transform: uppercase; font-weight: bold;">Pendapatan Hari Ini</div>
        <div style="font-size: 20px; font-weight: bold; margin-top: 5px; color: #0d6efd;">
            <?= format_rupiah($ringkasan['total_hari_ini']) ?>
        </div>
        <small style="color: #6c757d; font-size: 11px;"><?= (int)$ringkasan['transaksi_hari_ini'] ?> transaksi tercatat hari ini</small>
    </div>

    <div class="card" style="margin-bottom: 0; border-left: 4px solid #198754;">
        <div style="font-size: 12px; color: #6c757d; text-transform: uppercase; font-weight: bold;">Pendapatan Bulan Ini (<?= date('F Y') ?>)</div>
        <div style="font-size: 20px; font-weight: bold; margin-top: 5px; color: #198754;">
            <?= format_rupiah($ringkasan['total_bulan_ini']) ?>
        </div>
        <small style="color: #6c757d; font-size: 11px;"><?= (int)$ringkasan['transaksi_bulan_ini'] ?> transaksi di bulan berjalan</small>
    </div>

    <div class="card" style="margin-bottom: 0; border-left: 4px solid #20c997;">
        <div style="font-size: 12px; color: #6c757d; text-transform: uppercase; font-weight: bold;">Total Akumulasi Pendapatan</div>
        <div style="font-size: 20px; font-weight: bold; margin-top: 5px; color: #0f5132;">
            <?= format_rupiah($ringkasan['total_semua']) ?>
        </div>
        <small style="color: #6c757d; font-size: 11px;">Dari total <?= (int)$ringkasan['total_transaksi_semua'] ?> transaksi sepanjang masa</small>
    </div>
</div>

<!-- Navigasi Tab Laporan -->
<div class="no-print" style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px;">
    <a href="index.php?page=laporan&tab=rentang" class="btn <?= $tab === 'rentang' ? 'btn-primary' : 'btn-secondary' ?>" style="border-radius: var(--radius-pill);">Rentang Tanggal</a>
    <a href="index.php?page=laporan&tab=harian" class="btn <?= $tab === 'harian' ? 'btn-primary' : 'btn-secondary' ?>" style="border-radius: var(--radius-pill);">Laporan Harian (VIEW)</a>
    <a href="index.php?page=laporan&tab=bulanan" class="btn <?= $tab === 'bulanan' ? 'btn-primary' : 'btn-secondary' ?>" style="border-radius: var(--radius-pill);">Laporan Bulanan (VIEW)</a>
    <a href="index.php?page=laporan&tab=produk" class="btn <?= $tab === 'produk' ? 'btn-primary' : 'btn-secondary' ?>" style="border-radius: var(--radius-pill);">Produk Terlaris (VIEW)</a>
    <a href="index.php?page=laporan&tab=pelanggan" class="btn <?= $tab === 'pelanggan' ? 'btn-primary' : 'btn-secondary' ?>" style="border-radius: var(--radius-pill);">Analisis Pelanggan (VIEW)</a>
</div>

<?php
// ====================================================================
// TAB 1: LAPORAN BERDASARKAN RENTANG TANGGAL
// ====================================================================
if ($tab === 'rentang'):
    $tglMulai   = clean($_GET['tgl_mulai'] ?? date('Y-m-01', strtotime('-1 month')));
    $tglSelesai = clean($_GET['tgl_selesai'] ?? date('Y-m-d'));

    $dataRentang = Laporan::getLaporanRentangTanggal($tglMulai, $tglSelesai);
    $sumSubtotal = 0; $sumDiskon = 0; $sumTotal = 0; $sumQty = 0;
?>
    <div class="card">
        <form method="GET" action="index.php" class="no-print" style="display: flex; gap: 10px; align-items: flex-end; margin-bottom: 20px;">
            <input type="hidden" name="page" value="laporan">
            <input type="hidden" name="tab" value="rentang">

            <div>
                <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px;">Dari Tanggal</label>
                <input type="date" name="tgl_mulai" class="form-control" value="<?= e($tglMulai) ?>" required>
            </div>

            <div>
                <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px;">Sampai Tanggal</label>
                <input type="date" name="tgl_selesai" class="form-control" value="<?= e($tglSelesai) ?>" required>
            </div>

            <button type="submit" class="btn btn-primary">Tampilkan Laporan</button>
        </form>

        <h3 style="margin-top: 0; font-size: 16px;">
            Rincian Penjualan Periode: <?= format_tanggal($tglMulai) ?> s/d <?= format_tanggal($tglSelesai) ?>
        </h3>

        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 70px;">ID</th>
                    <th style="width: 110px;">Tanggal</th>
                    <th>Nama Pelanggan</th>
                    <th>Kota</th>
                    <th style="width: 70px; text-align: center;">Qty</th>
                    <th style="width: 120px; text-align: right;">Subtotal</th>
                    <th style="width: 100px; text-align: right;">Diskon</th>
                    <th style="width: 130px; text-align: right;">Total Akhir</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($dataRentang)): ?>
                    <tr><td colspan="8" style="text-align: center; color: #6c757d; padding: 25px;">Tidak ada transaksi pada rentang tanggal yang dipilih.</td></tr>
                <?php else: ?>
                    <?php foreach ($dataRentang as $row): 
                        $sumSubtotal += (float)$row['subtotal'];
                        $sumDiskon   += (float)$row['nilai_diskon'];
                        $sumTotal    += (float)$row['total_belanja'];
                        $sumQty      += (int)$row['total_qty'];
                    ?>
                        <tr>
                            <td>#<?= e($row['id_transaksi']) ?></td>
                            <td><?= format_tanggal($row['tanggal']) ?></td>
                            <td><?= e($row['nama_pelanggan'] ?? 'Umum') ?></td>
                            <td><?= e($row['kota'] ?? '-') ?></td>
                            <td style="text-align: center;"><?= e($row['total_qty']) ?></td>
                            <td style="text-align: right;"><?= format_rupiah($row['subtotal'], false) ?></td>
                            <td style="text-align: right; color: #dc3545;"><?= (float)$row['nilai_diskon'] > 0 ? '- ' . format_rupiah($row['nilai_diskon'], false) : '-' ?></td>
                            <td style="text-align: right;"><strong><?= format_rupiah($row['total_belanja']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="background-color: #f8f9fa; font-weight: bold; border-top: 2px solid #212529;">
                        <td colspan="4" style="text-align: right;">TOTAL:</td>
                        <td style="text-align: center;"><?= format_angka($sumQty) ?></td>
                        <td style="text-align: right;"><?= format_rupiah($sumSubtotal) ?></td>
                        <td style="text-align: right; color: #dc3545;">- <?= format_rupiah($sumDiskon) ?></td>
                        <td style="text-align: right; color: #0f5132; font-size: 15px;"><?= format_rupiah($sumTotal) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php
// ====================================================================
// TAB 2: LAPORAN HARIAN (VIEW view_laporan_harian)
// ====================================================================
elseif ($tab === 'harian'):
    $dataHarian = Laporan::getLaporanHarian();
    $totalHarianNominal = 0;
?>
    <div class="card">
        <h3 style="margin-top: 0; font-size: 16px;">
            Rekapitulasi Penjualan Harian (Sumber Data: <code>view_laporan_harian</code>)
        </h3>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Tanggal Penjualan</th>
                    <th style="text-align: center;">Total Transaksi</th>
                    <th style="text-align: center;">Item Berbeda</th>
                    <th style="text-align: center;">Total Barang (Qty)</th>
                    <th style="text-align: right;">Subtotal Omzet</th>
                    <th style="text-align: right;">Total Diskon</th>
                    <th style="text-align: right;">Total Pendapatan Bersih</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($dataHarian)): ?>
                    <tr><td colspan="7" style="text-align: center; color: #6c757d; padding: 25px;">Belum ada data penjualan harian.</td></tr>
                <?php else: ?>
                    <?php foreach ($dataHarian as $h): $totalHarianNominal += (float)$h['total_pendapatan']; ?>
                        <tr>
                            <td><strong><?= format_tanggal($h['tanggal']) ?></strong></td>
                            <td style="text-align: center;"><span class="badge" style="background: #e2e3e5; color: #000;"><?= e($h['total_transaksi']) ?> trx</span></td>
                            <td style="text-align: center;"><?= e($h['total_item_terjual']) ?> varian</td>
                            <td style="text-align: center;"><strong><?= format_angka($h['total_qty_terjual']) ?></strong> pcs</td>
                            <td style="text-align: right;"><?= format_rupiah($h['subtotal_omzet'], false) ?></td>
                            <td style="text-align: right; color: #dc3545;"><?= (float)$h['total_diskon'] > 0 ? '- ' . format_rupiah($h['total_diskon'], false) : '-' ?></td>
                            <td style="text-align: right;"><strong><?= format_rupiah($h['total_pendapatan']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="background-color: #f8f9fa; font-weight: bold; border-top: 2px solid #212529;">
                        <td colspan="6" style="text-align: right;">TOTAL PENDAPATAN SEMUA HARI:</td>
                        <td style="text-align: right; color: #0f5132; font-size: 15px;"><?= format_rupiah($totalHarianNominal) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php
// ====================================================================
// TAB 3: LAPORAN BULANAN (VIEW view_laporan_bulanan)
// ====================================================================
elseif ($tab === 'bulanan'):
    $dataBulanan = Laporan::getLaporanBulanan();
?>
    <div class="card">
        <h3 style="margin-top: 0; font-size: 16px;">
            Rekapitulasi Penjualan Bulanan (Sumber Data: <code>view_laporan_bulanan</code>)
        </h3>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Periode Bulan</th>
                    <th style="text-align: center;">Jumlah Transaksi</th>
                    <th style="text-align: center;">Pelanggan Unik</th>
                    <th style="text-align: right;">Total Pendapatan</th>
                    <th style="text-align: right;">Rata-rata Nilai per Transaksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($dataBulanan)): ?>
                    <tr><td colspan="5" style="text-align: center; color: #6c757d; padding: 25px;">Belum ada data bulanan.</td></tr>
                <?php else: ?>
                    <?php foreach ($dataBulanan as $b): 
                        $dt = DateTime::createFromFormat('Y-m', $b['periode_bulan']);
                        $bulanNama = $dt ? $dt->format('F Y') : $b['periode_bulan'];
                    ?>
                        <tr>
                            <td><strong><?= e($bulanNama) ?></strong> (<code><?= e($b['periode_bulan']) ?></code>)</td>
                            <td style="text-align: center;"><span class="badge" style="background: #0dcaf0; color: #000;"><?= e($b['total_transaksi']) ?> transaksi</span></td>
                            <td style="text-align: center;"><?= e($b['total_pelanggan_unik']) ?> orang</td>
                            <td style="text-align: right; font-weight: bold; color: #0f5132;"><?= format_rupiah($b['total_pendapatan']) ?></td>
                            <td style="text-align: right;"><?= format_rupiah($b['rata_rata_transaksi']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php
// ====================================================================
// TAB 4: LAPORAN PRODUK TERLARIS (VIEW view_produk_terlaris)
// ====================================================================
elseif ($tab === 'produk'):
    $topProduk = Laporan::getProdukTerlaris(15);
?>
    <div class="card">
        <h3 style="margin-top: 0; font-size: 16px;">
            Peringkat Produk Terlaris (Sumber Data: <code>view_produk_terlaris</code>)
        </h3>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 60px; text-align: center;">Peringkat</th>
                    <th>Kode</th>
                    <th>Nama Produk</th>
                    <th>Kategori</th>
                    <th style="text-align: center;">Jumlah Terjual</th>
                    <th style="text-align: right;">Total Omzet</th>
                    <th style="text-align: center;">Sisa Stok Gudang</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($topProduk)): ?>
                    <tr><td colspan="7" style="text-align: center; color: #6c757d; padding: 25px;">Belum ada data penjualan produk.</td></tr>
                <?php else: ?>
                    <?php $rank = 1; foreach ($topProduk as $prod): ?>
                        <tr>
                            <td style="text-align: center;">
                                <?php if ($rank === 1): ?>
                                    <span class="badge" style="background: #ffc107; color: #000; font-size: 13px;">#1 &#129351;</span>
                                <?php elseif ($rank === 2): ?>
                                    <span class="badge" style="background: #ced4da; color: #000; font-size: 12px;">#2 &#129352;</span>
                                <?php elseif ($rank === 3): ?>
                                    <span class="badge" style="background: #cd7f32; color: #fff; font-size: 12px;">#3 &#129353;</span>
                                <?php else: ?>
                                    <span style="color: #6c757d;">#<?= $rank ?></span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= e($prod['kode_produk']) ?></code></td>
                            <td><strong><?= e($prod['nama_produk']) ?></strong></td>
                            <td><?= e($prod['nama_kategori'] ?? '-') ?></td>
                            <td style="text-align: center;"><strong><?= format_angka($prod['total_terjual']) ?></strong> unit</td>
                            <td style="text-align: right; font-weight: bold; color: #0f5132;"><?= format_rupiah($prod['total_omzet']) ?></td>
                            <td style="text-align: center;">
                                <span class="badge" style="background: #e2e3e5; color: #383d41;"><?= e($prod['sisa_stok']) ?> pcs</span>
                            </td>
                        </tr>
                    <?php $rank++; endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php
// ====================================================================
// TAB 5: ANALISIS PELANGGAN (VIEW view_total_belanja)
// ====================================================================
elseif ($tab === 'pelanggan'):
    $topPelanggan = Laporan::getLaporanPelanggan(15);
?>
    <div class="card">
        <h3 style="margin-top: 0; font-size: 16px;">
            Peringkat Kontribusi Pelanggan (Sumber Data: <code>view_total_belanja</code>)
        </h3>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 60px; text-align: center;">Peringkat</th>
                    <th>Nama Pelanggan</th>
                    <th>Kota</th>
                    <th style="text-align: center;">Total Transaksi</th>
                    <th style="text-align: right;">Akumulasi Belanja</th>
                    <th style="text-align: center;">Segmentasi Loyalitas</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($topPelanggan)): ?>
                    <tr><td colspan="6" style="text-align: center; color: #6c757d; padding: 25px;">Belum ada data pelanggan.</td></tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($topPelanggan as $pel): ?>
                        <tr>
                            <td style="text-align: center; font-weight: bold; color: #6c757d;">#<?= $no++ ?></td>
                            <td><strong><?= e($pel['nama_pelanggan']) ?></strong></td>
                            <td><?= e($pel['kota']) ?></td>
                            <td style="text-align: center;">
                                <span class="badge" style="background: #0dcaf0; color: #000;"><?= e($pel['total_transaksi']) ?> kali</span>
                            </td>
                            <td style="text-align: right; font-weight: bold; color: #0f5132;"><?= format_rupiah($pel['total_belanja']) ?></td>
                            <td style="text-align: center;">
                                <?php if ($pel['status_loyalitas'] === 'Pelanggan VIP'): ?>
                                    <span class="badge" style="background: #ffc107; color: #000;">VIP</span>
                                <?php elseif ($pel['status_loyalitas'] === 'Pelanggan Reguler'): ?>
                                    <span class="badge" style="background: #0d6efd; color: #fff;">Reguler</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #e2e3e5; color: #6c757d;">Baru</span>
                                <?php endif; ?>
                            </td>
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
