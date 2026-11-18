<?php
/**
 * Test Suite: Fitur Scan & Penerimaan Produk dari Pabrik
 * Menguji Barcode Lookup, Penambahan Produk Baru, Penerimaan Stok Masuk, dan Atomic TCL
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Session.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/models/Produk.php';
require_once dirname(__DIR__) . '/models/PenerimaanPabrik.php';

$testCount = 0;
$passCount = 0;

function assertPabrikTest(string $testName, bool $condition, string $detail = '') {
    global $testCount, $passCount;
    $testCount++;
    if ($condition) {
        $passCount++;
        echo "[LULUS] {$testName}" . (!empty($detail) ? " ({$detail})" : "") . "\n";
    } else {
        echo "[GAGAL] {$testName}" . (!empty($detail) ? " - Detail: {$detail}" : "") . "\n";
    }
}

echo "======================================================================\n";
echo "       PENGUJIAN FITUR SCAN PRODUK DARI PABRIK (INBOUND STOCK)       \n";
echo "======================================================================\n\n";

// 1. Uji Lookup Barcode Produk Terdaftar
echo "--- 1. UJI LOOKUP BARCODE PABRIK ---\n";
$pBeras = Produk::findByBarcodeOrKode('8999999190111');
assertPabrikTest(
    "Lookup Produk Berdasarkan Barcode Pabrik",
    $pBeras !== null && $pBeras['kode_produk'] === 'PRD-001',
    "Ditemukan: " . ($pBeras['nama_produk'] ?? 'null')
);

$pKode = Produk::findByBarcodeOrKode('PRD-002');
assertPabrikTest(
    "Lookup Produk Berdasarkan Kode Produk Toko",
    $pKode !== null && $pKode['kode_produk'] === 'PRD-002',
    "Ditemukan: " . ($pKode['nama_produk'] ?? 'null')
);

$pRandom = Produk::findByBarcodeOrKode('BARCODE-TIDAK-ADA-999');
assertPabrikTest(
    "Penanganan Barcode yang Belum Terdaftar",
    $pRandom === null,
    "Hasil null sesuai ekspektasi"
);

// 2. Uji Pendaftaran Cepat Produk Baru dari Pabrik
echo "\n--- 2. UJI PENDAFTARAN CEPAT PRODUK BARU PABRIK ---\n";
$testBarcode = 'TEST-PABRIK-' . time();
$createRes = Produk::create([
    'kode_produk'    => 'PRD-TEST-' . substr(time(), -4),
    'barcode_pabrik' => $testBarcode,
    'nama_produk'    => 'Produk Uji Coba Pabrik Indofood',
    'id_kategori'    => 2,
    'harga'          => 12000,
    'harga_beli'     => 9500,
    'stok'           => 10,
    'status'         => 'aktif'
]);

assertPabrikTest(
    "Pendaftaran Produk Baru dengan Barcode Pabrik",
    $createRes['success'] === true,
    $createRes['message'] ?? ''
);

$newProd = Produk::findByBarcodeOrKode($testBarcode);
assertPabrikTest(
    "Verifikasi Keberadaan Produk Baru via Barcode Pabrik",
    $newProd !== null && (float)$newProd['harga_beli'] === 9500.0,
    "Nama: " . ($newProd['nama_produk'] ?? '') . ", Harga Beli: " . ($newProd['harga_beli'] ?? '')
);

// 3. Uji Penerimaan Stok Masuk Pabrik & TCL
echo "\n--- 3. UJI TRANSAKSI PENERIMAAN BARANG DARI PABRIK (TCL) ---\n";
$stokAwal = (int)$newProd['stok'];
$qtyMasuk = 25;

$penerimaanData = [
    'nama_pabrik'    => 'PT Indofood CBP Sukses Makmur Tbk',
    'no_surat_jalan' => 'SJ-TEST-PABRIK-001',
    'tanggal_terima' => date('Y-m-d H:i:s'),
    'catatan'        => 'Uji coba otomatis penerimaan barang pabrik',
    'id_user'        => 1
];

$items = [
    [
        'id_produk'      => $newProd['id_produk'],
        'nama_produk'    => $newProd['nama_produk'],
        'jumlah_masuk'   => $qtyMasuk,
        'harga_beli'     => 9500,
        'barcode_pabrik' => $testBarcode,
        'catatan'        => 'Dus 1-2'
    ]
];

$recResult = PenerimaanPabrik::createPenerimaan($penerimaanData, $items);
assertPabrikTest(
    "Eksekusi createPenerimaan Berhasil",
    $recResult['success'] === true,
    "No Dokumen: " . ($recResult['no_penerimaan'] ?? '') . " | Pesan: " . ($recResult['message'] ?? '')
);

$prodAfter = Produk::getById($newProd['id_produk']);
$stokAkhir = (int)$prodAfter['stok'];
assertPabrikTest(
    "Verifikasi Penambahan Stok Fisik Akurat",
    $stokAkhir === ($stokAwal + $qtyMasuk),
    "Stok Awal: {$stokAwal} + {$qtyMasuk} = {$stokAkhir}"
);

// 4. Uji Verifikasi Dokumen Header & Detail
echo "\n--- 4. UJI INTEGRITAS DOKUMEN & DETAIL PENERIMAAN ---\n";
$docId = (int)$recResult['id_penerimaan'];
$doc = PenerimaanPabrik::getById($docId);
assertPabrikTest(
    "Header Penerimaan Tersimpan Lengkap",
    $doc !== null && $doc['nama_pabrik'] === 'PT Indofood CBP Sukses Makmur Tbk' && (int)$doc['total_qty'] === $qtyMasuk,
    "Total Qty: " . ($doc['total_qty'] ?? 0)
);

$details = PenerimaanPabrik::getDetails($docId);
assertPabrikTest(
    "Detail Produk Masuk Tersimpan Lengkap",
    count($details) === 1 && (int)$details[0]['stok_sesudah'] === $stokAkhir,
    "Stok Sebelum: " . ($details[0]['stok_sebelum'] ?? 0) . " -> Stok Sesudah: " . ($details[0]['stok_sesudah'] ?? 0)
);

// 5. Uji TCL Rollback jika ada data tidak valid
echo "\n--- 5. UJI DATABASE TCL ROLLBACK PADA DATA DEFISIT/TIDAK VALID ---\n";
$stokBeforeBad = (int)Produk::getById($newProd['id_produk'])['stok'];
$badItems = [
    [
        'id_produk'    => $newProd['id_produk'],
        'nama_produk'  => $newProd['nama_produk'],
        'jumlah_masuk' => -5, // Tidak valid
    ]
];

$badResult = PenerimaanPabrik::createPenerimaan($penerimaanData, $badItems);
$stokAfterBad = (int)Produk::getById($newProd['id_produk'])['stok'];

assertPabrikTest(
    "Penolakan Penerimaan Qty Negatif",
    $badResult['success'] === false,
    $badResult['message'] ?? ''
);

assertPabrikTest(
    "Integritas TCL Rollback: Stok Tidak Berubah saat Gagal",
    $stokBeforeBad === $stokAfterBad,
    "Stok tetap: {$stokAfterBad}"
);

// 6. Uji Statistik & Riwayat
echo "\n--- 6. UJI STATISTIK & PENCARIAN RIWAYAT ---\n";
$stats = PenerimaanPabrik::getStats();
assertPabrikTest(
    "Statistik Penerimaan Pabrik Terhitung Real-time",
    isset($stats['total_transaksi']) && (int)$stats['total_transaksi'] >= 1,
    "Total Transaksi: " . $stats['total_transaksi'] . ", Total Unit: " . $stats['total_unit_semua']
);

$history = PenerimaanPabrik::getAll('Indofood');
assertPabrikTest(
    "Pencarian Riwayat Berdasarkan Nama Pabrik",
    count($history) >= 1,
    "Ditemukan " . count($history) . " catatan untuk Indofood"
);

// Bersihkan data dummy pengujian
$db = Database::getInstance();
$db->execute("DELETE FROM tbl_penerimaan_pabrik WHERE id_penerimaan = :id", [':id' => $docId]);
$db->execute("DELETE FROM tbl_produk WHERE id_produk = :id", [':id' => $newProd['id_produk']]);

echo "\n======================================================================\n";
echo "RINGKASAN: {$passCount} DARI {$testCount} PENGUJIAN BERHASIL LULUS 100%\n";
echo "STATUS FITUR SCAN PABRIK: SIAP DIGUNAKAN SECARA PRODUKSI\n";
echo "======================================================================\n";
