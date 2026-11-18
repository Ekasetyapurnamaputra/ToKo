<?php
/**
 * Modul Scan & Penerimaan Produk dari Pabrik (Inbound / Restock Warehouse)
 * Sistem Informasi Penjualan ToKo
 * Mendukung Barcode Scanner Hardware (USB/Wireless), Kamera Scanner HTML5, dan Penambahan Produk Baru
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/Produk.php';
require_once dirname(dirname(__DIR__)) . '/models/Kategori.php';
require_once dirname(dirname(__DIR__)) . '/models/PenerimaanPabrik.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/barcode_helper.php';

Auth::requireRole(['admin', 'kasir']);

$currentUser = Auth::user();
$action = $_GET['action'] ?? 'scan';

// ====================================================================
// 1. ENDPOINT AJAX (LOOKUP BARCODE, QUICK CREATE, & LIVE SEARCH)
// ====================================================================

// AJAX: Lookup Barcode atau Kode Produk
if ($action === 'ajax_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $barcode = clean($_POST['barcode'] ?? $_GET['barcode'] ?? '');

    if (empty($barcode)) {
        echo json_encode(['success' => false, 'message' => 'Barcode tidak boleh kosong.']);
        exit;
    }

    $produk = Produk::findByBarcodeOrKode($barcode);

    if ($produk) {
        echo json_encode([
            'success' => true,
            'found'   => true,
            'product' => [
                'id_produk'      => (int)$produk['id_produk'],
                'kode_produk'    => $produk['kode_produk'],
                'barcode_pabrik' => $produk['barcode_pabrik'] ?? $produk['kode_produk'],
                'nama_produk'    => $produk['nama_produk'],
                'nama_kategori'  => $produk['nama_kategori'] ?? 'Tanpa Kategori',
                'stok'           => (int)$produk['stok'],
                'harga'          => (float)$produk['harga'],
                'harga_beli'     => (float)($produk['harga_beli'] ?? 0),
                'status'         => $produk['status']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'found'   => false,
            'barcode' => $barcode,
            'message' => "Produk dengan barcode '{$barcode}' belum terdaftar di database sistem."
        ]);
    }
    exit;
}

// AJAX: Pendaftaran Cepat Produk Baru dari Pabrik
if ($action === 'ajax_quick_create') {
    header('Content-Type: application/json; charset=utf-8');

    if (!is_post()) {
        echo json_encode(['success' => false, 'message' => 'Metode request tidak valid.']);
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        echo json_encode(['success' => false, 'message' => 'Token keamanan CSRF tidak valid. Silakan muat ulang halaman.']);
        exit;
    }

    $barcode   = clean($_POST['barcode_pabrik'] ?? '');
    $nama      = clean($_POST['nama_produk'] ?? '');
    $kategori  = (int)($_POST['id_kategori'] ?? 0);
    $hargaBeli = (float)($_POST['harga_beli'] ?? 0);
    $hargaJual = (float)($_POST['harga'] ?? 0);
    $stokAwal  = (int)($_POST['stok_awal'] ?? 0);

    if (empty($nama)) {
        echo json_encode(['success' => false, 'message' => 'Nama produk wajib diisi.']);
        exit;
    }

    if ($hargaJual < 0 || $hargaBeli < 0 || $stokAwal < 0) {
        echo json_encode(['success' => false, 'message' => 'Harga dan stok tidak boleh bernilai negatif.']);
        exit;
    }

    // Buat kode produk otomatis jika tidak diberikan
    $kodeProduk = clean($_POST['kode_produk'] ?? '');
    if (empty($kodeProduk)) {
        $kodeProduk = Produk::generateKode();
    }

    $createRes = Produk::create([
        'kode_produk'    => $kodeProduk,
        'barcode_pabrik' => !empty($barcode) ? $barcode : $kodeProduk,
        'nama_produk'    => $nama,
        'id_kategori'    => $kategori > 0 ? $kategori : null,
        'harga'          => $hargaJual,
        'harga_beli'     => $hargaBeli,
        'stok'           => $stokAwal,
        'status'         => 'aktif'
    ]);

    if (!$createRes['success']) {
        echo json_encode(['success' => false, 'message' => $createRes['message']]);
        exit;
    }

    $newProd = Produk::getById($createRes['id']);

    echo json_encode([
        'success' => true,
        'message' => "Produk baru '{$nama}' berhasil didaftarkan ke katalog.",
        'product' => [
            'id_produk'      => (int)$newProd['id_produk'],
            'kode_produk'    => $newProd['kode_produk'],
            'barcode_pabrik' => $newProd['barcode_pabrik'] ?? $newProd['kode_produk'],
            'nama_produk'    => $newProd['nama_produk'],
            'nama_kategori'  => $newProd['nama_kategori'] ?? 'Tanpa Kategori',
            'stok'           => (int)$newProd['stok'],
            'harga'          => (float)$newProd['harga'],
            'harga_beli'     => (float)($newProd['harga_beli'] ?? 0),
            'status'         => $newProd['status']
        ]
    ]);
    exit;
}

// AJAX: Cari Produk Berdasarkan Kata Kunci (Untuk Pemilihan Manual)
if ($action === 'ajax_search') {
    header('Content-Type: application/json; charset=utf-8');
    $q = clean($_GET['q'] ?? '');
    $list = Produk::getAll($q, null, 'aktif');
    $results = [];
    $limit = 15;
    $count = 0;
    foreach ($list as $item) {
        $results[] = [
            'id_produk'      => (int)$item['id_produk'],
            'kode_produk'    => $item['kode_produk'],
            'barcode_pabrik' => $item['barcode_pabrik'] ?? $item['kode_produk'],
            'nama_produk'    => $item['nama_produk'],
            'nama_kategori'  => $item['nama_kategori'] ?? 'Tanpa Kategori',
            'stok'           => (int)$item['stok'],
            'harga'          => (float)$item['harga'],
            'harga_beli'     => (float)($item['harga_beli'] ?? 0)
        ];
        $count++;
        if ($count >= $limit) break;
    }
    echo json_encode(['success' => true, 'results' => $results]);
    exit;
}

// ====================================================================
// 2. PROSES POST SIMPAN PENERIMAAN BARANG DARI PABRIK
// ====================================================================
if (is_post() && $action === 'save_penerimaan') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        Session::setFlash('danger', 'Validasi token keamanan (CSRF) gagal. Silakan coba kembali.');
        header('Location: index.php?page=scan_pabrik');
        exit;
    }

    $namaPabrik   = clean($_POST['nama_pabrik'] ?? '');
    $noSuratJalan = clean($_POST['no_surat_jalan'] ?? '');
    $tanggalTerima= clean($_POST['tanggal_terima'] ?? date('Y-m-d H:i:s'));
    $catatan      = clean($_POST['catatan'] ?? '');
    $itemsJson    = $_POST['items_json'] ?? '';

    $items = json_decode($itemsJson, true);

    if (empty($namaPabrik)) {
        Session::setFlash('danger', 'Nama pabrik / supplier pengirim wajib diisi.');
        header('Location: index.php?page=scan_pabrik');
        exit;
    }

    if (empty($items) || !is_array($items)) {
        Session::setFlash('danger', 'Belum ada produk yang discan untuk disimpan.');
        header('Location: index.php?page=scan_pabrik');
        exit;
    }

    $headerData = [
        'nama_pabrik'    => $namaPabrik,
        'no_surat_jalan' => $noSuratJalan,
        'tanggal_terima' => $tanggalTerima,
        'catatan'        => $catatan,
        'id_user'        => Auth::id()
    ];

    $result = PenerimaanPabrik::createPenerimaan($headerData, $items);

    if ($result['success']) {
        Session::setFlash('success', "Penerimaan barang dari pabrik '{$namaPabrik}' ({$result['total_qty']} unit) berhasil diproses dan stok telah diperbarui!");
        header('Location: index.php?page=scan_pabrik&action=detail&id=' . $result['id_penerimaan'] . '&new=1');
        exit;
    } else {
        Session::setFlash('danger', $result['message']);
        header('Location: index.php?page=scan_pabrik');
        exit;
    }
}

// ====================================================================
// 3. VIEW: DETAIL PENERIMAAN BARANG
// ====================================================================
if ($action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $penerimaan = PenerimaanPabrik::getById($id);

    if (!$penerimaan) {
        Session::setFlash('danger', 'Data bukti penerimaan barang pabrik tidak ditemukan.');
        header('Location: index.php?page=scan_pabrik&action=riwayat');
        exit;
    }

    $details = PenerimaanPabrik::getDetails($id);
    $pageTitle = 'Bukti Penerimaan Pabrik — ' . $penerimaan['no_penerimaan'];
    require_once dirname(dirname(__DIR__)) . '/templates/header.php';
    ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0;">Bukti Penerimaan Produk dari Pabrik</h2>
            <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">Nomor Dokumen: <strong><?= e($penerimaan['no_penerimaan']) ?></strong></p>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="index.php?page=scan_pabrik&action=cetak&id=<?= $penerimaan['id_penerimaan'] ?>" target="_blank" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                <span>Cetak Tanda Terima</span>
            </a>
            <a href="index.php?page=scan_pabrik" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                <span>+ Scan Penerimaan Baru</span>
            </a>
            <a href="index.php?page=scan_pabrik&action=riwayat" class="btn btn-secondary">Riwayat</a>
        </div>
    </div>

    <!-- Ringkasan Dokumen -->
    <div class="card" style="margin-bottom: 20px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
            <div>
                <small style="color: #6c757d; text-transform: uppercase; font-size: 11px; font-weight: 600;">Pabrik / Supplier</small>
                <div style="font-size: 17px; font-weight: 700; color: #111827; margin-top: 3px;">🏭 <?= e($penerimaan['nama_pabrik']) ?></div>
            </div>
            <div>
                <small style="color: #6c757d; text-transform: uppercase; font-size: 11px; font-weight: 600;">No. Surat Jalan / DO Pabrik</small>
                <div style="font-size: 16px; font-weight: 600; color: #374151; margin-top: 3px;"><?= !empty($penerimaan['no_surat_jalan']) ? e($penerimaan['no_surat_jalan']) : '<span style="color:#9ca3af;">(Tidak dicantumkan)</span>' ?></div>
            </div>
            <div>
                <small style="color: #6c757d; text-transform: uppercase; font-size: 11px; font-weight: 600;">Tanggal & Waktu Diterima</small>
                <div style="font-size: 15px; font-weight: 600; color: #374151; margin-top: 3px;">📅 <?= format_tanggal_indo($penerimaan['tanggal_terima']) ?></div>
            </div>
            <div>
                <small style="color: #6c757d; text-transform: uppercase; font-size: 11px; font-weight: 600;">Petugas Penerima Gudang</small>
                <div style="font-size: 15px; font-weight: 600; color: #374151; margin-top: 3px;">👤 <?= e($penerimaan['nama_petugas'] ?? 'Petugas Toko') ?> (<?= e($penerimaan['role'] ?? 'Staff') ?>)</div>
            </div>
        </div>

        <?php if (!empty($penerimaan['catatan'])): ?>
            <div style="margin-top: 15px; padding-top: 12px; border-top: 1px dashed #e5e7eb; font-size: 13px; color: #4b5563;">
                <strong>Catatan Pengiriman:</strong> <?= nl2br(e($penerimaan['catatan'])) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tabel Rincian Produk Diterima -->
    <div class="card">
        <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 16px;">Rincian Produk Masuk ke Gudang</h3>
        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left; font-size: 13px;">
                        <th style="padding: 10px 12px;">No</th>
                        <th style="padding: 10px 12px;">Kode & Barcode</th>
                        <th style="padding: 10px 12px;">Nama Produk</th>
                        <th style="padding: 10px 12px; text-align: right;">Stok Gudang Sebelum</th>
                        <th style="padding: 10px 12px; text-align: center;">Jumlah Masuk</th>
                        <th style="padding: 10px 12px; text-align: right;">Stok Gudang Sesudah</th>
                        <th style="padding: 10px 12px; text-align: right;">Harga Beli Pabrik</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    $totalQty = 0;
                    $totalNilaiBeli = 0;
                    foreach ($details as $d): 
                        $totalQty += (int)$d['jumlah_masuk'];
                        $subtotalBeli = (float)$d['harga_beli'] * (int)$d['jumlah_masuk'];
                        $totalNilaiBeli += $subtotalBeli;
                    ?>
                    <tr style="border-bottom: 1px solid #edf2f7; font-size: 13px;">
                        <td style="padding: 10px 12px;"><?= $no++ ?></td>
                        <td style="padding: 10px 12px;">
                            <span class="badge badge-secondary" style="font-family: monospace;"><?= e($d['kode_produk']) ?></span>
                        </td>
                        <td style="padding: 10px 12px;">
                            <strong><?= e($d['nama_produk']) ?></strong>
                            <?php if (!empty($d['nama_kategori'])): ?>
                                <span style="font-size: 11px; color: #64748b; display: block;"><?= e($d['nama_kategori']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 10px 12px; text-align: right; color: #64748b;"><?= number_format($d['stok_sebelum'], 0, ',', '.') ?> unit</td>
                        <td style="padding: 10px 12px; text-align: center;">
                            <span style="display: inline-block; padding: 3px 10px; background: #ecfdf5; color: #047857; font-weight: 700; border-radius: 999px; border: 1px solid #a7f3d0;">
                                +<?= number_format($d['jumlah_masuk'], 0, ',', '.') ?> unit
                            </span>
                        </td>
                        <td style="padding: 10px 12px; text-align: right; font-weight: 700; color: #1e293b;">
                            <?= number_format($d['stok_sesudah'], 0, ',', '.') ?> unit
                        </td>
                        <td style="padding: 10px 12px; text-align: right;">
                            <?= $d['harga_beli'] > 0 ? format_rupiah($d['harga_beli']) : '<span style="color:#94a3b8;">-</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f8fafc; font-weight: 700; border-top: 2px solid #cbd5e1;">
                        <td colspan="4" style="padding: 12px; text-align: right;">TOTAL KESELURUHAN DITERIMA:</td>
                        <td style="padding: 12px; text-align: center; color: #047857; font-size: 15px;">
                            <?= number_format($totalQty, 0, ',', '.') ?> unit
                        </td>
                        <td></td>
                        <td style="padding: 12px; text-align: right; font-size: 14px; color: #1e293b;">
                            <?= $totalNilaiBeli > 0 ? format_rupiah($totalNilaiBeli) : '-' ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php
    require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
    exit;
}

// ====================================================================
// 4. VIEW: CETAK TANDA TERIMA BARANG PABRIK (PRINT SLIP)
// ====================================================================
if ($action === 'cetak') {
    $id = (int)($_GET['id'] ?? 0);
    $penerimaan = PenerimaanPabrik::getById($id);

    if (!$penerimaan) {
        die('Dokumen tidak ditemukan.');
    }

    $details = PenerimaanPabrik::getDetails($id);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Surat Penerimaan Barang Pabrik - <?= e($penerimaan['no_penerimaan']) ?></title>
        <style>
            @page { size: A4 portrait; margin: 15mm; }
            body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #111; margin: 0; padding: 20px; }
            .header { display: flex; justify-content: space-between; border-bottom: 2px solid #222; padding-bottom: 12px; margin-bottom: 16px; }
            .store-name { font-size: 20px; font-weight: bold; }
            .doc-title { font-size: 16px; font-weight: bold; text-align: right; text-transform: uppercase; }
            .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; font-size: 12px; }
            .meta-box { border: 1px solid #ccc; padding: 10px; border-radius: 4px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
            th, td { border: 1px solid #333; padding: 7px 10px; }
            th { background: #f2f2f2; text-align: left; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .signatures { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; text-align: center; margin-top: 30px; }
            .sig-space { height: 60px; }
            @media print {
                .no-print { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 15px; padding: 10px; background: #e0f2fe; border: 1px solid #7dd3fc; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
            <span>Tekan tombol di sebelah kanan untuk mencetak tanda terima penerimaan gudang.</span>
            <button onclick="window.print()" style="padding: 8px 16px; background: #0284c7; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">🖨️ Cetak Dokumen</button>
        </div>

        <div class="header">
            <div>
                <div class="store-name"><?= e(APP_NAME) ?></div>
                <div style="color: #555; font-size: 11px;">Sistem Gudang & Logistik Masuk Barang Pabrik</div>
            </div>
            <div style="text-align: right;">
                <div class="doc-title">Bukti Penerimaan Barang Pabrik</div>
                <div style="font-family: monospace; font-size: 13px; font-weight: bold;"><?= e($penerimaan['no_penerimaan']) ?></div>
                <div style="margin-top: 5px;">
                    <?= generate_barcode_svg($penerimaan['no_penerimaan'], 32, 1, 3) ?>
                </div>
            </div>
        </div>

        <div class="meta-grid">
            <div class="meta-box">
                <strong>DATA PENGIRIMAN PABRIK:</strong><br>
                Pabrik / Vendor: <strong><?= e($penerimaan['nama_pabrik']) ?></strong><br>
                No. Surat Jalan Pabrik: <?= !empty($penerimaan['no_surat_jalan']) ? e($penerimaan['no_surat_jalan']) : '-' ?><br>
                Tanggal Masuk: <?= format_tanggal_indo($penerimaan['tanggal_terima']) ?>
            </div>
            <div class="meta-box">
                <strong>PENERIMA GUDANG:</strong><br>
                Nama Petugas: <?= e($penerimaan['nama_petugas'] ?? 'Petugas Toko') ?><br>
                Tanggal Catat: <?= date('d/m/Y H:i', strtotime($penerimaan['created_at'])) ?><br>
                Catatan: <?= !empty($penerimaan['catatan']) ? e($penerimaan['catatan']) : '-' ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 30px;" class="text-center">No</th>
                    <th>Kode / Barcode</th>
                    <th>Nama Barang / Produk</th>
                    <th class="text-center">Stok Awal</th>
                    <th class="text-center">Jumlah Diterima</th>
                    <th class="text-center">Stok Akhir</th>
                    <th class="text-right">Harga Pabrik</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                $tot = 0;
                foreach ($details as $d): 
                    $tot += (int)$d['jumlah_masuk'];
                ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td style="font-family: monospace;"><?= e($d['kode_produk']) ?></td>
                    <td><strong><?= e($d['nama_produk']) ?></strong></td>
                    <td class="text-center"><?= $d['stok_sebelum'] ?></td>
                    <td class="text-center" style="font-weight: bold;">+<?= $d['jumlah_masuk'] ?></td>
                    <td class="text-center"><?= $d['stok_sesudah'] ?></td>
                    <td class="text-right"><?= $d['harga_beli'] > 0 ? format_rupiah($d['harga_beli']) : '-' ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="font-weight: bold; background: #fafafa;">
                    <td colspan="4" class="text-right">TOTAL FISIK DITERIMA:</td>
                    <td class="text-center" style="font-size: 13px;"><?= number_format($tot, 0, ',', '.') ?> UNIT</td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>

        <div class="signatures">
            <div>
                <div>Pengirim / Sopir Pabrik</div>
                <div class="sig-space"></div>
                <div>( _____________________ )</div>
            </div>
            <div>
                <div>Petugas Gudang Toko</div>
                <div class="sig-space"></div>
                <div>( <strong><?= e($penerimaan['nama_petugas'] ?? 'Staff Gudang') ?></strong> )</div>
            </div>
            <div>
                <div>Kepala Toko / Supervisor</div>
                <div class="sig-space"></div>
                <div>( _____________________ )</div>
            </div>
        </div>

        <script>
            window.addEventListener('DOMContentLoaded', function() {
                // Auto print jika dibuka langsung
                setTimeout(function() { window.print(); }, 600);
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ====================================================================
// 5. VIEW: RIWAYAT PENERIMAAN BARANG DARI PABRIK
// ====================================================================
if ($action === 'riwayat') {
    $keyword   = clean($_GET['q'] ?? '');
    $tglMulai  = clean($_GET['tgl_mulai'] ?? '');
    $tglSelesai= clean($_GET['tgl_selesai'] ?? '');

    $listRiwayat = PenerimaanPabrik::getAll($keyword, $tglMulai, $tglSelesai);
    $stats = PenerimaanPabrik::getStats();

    $pageTitle = 'Riwayat Penerimaan Produk dari Pabrik';
    require_once dirname(dirname(__DIR__)) . '/templates/header.php';
    ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0;">Riwayat Penerimaan Barang dari Pabrik</h2>
            <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">Arsip seluruh dokumen penerimaan dan penambahan stok masuk dari pabrik / supplier.</p>
        </div>
        <div>
            <a href="index.php?page=scan_pabrik" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/></svg>
                <span>+ Scan Penerimaan Baru</span>
            </a>
        </div>
    </div>

    <!-- Filter Riwayat -->
    <div class="card" style="margin-bottom: 20px;">
        <form method="GET" action="index.php" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <input type="hidden" name="page" value="scan_pabrik">
            <input type="hidden" name="action" value="riwayat">

            <input type="text" name="q" class="form-control" style="max-width: 250px;" 
                   placeholder="Cari no bukti, pabrik, atau no SJ..." value="<?= e($keyword) ?>">

            <div style="display: flex; align-items: center; gap: 6px;">
                <label style="font-size: 12px; color: #6c757d; margin: 0;">Dari:</label>
                <input type="date" name="tgl_mulai" class="form-control" style="max-width: 160px;" value="<?= e($tglMulai) ?>">
            </div>

            <div style="display: flex; align-items: center; gap: 6px;">
                <label style="font-size: 12px; color: #6c757d; margin: 0;">Sampai:</label>
                <input type="date" name="tgl_selesai" class="form-control" style="max-width: 160px;" value="<?= e($tglSelesai) ?>">
            </div>

            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if (!empty($keyword) || !empty($tglMulai) || !empty($tglSelesai)): ?>
                <a href="index.php?page=scan_pabrik&action=riwayat" class="btn btn-secondary">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabel Data Riwayat -->
    <div class="card">
        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left; font-size: 13px;">
                        <th style="padding: 10px 12px;">No. Penerimaan</th>
                        <th style="padding: 10px 12px;">Tanggal Terima</th>
                        <th style="padding: 10px 12px;">Pabrik / Vendor</th>
                        <th style="padding: 10px 12px;">No. Surat Jalan</th>
                        <th style="padding: 10px 12px; text-align: center;">Total Produk</th>
                        <th style="padding: 10px 12px; text-align: center;">Total Unit</th>
                        <th style="padding: 10px 12px;">Petugas</th>
                        <th style="padding: 10px 12px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($listRiwayat)): ?>
                        <tr>
                            <td colspan="8" style="padding: 30px; text-align: center; color: #94a3b8;">
                                Belum ada riwayat penerimaan produk dari pabrik.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($listRiwayat as $r): ?>
                            <tr style="border-bottom: 1px solid #edf2f7; font-size: 13px;">
                                <td style="padding: 10px 12px;">
                                    <strong style="color: #2563eb; font-family: monospace;"><?= e($r['no_penerimaan']) ?></strong>
                                </td>
                                <td style="padding: 10px 12px; white-space: nowrap;">
                                    <?= date('d/m/Y H:i', strtotime($r['tanggal_terima'])) ?>
                                </td>
                                <td style="padding: 10px 12px; font-weight: 600;">
                                    🏭 <?= e($r['nama_pabrik']) ?>
                                </td>
                                <td style="padding: 10px 12px; color: #475569;">
                                    <?= !empty($r['no_surat_jalan']) ? e($r['no_surat_jalan']) : '<span style="color:#94a3b8;">-</span>' ?>
                                </td>
                                <td style="padding: 10px 12px; text-align: center;">
                                    <span class="badge badge-secondary"><?= $r['total_item'] ?> SKU</span>
                                </td>
                                <td style="padding: 10px 12px; text-align: center; font-weight: 700; color: #047857;">
                                    +<?= number_format($r['total_qty'], 0, ',', '.') ?>
                                </td>
                                <td style="padding: 10px 12px; color: #64748b; font-size: 12px;">
                                    <?= e($r['nama_petugas'] ?? 'Petugas Toko') ?>
                                </td>
                                <td style="padding: 10px 12px; text-align: center; white-space: nowrap;">
                                    <a href="index.php?page=scan_pabrik&action=detail&id=<?= $r['id_penerimaan'] ?>" class="btn btn-secondary btn-sm" title="Lihat Rincian">Rincian</a>
                                    <a href="index.php?page=scan_pabrik&action=cetak&id=<?= $r['id_penerimaan'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="Cetak Surat Penerimaan">🖨️</a>
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
    exit;
}

// ====================================================================
// 6. VIEW UTAMA: SCANNER PRODUK DARI PABRIK (INBOUND ENTRY HUB)
// ====================================================================
$kategoriList = Kategori::getAll();
$stats = PenerimaanPabrik::getStats();
$nextDocNo = PenerimaanPabrik::generateNoPenerimaan();

$pageTitle = 'Scan Produk Masuk dari Pabrik';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<!-- Header & Navigasi Cepat -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="margin: 0; display: flex; align-items: center; gap: 8px;">
            <span>🏭 Scan Produk dari Pabrik</span>
            <span class="badge badge-success" style="font-size: 12px; font-weight: 500;">Inbound Warehouse</span>
        </h2>
        <p style="margin: 4px 0 0 0; color: #6c757d; font-size: 13px;">
            Pindai barcode kemasan produk dari pabrik / vendor untuk verifikasi barang masuk dan penambahan stok gudang otomatis.
        </p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="index.php?page=scan_pabrik&action=riwayat" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            <span>Riwayat Penerimaan Pabrik</span>
        </a>
        <a href="index.php?page=produk" class="btn btn-secondary">Katalog Produk</a>
    </div>
</div>

<!-- Statistik Mini -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
    <div class="card" style="padding: 15px; background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%); border-left: 4px solid #3b82f6;">
        <div style="font-size: 12px; color: #64748b; font-weight: 600;">DITERIMA HARI INI</div>
        <div style="font-size: 24px; font-weight: 700; color: #1e3a8a; margin-top: 4px;">
            <?= number_format($stats['unit_hari_ini'] ?? 0, 0, ',', '.') ?> <span style="font-size: 13px; font-weight: 500; color: #64748b;">unit</span>
        </div>
    </div>
    <div class="card" style="padding: 15px; background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%); border-left: 4px solid #10b981;">
        <div style="font-size: 12px; color: #64748b; font-weight: 600;">TOTAL MASUK BULAN INI</div>
        <div style="font-size: 24px; font-weight: 700; color: #065f46; margin-top: 4px;">
            <?= number_format($stats['unit_bulan_ini'] ?? 0, 0, ',', '.') ?> <span style="font-size: 13px; font-weight: 500; color: #64748b;">unit</span>
        </div>
    </div>
    <div class="card" style="padding: 15px; background: linear-gradient(135deg, #faf5ff 0%, #ffffff 100%); border-left: 4px solid #a855f7;">
        <div style="font-size: 12px; color: #64748b; font-weight: 600;">PABRIK / SUPPLIER AKTIF</div>
        <div style="font-size: 24px; font-weight: 700; color: #581c87; margin-top: 4px;">
            <?= number_format($stats['total_pabrik'] ?? 0, 0, ',', '.') ?> <span style="font-size: 13px; font-weight: 500; color: #64748b;">rekanan</span>
        </div>
    </div>
    <div class="card" style="padding: 15px; background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%); border-left: 4px solid #f59e0b;">
        <div style="font-size: 12px; color: #64748b; font-weight: 600;">TOTAL RIWAYAT SURAT</div>
        <div style="font-size: 24px; font-weight: 700; color: #92400e; margin-top: 4px;">
            <?= number_format($stats['total_transaksi'] ?? 0, 0, ',', '.') ?> <span style="font-size: 13px; font-weight: 500; color: #64748b;">dokumen</span>
        </div>
    </div>
</div>

<!-- Layout 2 Kolom: Kolom Kiri Scanner & Data Pengiriman, Kolom Kanan Manifest Barang Masuk -->
<div style="display: grid; grid-template-columns: 380px 1fr; gap: 20px; align-items: start;" id="scan-pabrik-container">
    
    <!-- KOLOM KIRI: INFO SURAT JALAN & SCANNER -->
    <div>
        <!-- Card 1: Data Pengiriman Pabrik -->
        <div class="card" style="margin-bottom: 20px;">
            <h3 style="margin-top: 0; font-size: 15px; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                <span>Informasi Pengiriman Pabrik</span>
            </h3>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="nama_pabrik" style="font-weight: 600; font-size: 12px;">Nama Pabrik / Supplier *</label>
                <input type="text" id="nama_pabrik" name="nama_pabrik" class="form-control" list="list-pabrik-suggestions" 
                       placeholder="Contoh: PT Indofood CBP, Unilever..." required autocomplete="off">
                <datalist id="list-pabrik-suggestions">
                    <option value="PT Indofood CBP Sukses Makmur Tbk">
                    <option value="PT Unilever Indonesia Tbk">
                    <option value="PT Mayora Indah Tbk">
                    <option value="PT Wings Surya (Wings Group)">
                    <option value="Pabrik Beras Nusantara">
                    <option value="CV Sumber Pangan Makmur">
                    <option value="PT Forisa Nusapersada">
                </datalist>
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="no_surat_jalan" style="font-weight: 600; font-size: 12px;">No. Surat Jalan / Faktur Pabrik</label>
                <input type="text" id="no_surat_jalan" name="no_surat_jalan" class="form-control" 
                       placeholder="Contoh: SJ-2026/XI/9941" autocomplete="off">
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="tanggal_terima" style="font-weight: 600; font-size: 12px;">Waktu Penerimaan</label>
                <input type="datetime-local" id="tanggal_terima" name="tanggal_terima" class="form-control" 
                       value="<?= date('Y-m-d\TH:i') ?>">
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="catatan_penerimaan" style="font-weight: 600; font-size: 12px;">Catatan Tambahan (Opsional)</label>
                <input type="text" id="catatan_penerimaan" name="catatan_penerimaan" class="form-control" 
                       placeholder="Contoh: Barang tiba kondisi baik via truk box">
            </div>
        </div>

        <!-- Card 2: Scanner Barcode Pabrik -->
        <div class="card" style="border: 2px solid #2563eb; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.12);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h3 style="margin: 0; font-size: 15px; color: #1e3a8a; display: flex; align-items: center; gap: 6px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/></svg>
                    <span>Pindai Barcode Pabrik</span>
                </h3>
                <span class="badge badge-success" id="scanner-status-indicator">Siap Scan</span>
            </div>

            <!-- Input Barcode Utama -->
            <div style="margin-bottom: 12px;">
                <label for="barcode_input" style="font-size: 12px; font-weight: 600; color: #374151;">
                    Arahkan Barcode Scanner Gun / Input Manual:
                </label>
                <div style="position: relative; margin-top: 4px;">
                    <input type="text" id="barcode_input" class="form-control" 
                           placeholder="Scan barcode di sini lalu Enter..." 
                           style="padding-left: 38px; font-size: 14px; font-weight: 600; border-color: #3b82f6; height: 42px;"
                           autofocus autocomplete="off">
                    <span style="position: absolute; left: 10px; top: 11px; color: #3b82f6;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/></svg>
                    </span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px; font-size: 11px; color: #6b7280;">
                    <span>Tekan <kbd style="background:#eee; padding:1px 4px; border-radius:3px;">Enter</kbd> untuk memproses</span>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px;">
                        <input type="checkbox" id="chk-audio-beep" checked>
                        <span>Suara Beep 🔊</span>
                    </label>
                </div>
            </div>

            <!-- Mode Jumlah Cepat (Batch Multiplier) -->
            <div style="margin-bottom: 14px; background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                <label style="font-size: 11px; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">
                    Tambahan Qty per Kali Scan:
                </label>
                <div style="display: flex; gap: 6px;" id="batch-qty-selectors">
                    <button type="button" class="btn btn-secondary btn-sm batch-btn active" data-qty="1">+1 Unit</button>
                    <button type="button" class="btn btn-secondary btn-sm batch-btn" data-qty="5">+5 Unit</button>
                    <button type="button" class="btn btn-secondary btn-sm batch-btn" data-qty="10">+10 Unit</button>
                    <button type="button" class="btn btn-secondary btn-sm batch-btn" data-qty="24">+24 (1 Dus)</button>
                </div>
            </div>

            <!-- Toggle Kamera Live Scanner -->
            <div style="display: flex; gap: 8px; margin-bottom: 10px;">
                <button type="button" id="btn-toggle-camera" class="btn btn-secondary" style="flex: 1; display: inline-flex; justify-content: center; align-items: center; gap: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                    <span id="btn-camera-text">Buka Kamera Scanner</span>
                </button>
                <button type="button" id="btn-open-manual-search" class="btn btn-secondary" title="Pencarian Manual" style="padding: 0 14px;">
                    🔍 Manual
                </button>
            </div>

            <!-- Container Kamera Scanner (Hidden default) -->
            <div id="camera-scanner-wrapper" style="display: none; margin-top: 10px; border: 2px dashed #3b82f6; border-radius: 8px; padding: 8px; background: #000; position: relative;">
                <video id="camera-preview" style="width: 100%; height: 220px; object-fit: cover; border-radius: 4px; display: block;" playsinline></video>
                <canvas id="camera-canvas" style="display: none;"></canvas>
                
                <!-- Reticle / Target Viewfinder Animasi Laser -->
                <div id="camera-reticle" style="position: absolute; top: 18px; left: 18px; right: 18px; bottom: 18px; border: 2px solid rgba(16, 185, 129, 0.8); border-radius: 6px; pointer-events: none; overflow: hidden;">
                    <div style="position: absolute; top: 50%; left: 0; right: 0; height: 2px; background: #ef4444; box-shadow: 0 0 8px #ef4444; animation: scanLine 2s infinite ease-in-out;"></div>
                </div>

                <div style="position: absolute; bottom: 14px; left: 0; right: 0; text-align: center;">
                    <span style="background: rgba(0,0,0,0.7); color: #fff; font-size: 11px; padding: 4px 10px; border-radius: 999px;">
                        Arahkan kamera ke barcode kemasan pabrik
                    </span>
                </div>
            </div>

            <!-- Feedback Log Terakhir -->
            <div id="scan-last-feedback" style="display: none; margin-top: 12px; padding: 10px; border-radius: 6px; font-size: 12px;"></div>
        </div>
    </div>

    <!-- KOLOM KANAN: DAFTAR MANIFEST BARANG MASUK DARI PABRIK -->
    <div>
        <div class="card" style="min-height: 520px; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 12px; border-bottom: 1px solid #e5e7eb;">
                <div>
                    <h3 style="margin: 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                        <span>Daftar Produk Masuk dari Pabrik</span>
                        <span class="badge badge-primary" id="badge-total-sku">0 SKU</span>
                    </h3>
                    <small style="color: #6c757d;">Periksa jumlah unit fisik yang diterima sebelum menyimpan ke gudang.</small>
                </div>
                <div>
                    <button type="button" id="btn-clear-manifest" class="btn btn-secondary btn-sm" style="color: #ef4444;">
                        Kosongkan Daftar
                    </button>
                </div>
            </div>

            <!-- Tabel Manifest Scanned Items -->
            <div style="flex: 1; overflow-x: auto;">
                <table class="table" id="table-scanned-items" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left; font-size: 12px;">
                            <th style="padding: 10px 8px; width: 35px;">No</th>
                            <th style="padding: 10px 8px;">Barcode / Kode</th>
                            <th style="padding: 10px 8px;">Nama Produk</th>
                            <th style="padding: 10px 8px; text-align: right;">Stok Gudang</th>
                            <th style="padding: 10px 8px; text-align: center; width: 140px;">Qty Diterima</th>
                            <th style="padding: 10px 8px; text-align: right;">Stok Baru</th>
                            <th style="padding: 10px 8px; text-align: right; width: 120px;">Harga Beli (Rp)</th>
                            <th style="padding: 10px 8px; text-align: center; width: 40px;"></th>
                        </tr>
                    </thead>
                    <tbody id="scanned-items-tbody">
                        <tr id="empty-manifest-row">
                            <td colspan="8" style="padding: 60px 20px; text-align: center; color: #94a3b8;">
                                <div style="font-size: 44px; margin-bottom: 10px;">📦</div>
                                <div style="font-size: 15px; font-weight: 600; color: #475569;">Belum Ada Produk yang Discan</div>
                                <p style="font-size: 12px; margin: 4px auto 0; max-width: 400px;">
                                    Gunakan barcode scanner gun atau aktifkan kamera untuk memindai kode produk dari pabrik. Data produk akan otomatis muncul di sini.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Ringkasan & Tombol Simpan -->
            <div style="border-top: 2px solid #e2e8f0; padding-top: 15px; margin-top: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div style="display: flex; gap: 20px;">
                        <div>
                            <span style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Total Jenis Produk</span>
                            <div style="font-size: 18px; font-weight: 700; color: #1e293b;" id="summary-total-sku">0 SKU</div>
                        </div>
                        <div>
                            <span style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Total Fisik Diterima</span>
                            <div style="font-size: 18px; font-weight: 700; color: #047857;" id="summary-total-qty">0 Unit</div>
                        </div>
                        <div>
                            <span style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Estimasi Nilai Beli</span>
                            <div style="font-size: 18px; font-weight: 700; color: #1e293b;" id="summary-total-nilai">Rp 0</div>
                        </div>
                    </div>

                    <form method="POST" action="index.php?page=scan_pabrik&action=save_penerimaan" id="form-save-penerimaan">
                        <?= csrf_field() ?>
                        <input type="hidden" name="nama_pabrik" id="hidden_nama_pabrik">
                        <input type="hidden" name="no_surat_jalan" id="hidden_no_surat_jalan">
                        <input type="hidden" name="tanggal_terima" id="hidden_tanggal_terima">
                        <input type="hidden" name="catatan" id="hidden_catatan">
                        <input type="hidden" name="items_json" id="hidden_items_json">

                        <button type="button" id="btn-submit-penerimaan" class="btn btn-primary" style="padding: 10px 24px; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;" disabled>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                            <span>Simpan Penerimaan ke Gudang</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: DAFTARKAN PRODUK BARU DARI PABRIK -->
<div id="modal-quick-create" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="card" style="max-width: 540px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; font-size: 16px; color: #1e3a8a; display: flex; align-items: center; gap: 6px;">
                <span>✨ Daftarkan Produk Baru dari Pabrik</span>
            </h3>
            <button type="button" class="btn-close-modal" id="btn-close-create-modal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #6b7280;">&times;</button>
        </div>

        <p style="font-size: 12px; color: #475569; margin-top: 0;">
            Barcode <strong id="modal-display-barcode" style="font-family: monospace; color: #2563eb;"></strong> belum terdaftar di sistem. Lengkapi data produk berikut untuk langsung mendaftarkannya ke katalog:
        </p>

        <form id="form-quick-create">
            <input type="hidden" id="qc_barcode_pabrik" name="barcode_pabrik">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div class="form-group" style="margin: 0;">
                    <label style="font-size: 12px; font-weight: 600;">Barcode Pabrik</label>
                    <input type="text" id="qc_barcode_display" class="form-control" disabled style="font-family: monospace;">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label style="font-size: 12px; font-weight: 600;">Kode Produk Toko</label>
                    <input type="text" id="qc_kode_produk" name="kode_produk" class="form-control" placeholder="Auto (PRD-XXX)">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="qc_nama_produk" style="font-size: 12px; font-weight: 600;">Nama Produk dari Pabrik *</label>
                <input type="text" id="qc_nama_produk" name="nama_produk" class="form-control" required placeholder="Contoh: Biskuit Kelapa 300g" autocomplete="off">
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="qc_id_kategori" style="font-size: 12px; font-weight: 600;">Kategori</label>
                <select id="qc_id_kategori" name="id_kategori" class="form-control">
                    <option value="">-- Pilih Kategori --</option>
                    <?php foreach ($kategoriList as $kat): ?>
                        <option value="<?= $kat['id_kategori'] ?>"><?= e($kat['nama_kategori']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div class="form-group" style="margin: 0;">
                    <label for="qc_harga_beli" style="font-size: 12px; font-weight: 600;">Harga Beli Pabrik (Rp)</label>
                    <input type="number" id="qc_harga_beli" name="harga_beli" class="form-control" min="0" step="100" value="0">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label for="qc_harga" style="font-size: 12px; font-weight: 600;">Harga Jual Konsumen (Rp) *</label>
                    <input type="number" id="qc_harga" name="harga" class="form-control" min="0" step="100" required placeholder="0">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label for="qc_stok_awal" style="font-size: 12px; font-weight: 600;">Jumlah Diterima Saat Ini (Unit)</label>
                <input type="number" id="qc_stok_awal" name="stok_awal" class="form-control" min="1" value="1">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" id="btn-cancel-create">Batal</button>
                <button type="submit" class="btn btn-primary" id="btn-save-quick-create">Daftarkan & Tambah ke Manifest</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: PENCARIAN MANUAL PRODUK -->
<div id="modal-manual-search" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="card" style="max-width: 500px; width: 90%; max-height: 85vh; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px; margin-bottom: 12px;">
            <h3 style="margin: 0; font-size: 15px; color: #1e293b;">Pencarian Manual Produk</h3>
            <button type="button" class="btn-close-modal" id="btn-close-search-modal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #6b7280;">&times;</button>
        </div>

        <input type="text" id="manual-search-input" class="form-control" placeholder="Ketik nama atau kode produk..." style="margin-bottom: 12px;" autocomplete="off">

        <div id="manual-search-results" style="max-height: 300px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px;">
            <div style="padding: 20px; text-align: center; color: #94a3b8; font-size: 13px;">
                Ketik nama produk untuk mencari...
            </div>
        </div>
    </div>
</div>

<!-- STYLE ANIMASI LASER SCANNER & RESPONSIVE -->
<style>
@keyframes scanLine {
    0% { top: 0%; opacity: 0.8; }
    50% { top: 100%; opacity: 1; }
    100% { top: 0%; opacity: 0.8; }
}
.batch-btn.active {
    background: #2563eb !important;
    color: #fff !important;
    border-color: #2563eb !important;
}
.flash-highlight {
    animation: flashBg 1.2s ease-out;
}
@keyframes flashBg {
    0% { background-color: #fef08a; }
    100% { background-color: transparent; }
}
@media (max-width: 900px) {
    #scan-pabrik-container {
        grid-template-columns: 1fr !important;
    }
}
</style>

<!-- JAVASCRIPT LOGIC SCANNER & INBOUND MANIFEST -->
<script>
(function() {
    'use strict';

    // State Aplikasi
    const state = {
        manifest: [],        // List item yang discan: [{ id_produk, kode_produk, barcode_pabrik, nama_produk, nama_kategori, stok, harga_beli, harga, jumlah_masuk }]
        batchQty: 1,        // Qty penambahan per kali scan (+1, +5, +10, etc)
        isScanningCamera: false,
        cameraStream: null,
        audioContext: null
    };

    // DOM Elements
    const barcodeInput = document.getElementById('barcode_input');
    const chkAudio = document.getElementById('chk-audio-beep');
    const statusIndicator = document.getElementById('scanner-status-indicator');
    const feedbackBox = document.getElementById('scan-last-feedback');
    const tbody = document.getElementById('scanned-items-tbody');
    const badgeSku = document.getElementById('badge-total-sku');
    const summarySku = document.getElementById('summary-total-sku');
    const summaryQty = document.getElementById('summary-total-qty');
    const summaryNilai = document.getElementById('summary-total-nilai');
    const btnSubmit = document.getElementById('btn-submit-penerimaan');
    const btnClear = document.getElementById('btn-clear-manifest');

    // Camera elements
    const btnToggleCamera = document.getElementById('btn-toggle-camera');
    const btnCameraText = document.getElementById('btn-camera-text');
    const cameraWrapper = document.getElementById('camera-scanner-wrapper');
    const cameraVideo = document.getElementById('camera-preview');
    const cameraCanvas = document.getElementById('camera-canvas');

    // Modal elements
    const modalCreate = document.getElementById('modal-quick-create');
    const modalSearch = document.getElementById('modal-manual-search');
    const formQuickCreate = document.getElementById('form-quick-create');

    // Inisialisasi Audio Synthesizer Beep (Web Audio API murni tanpa file mp3 eksternal)
    function playBeep(type) {
        if (!chkAudio.checked) return;
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            if (!state.audioContext) {
                state.audioContext = new AudioContext();
            }
            if (state.audioContext.state === 'suspended') {
                state.audioContext.resume();
            }
            const osc = state.audioContext.createOscillator();
            const gain = state.audioContext.createGain();
            osc.connect(gain);
            gain.connect(state.audioContext.destination);

            if (type === 'success') {
                osc.frequency.setValueAtTime(880, state.audioContext.currentTime); // Nada A5
                gain.gain.setValueAtTime(0.15, state.audioContext.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, state.audioContext.currentTime + 0.12);
                osc.start();
                osc.stop(state.audioContext.currentTime + 0.12);
            } else if (type === 'warning') {
                osc.frequency.setValueAtTime(440, state.audioContext.currentTime);
                gain.gain.setValueAtTime(0.2, state.audioContext.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, state.audioContext.currentTime + 0.25);
                osc.start();
                osc.stop(state.audioContext.currentTime + 0.25);
            }
        } catch (e) {
            console.log('Audio feedback not supported:', e);
        }
    }

    // Format Rupiah Helper
    function formatRupiah(num) {
        return 'Rp ' + Math.round(num).toLocaleString('id-ID');
    }

    // Tampilkan pesan status feedback
    function showFeedback(msg, isSuccess) {
        feedbackBox.style.display = 'block';
        feedbackBox.style.background = isSuccess ? '#ecfdf5' : '#fef2f2';
        feedbackBox.style.color = isSuccess ? '#065f46' : '#991b1b';
        feedbackBox.style.border = '1px solid ' + (isSuccess ? '#a7f3d0' : '#fecaca');
        feedbackBox.innerHTML = msg;
    }

    // Tambahkan produk ke manifest (atau tambahkan qty jika sudah ada)
    function addProductToManifest(product, qtyToAdd) {
        qtyToAdd = qtyToAdd || state.batchQty;
        const existingIndex = state.manifest.findIndex(item => item.id_produk === product.id_produk);

        if (existingIndex >= 0) {
            // Sudah ada di manifest -> tambahkan jumlah masuk
            state.manifest[existingIndex].jumlah_masuk += qtyToAdd;
            renderManifestTable(product.id_produk);
            showFeedback(`✓ Ditambahkan <strong>+${qtyToAdd} unit</strong> untuk <strong>${product.nama_produk}</strong> (Total: ${state.manifest[existingIndex].jumlah_masuk} unit)`, true);
        } else {
            // Belum ada -> tambahkan baris baru
            state.manifest.push({
                id_produk: product.id_produk,
                kode_produk: product.kode_produk,
                barcode_pabrik: product.barcode_pabrik || product.kode_produk,
                nama_produk: product.nama_produk,
                nama_kategori: product.nama_kategori || 'Tanpa Kategori',
                stok: parseInt(product.stok) || 0,
                harga_beli: parseFloat(product.harga_beli) || 0,
                harga: parseFloat(product.harga) || 0,
                jumlah_masuk: qtyToAdd
            });
            renderManifestTable(product.id_produk);
            showFeedback(`✓ Produk <strong>${product.nama_produk}</strong> berhasil dimasukkan (+${qtyToAdd} unit)`, true);
        }

        playBeep('success');
    }

    // Render ulang tabel manifest
    function renderManifestTable(highlightId) {
        if (state.manifest.length === 0) {
            tbody.innerHTML = `
                <tr id="empty-manifest-row">
                    <td colspan="8" style="padding: 60px 20px; text-align: center; color: #94a3b8;">
                        <div style="font-size: 44px; margin-bottom: 10px;">📦</div>
                        <div style="font-size: 15px; font-weight: 600; color: #475569;">Belum Ada Produk yang Discan</div>
                        <p style="font-size: 12px; margin: 4px auto 0; max-width: 400px;">
                            Gunakan barcode scanner gun atau aktifkan kamera untuk memindai kode produk dari pabrik. Data produk akan otomatis muncul di sini.
                        </p>
                    </td>
                </tr>
            `;
            badgeSku.textContent = '0 SKU';
            summarySku.textContent = '0 SKU';
            summaryQty.textContent = '0 Unit';
            summaryNilai.textContent = 'Rp 0';
            btnSubmit.disabled = true;
            return;
        }

        let totalQty = 0;
        let totalNilai = 0;
        let html = '';

        state.manifest.forEach((item, idx) => {
            totalQty += item.jumlah_masuk;
            totalNilai += (item.harga_beli * item.jumlah_masuk);
            const stokBaru = item.stok + item.jumlah_masuk;
            const isHighlight = highlightId && item.id_produk === highlightId;

            html += `
                <tr data-id="${item.id_produk}" class="${isHighlight ? 'flash-highlight' : ''}" style="border-bottom: 1px solid #edf2f7; font-size: 13px;">
                    <td style="padding: 8px;">${idx + 1}</td>
                    <td style="padding: 8px;">
                        <span class="badge badge-secondary" style="font-family: monospace;">${item.barcode_pabrik || item.kode_produk}</span>
                    </td>
                    <td style="padding: 8px;">
                        <strong>${item.nama_produk}</strong>
                        <div style="font-size: 11px; color: #64748b;">${item.nama_kategori}</div>
                    </td>
                    <td style="padding: 8px; text-align: right; color: #64748b;">
                        ${item.stok} unit
                    </td>
                    <td style="padding: 8px; text-align: center;">
                        <div style="display: inline-flex; align-items: center; gap: 4px;">
                            <button type="button" class="btn btn-secondary btn-sm btn-qty-minus" data-id="${item.id_produk}" style="padding: 2px 8px; font-weight: bold;">-</button>
                            <input type="number" min="1" class="form-control input-manifest-qty" data-id="${item.id_produk}" value="${item.jumlah_masuk}" style="width: 60px; text-align: center; padding: 3px; height: 28px; font-weight: 700;">
                            <button type="button" class="btn btn-secondary btn-sm btn-qty-plus" data-id="${item.id_produk}" style="padding: 2px 8px; font-weight: bold;">+</button>
                        </div>
                    </td>
                    <td style="padding: 8px; text-align: right; font-weight: 700; color: #047857;">
                        ${stokBaru} unit
                    </td>
                    <td style="padding: 8px; text-align: right;">
                        <input type="number" min="0" step="100" class="form-control input-manifest-hb" data-id="${item.id_produk}" value="${item.harga_beli}" style="width: 100px; text-align: right; padding: 3px; height: 28px; font-size: 12px;">
                    </td>
                    <td style="padding: 8px; text-align: center;">
                        <button type="button" class="btn-remove-item" data-id="${item.id_produk}" style="background: none; border: none; color: #ef4444; font-size: 18px; cursor: pointer;" title="Hapus baris">&times;</button>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
        badgeSku.textContent = state.manifest.length + ' SKU';
        summarySku.textContent = state.manifest.length + ' SKU';
        summaryQty.textContent = totalQty.toLocaleString('id-ID') + ' Unit';
        summaryNilai.textContent = formatRupiah(totalNilai);
        btnSubmit.disabled = false;
    }

    // Lookup Barcode via AJAX
    function processBarcode(code) {
        code = (code || '').trim();
        if (!code) return;

        statusIndicator.textContent = 'Mencari...';
        statusIndicator.className = 'badge badge-warning';

        const formData = new FormData();
        formData.append('barcode', code);

        fetch('index.php?page=scan_pabrik&action=ajax_lookup', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            statusIndicator.textContent = 'Siap Scan';
            statusIndicator.className = 'badge badge-success';

            if (data.success && data.found) {
                // Produk Ditemukan
                addProductToManifest(data.product, state.batchQty);
                barcodeInput.value = '';
                barcodeInput.focus();
            } else if (data.success && !data.found) {
                // Produk Baru Belum Terdaftar di Toko
                playBeep('warning');
                openQuickCreateModal(code);
            } else {
                showFeedback('⚠️ ' + (data.message || 'Terjadi kesalahan saat memproses barcode.'), false);
            }
        })
        .catch(err => {
            statusIndicator.textContent = 'Siap Scan';
            statusIndicator.className = 'badge badge-success';
            console.error('Error lookup barcode:', err);
            showFeedback('⚠️ Gagal menghubungi server.', false);
        });
    }

    // Buka Modal Pendaftaran Cepat Produk Baru
    function openQuickCreateModal(barcode) {
        document.getElementById('modal-display-barcode').textContent = barcode;
        document.getElementById('qc_barcode_pabrik').value = barcode;
        document.getElementById('qc_barcode_display').value = barcode;
        document.getElementById('qc_kode_produk').value = '';
        document.getElementById('qc_nama_produk').value = '';
        document.getElementById('qc_harga_beli').value = 0;
        document.getElementById('qc_harga').value = '';
        document.getElementById('qc_stok_awal').value = state.batchQty;

        modalCreate.style.display = 'flex';
        setTimeout(() => {
            document.getElementById('qc_nama_produk').focus();
        }, 100);
    }

    function closeQuickCreateModal() {
        modalCreate.style.display = 'none';
        barcodeInput.value = '';
        barcodeInput.focus();
    }

    // Submit Quick Create Form
    formQuickCreate.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('btn-save-quick-create');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Menyimpan...';

        const formData = new FormData(formQuickCreate);
        formData.append('csrf_token', '<?= csrf_token() ?>');

        fetch('index.php?page=scan_pabrik&action=ajax_quick_create', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Daftarkan & Tambah ke Manifest';

            if (data.success && data.product) {
                closeQuickCreateModal();
                addProductToManifest(data.product, parseInt(document.getElementById('qc_stok_awal').value) || state.batchQty);
                showFeedback(`✨ Berhasil mendaftarkan produk baru <strong>${data.product.nama_produk}</strong> dari pabrik!`, true);
            } else {
                alert(data.message || 'Gagal mendaftarkan produk.');
            }
        })
        .catch(err => {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Daftarkan & Tambah ke Manifest';
            console.error(err);
            alert('Terjadi kesalahan jaringan.');
        });
    });

    document.getElementById('btn-close-create-modal').addEventListener('click', closeQuickCreateModal);
    document.getElementById('btn-cancel-create').addEventListener('click', closeQuickCreateModal);

    // Event Listener Barcode Input (Scanner Gun triggers Enter)
    barcodeInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const code = this.value.trim();
            if (code) {
                processBarcode(code);
            }
        }
    });

    // Batch Qty Selector Buttons (+1, +5, +10, +24)
    document.querySelectorAll('.batch-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.batch-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            state.batchQty = parseInt(this.getAttribute('data-qty')) || 1;
            barcodeInput.focus();
        });
    });

    // Event Delegation untuk Tabel Manifest (Plus, Minus, Edit Qty, Remove, Edit Harga Beli)
    tbody.addEventListener('click', function(e) {
        const minusBtn = e.target.closest('.btn-qty-minus');
        const plusBtn = e.target.closest('.btn-qty-plus');
        const removeBtn = e.target.closest('.btn-remove-item');

        if (minusBtn) {
            const id = parseInt(minusBtn.getAttribute('data-id'));
            const item = state.manifest.find(it => it.id_produk === id);
            if (item && item.jumlah_masuk > 1) {
                item.jumlah_masuk--;
                renderManifestTable();
            }
        } else if (plusBtn) {
            const id = parseInt(plusBtn.getAttribute('data-id'));
            const item = state.manifest.find(it => it.id_produk === id);
            if (item) {
                item.jumlah_masuk++;
                renderManifestTable();
            }
        } else if (removeBtn) {
            const id = parseInt(removeBtn.getAttribute('data-id'));
            state.manifest = state.manifest.filter(it => it.id_produk !== id);
            renderManifestTable();
        }
    });

    tbody.addEventListener('change', function(e) {
        if (e.target.classList.contains('input-manifest-qty')) {
            const id = parseInt(e.target.getAttribute('data-id'));
            const val = parseInt(e.target.value) || 1;
            const item = state.manifest.find(it => it.id_produk === id);
            if (item) {
                item.jumlah_masuk = Math.max(1, val);
                renderManifestTable();
            }
        } else if (e.target.classList.contains('input-manifest-hb')) {
            const id = parseInt(e.target.getAttribute('data-id'));
            const val = parseFloat(e.target.value) || 0;
            const item = state.manifest.find(it => it.id_produk === id);
            if (item) {
                item.harga_beli = Math.max(0, val);
                renderManifestTable();
            }
        }
    });

    // Kosongkan Manifest
    btnClear.addEventListener('click', function() {
        if (state.manifest.length === 0) return;
        if (confirm('Kosongkan semua daftar barang yang telah discan?')) {
            state.manifest = [];
            renderManifestTable();
            showFeedback('Daftar scan dikosongkan.', true);
        }
    });

    // Submit Manifest ke Server
    btnSubmit.addEventListener('click', function() {
        const namaPabrik = document.getElementById('nama_pabrik').value.trim();
        if (!namaPabrik) {
            alert('Harap isi Nama Pabrik / Supplier pengirim terlebih dahulu!');
            document.getElementById('nama_pabrik').focus();
            return;
        }

        if (state.manifest.length === 0) {
            alert('Belum ada produk yang discan.');
            return;
        }

        const totalUnit = state.manifest.reduce((acc, it) => acc + it.jumlah_masuk, 0);
        if (!confirm(`Konfirmasi penerimaan barang dari pabrik:\n\n- Pabrik: ${namaPabrik}\n- Total SKU: ${state.manifest.length} jenis\n- Total Fisik: ${totalUnit} unit\n\nLanjutkan pembaruan stok gudang?`)) {
            return;
        }

        document.getElementById('hidden_nama_pabrik').value = namaPabrik;
        document.getElementById('hidden_no_surat_jalan').value = document.getElementById('no_surat_jalan').value.trim();
        document.getElementById('hidden_tanggal_terima').value = document.getElementById('tanggal_terima').value;
        document.getElementById('hidden_catatan').value = document.getElementById('catatan_penerimaan').value.trim();
        document.getElementById('hidden_items_json').value = JSON.stringify(state.manifest);

        this.disabled = true;
        this.innerHTML = '<span>Menyimpan ke Gudang...</span>';
        document.getElementById('form-save-penerimaan').submit();
    });

    // ================================================================
    // LIVE CAMERA SCANNER (BarcodeDetector API & Video Stream)
    // ================================================================
    let cameraInterval = null;
    let barcodeDetector = null;

    if ('BarcodeDetector' in window) {
        BarcodeDetector.getSupportedFormats()
            .then(formats => {
                barcodeDetector = new BarcodeDetector({ formats: formats });
            })
            .catch(err => console.log('BarcodeDetector error:', err));
    }

    function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Kamera tidak didukung pada browser ini atau koneksi tidak aman (HTTPS/Localhost diperlukan).');
            return;
        }

        navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
        })
        .then(stream => {
            state.cameraStream = stream;
            cameraVideo.srcObject = stream;
            cameraVideo.play();
            state.isScanningCamera = true;
            cameraWrapper.style.display = 'block';
            btnCameraText.textContent = 'Tutup Kamera Scanner';
            btnToggleCamera.classList.remove('btn-secondary');
            btnToggleCamera.classList.add('btn-primary');

            // Jalankan deteksi berulang
            let lastScannedCode = '';
            let lastScanTime = 0;

            cameraInterval = setInterval(() => {
                if (!state.isScanningCamera) return;

                if (barcodeDetector) {
                    barcodeDetector.detect(cameraVideo)
                        .then(barcodes => {
                            if (barcodes.length > 0) {
                                const detected = barcodes[0].rawValue;
                                const now = Date.now();
                                if (detected && (detected !== lastScannedCode || now - lastScanTime > 2500)) {
                                    lastScannedCode = detected;
                                    lastScanTime = now;
                                    processBarcode(detected);
                                }
                            }
                        })
                        .catch(err => {});
                }
            }, 300);
        })
        .catch(err => {
            console.error('Kamera gagal diakses:', err);
            alert('Tidak dapat mengakses kamera: ' + err.message);
        });
    }

    function stopCamera() {
        if (state.cameraStream) {
            state.cameraStream.getTracks().forEach(track => track.stop());
            state.cameraStream = null;
        }
        if (cameraInterval) {
            clearInterval(cameraInterval);
            cameraInterval = null;
        }
        state.isScanningCamera = false;
        cameraWrapper.style.display = 'none';
        btnCameraText.textContent = 'Buka Kamera Scanner';
        btnToggleCamera.classList.remove('btn-primary');
        btnToggleCamera.classList.add('btn-secondary');
    }

    btnToggleCamera.addEventListener('click', function() {
        if (state.isScanningCamera) {
            stopCamera();
        } else {
            startCamera();
        }
    });

    // ================================================================
    // MODAL PENCARIAN MANUAL PRODUK
    // ================================================================
    const btnOpenManual = document.getElementById('btn-open-manual-search');
    const manualSearchInput = document.getElementById('manual-search-input');
    const manualSearchResults = document.getElementById('manual-search-results');

    btnOpenManual.addEventListener('click', function() {
        modalSearch.style.display = 'flex';
        manualSearchInput.value = '';
        manualSearchInput.focus();
        searchProductsManual('');
    });

    document.getElementById('btn-close-search-modal').addEventListener('click', function() {
        modalSearch.style.display = 'none';
        barcodeInput.focus();
    });

    let searchTimeout = null;
    manualSearchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        searchTimeout = setTimeout(() => {
            searchProductsManual(query);
        }, 250);
    });

    function searchProductsManual(q) {
        manualSearchResults.innerHTML = '<div style="padding:15px; text-align:center; color:#64748b;">Mencari produk...</div>';
        fetch('index.php?page=scan_pabrik&action=ajax_search&q=' + encodeURIComponent(q))
            .then(res => res.json())
            .then(data => {
                if (data.success && data.results.length > 0) {
                    let html = '';
                    data.results.forEach(item => {
                        html += `
                            <div class="manual-product-item" data-json='${JSON.stringify(item)}' style="padding: 10px 14px; border-bottom: 1px solid #f1f5f9; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong style="font-size: 13px;">${item.nama_produk}</strong>
                                    <div style="font-size: 11px; color: #64748b;">
                                        Kode: <span style="font-family: monospace;">${item.kode_produk}</span> | 
                                        Barcode: <span style="font-family: monospace;">${item.barcode_pabrik || '-'}</span> | 
                                        Stok: ${item.stok} unit
                                    </div>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm">+ Pilih</button>
                            </div>
                        `;
                    });
                    manualSearchResults.innerHTML = html;
                } else {
                    manualSearchResults.innerHTML = '<div style="padding:20px; text-align:center; color:#94a3b8;">Tidak ada produk yang cocok.</div>';
                }
            })
            .catch(err => {
                manualSearchResults.innerHTML = '<div style="padding:15px; text-align:center; color:#ef4444;">Gagal memuat produk.</div>';
            });
    }

    manualSearchResults.addEventListener('click', function(e) {
        const itemEl = e.target.closest('.manual-product-item');
        if (itemEl) {
            const rawJson = itemEl.getAttribute('data-json');
            try {
                const product = JSON.parse(rawJson);
                addProductToManifest(product, state.batchQty);
                modalSearch.style.display = 'none';
                barcodeInput.focus();
            } catch (err) {
                console.error(err);
            }
        }
    });

    // Auto focus kembali ke input barcode saat klik sembarang tempat jika tidak sedang di input lain
    document.addEventListener('click', function(e) {
        if (modalCreate.style.display === 'flex' || modalSearch.style.display === 'flex') return;
        if (!e.target.closest('input, select, textarea, button, a')) {
            barcodeInput.focus();
        }
    });

})();
</script>

<?php
require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
?>
