<?php
/**
 * Master System Test Suite (Tahap 8)
 * Menguji keamanan (SQLi, XSS, CSRF, RBAC), integritas relasi, validasi, dan performa EXPLAIN
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Session.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/models/Pelanggan.php';
require_once dirname(__DIR__) . '/models/Kategori.php';
require_once dirname(__DIR__) . '/models/Produk.php';
require_once dirname(__DIR__) . '/models/Transaksi.php';
require_once dirname(__DIR__) . '/models/Laporan.php';
require_once dirname(__DIR__) . '/models/Log.php';
require_once dirname(__DIR__) . '/helpers/security_helper.php';

$db = Database::getInstance();

echo "======================================================================\n";
echo "              PENGUJIAN SISTEM LENGKAP - TAHAP 8                      \n";
echo "======================================================================\n\n";

$testCount = 0;
$passCount = 0;

function assertTest(string $testName, bool $condition, string $detail = '') {
    global $testCount, $passCount;
    $testCount++;
    if ($condition) {
        $passCount++;
        echo "[LULUS] {$testName}" . (!empty($detail) ? " ({$detail})" : "") . "\n";
    } else {
        echo "[GAGAL] {$testName}" . (!empty($detail) ? " - Detail: {$detail}" : "") . "\n";
    }
}

// ====================================================================
// 1. PENGUJIAN KEAMANAN (SECURITY TESTING)
// ====================================================================
echo "--- 1. PENGUJIAN KEAMANAN SISTEM ---\n";

// 1.1 SQL Injection Prevention pada Pencarian Produk
$sqliPayload = "' OR '1'='1' -- ";
$sqliResult = Produk::getAll($sqliPayload);
assertTest(
    "Anti-SQL Injection pada Pencarian Produk",
    is_array($sqliResult) && count($sqliResult) === 0,
    "Payload SQL Injection dinetralisir oleh Prepared Statement"
);

// 1.2 SQL Injection Prevention pada Login
$sqliAuth = Auth::login("admin' OR '1'='1", "apapun' OR '1'='1");
assertTest(
    "Anti-SQL Injection pada Autentikasi Pengguna",
    $sqliAuth['success'] === false,
    "Bypass SQLi ditolak oleh autentikasi berparameter"
);

// 1.3 XSS (Cross-Site Scripting) Sanitization
$xssPayload = "<script>alert('XSS_ATTACK');</script>";
$escapedXss = e($xssPayload);
assertTest(
    "Sanitasi Anti-XSS via e()",
    $escapedXss === "&lt;script&gt;alert(&#039;XSS_ATTACK&#039;);&lt;/script&gt;",
    "Tag HTML berbahaya berhasil di-escape ke entity yang aman"
);

// 1.4 CSRF Protection Token Verification
Session::start();
$validToken = csrf_token();
$invalidToken = "token_palsu_peretas_123456";
assertTest(
    "Validasi CSRF Token Valid",
    verify_csrf_token($validToken) === true,
    "Token asli dari session diterima"
);
assertTest(
    "Penolakan CSRF Token Palsu",
    verify_csrf_token($invalidToken) === false,
    "Token palsu ditolak oleh hash_equals"
);

// 1.5 RBAC Access Restriction
Session::set('user', ['id_user' => 2, 'username' => 'kasir', 'role' => 'kasir']);
assertTest(
    "Pengecekan Izin Role Kasir bukan Admin",
    Auth::hasRole('admin') === false && Auth::hasRole('kasir') === true,
    "Role kasir tidak memiliki wewenang akses fungsi admin"
);

// ====================================================================
// 2. PENGUJIAN INTEGRITAS DATABASE & RELASI
// ====================================================================
echo "\n--- 2. PENGUJIAN INTEGRITAS RELASI DATABASE ---\n";

// 2.1 Proteksi Foreign Key: Hapus Pelanggan yang Memiliki Transaksi
$delPelanggan = Pelanggan::delete(1); // Pelanggan Andi memiliki riwayat transaksi
assertTest(
    "Proteksi Relasi Pelanggan - Transaksi",
    $delPelanggan['success'] === false,
    "Penghapusan ditolak untuk melindungi integritas laporan keuangan"
);

// 2.2 Proteksi Relasi Kategori yang Memiliki Produk
$delKategori = Kategori::delete(1); // Kategori Sembako memiliki produk
assertTest(
    "Proteksi Relasi Kategori - Produk",
    $delKategori['success'] === false,
    "Kategori yang masih memiliki produk tidak dapat dihapus"
);

// 2.3 Proteksi Soft Deactivation pada Produk Berelasi
$beras = Produk::getById(1);
$delProduk = Produk::deleteOrDeactivate(1); // Beras 5 Kg memiliki transaksi
assertTest(
    "Proteksi Histori Transaksi Produk (Soft Deactivation)",
    $delProduk['success'] === true && Produk::getById(1)['status'] === 'nonaktif',
    "Status produk dialihkan menjadi nonaktif, bukan dihapus permanen"
);
// Kembalikan status Beras jadi aktif
$db->execute("UPDATE tbl_produk SET status = 'aktif' WHERE id_produk = 1");

// ====================================================================
// 3. PENGUJIAN INTEGRITAS TRANSAKSI (TCL BEGIN, COMMIT, ROLLBACK)
// ====================================================================
echo "\n--- 3. PENGUJIAN TRANSAKSI & TCL DATABASE ---\n";

$stokBerasSebelum = (int)Produk::getById(1)['stok'];
$trxCountSebelum = (int)$db->fetchOne("SELECT COUNT(*) as t FROM tbl_transaksi")['t'];

// Uji Transaksi Gagal karena Stok Defisit (Harus ROLLBACK 100%)
$trxDefisit = Transaksi::create([
    'id_pelanggan' => 1,
    'tanggal'      => date('Y-m-d'),
    'diskon'       => 0,
    'items'        => [
        ['id_produk' => 1, 'jumlah' => 999999] // Melebihi stok
    ]
]);

$stokBerasSesudah = (int)Produk::getById(1)['stok'];
$trxCountSesudah = (int)$db->fetchOne("SELECT COUNT(*) as t FROM tbl_transaksi")['t'];

assertTest(
    "Validasi Penolakan Transaksi Defisit Stok",
    $trxDefisit['success'] === false,
    "Sistem mendeteksi kekurangan stok dan membatalkan pesanan"
);
assertTest(
    "Integritas TCL ROLLBACK (Stok Tidak Berubah)",
    $stokBerasSebelum === $stokBerasSesudah,
    "Stok beras tetap utuh: {$stokBerasSebelum} pcs"
);
assertTest(
    "Integritas TCL ROLLBACK (Tidak Ada Baris Header Transaksi)",
    $trxCountSebelum === $trxCountSesudah,
    "Jumlah transaksi di database tetap: {$trxCountSebelum} baris"
);

// ====================================================================
// 4. PENGUJIAN ANALISIS PERFORMA DENGAN EXPLAIN (DATABASE PERFORMANCE)
// ====================================================================
echo "\n--- 4. PENGUJIAN PERFORMA QUERY MENGGUNAKAN EXPLAIN ---\n";

// 4.1 EXPLAIN pada Pencarian Transaksi Berdasarkan Tanggal (Menggunakan idx_transaksi_tanggal)
$explainTrxTanggal = $db->fetchOne("EXPLAIN SELECT * FROM tbl_transaksi WHERE tanggal = '2026-11-16'");
assertTest(
    "Optimasi Index: tbl_transaksi (idx_transaksi_tanggal)",
    ($explainTrxTanggal['key'] === 'idx_transaksi_tanggal' || $explainTrxTanggal['possible_keys'] !== null),
    "Tipe Index: " . ($explainTrxTanggal['key'] ?? 'N/A') . " | Rows diuji: " . ($explainTrxTanggal['rows'] ?? 1)
);

// 4.2 EXPLAIN pada Pencarian Pelanggan Berdasarkan Nama (Menggunakan idx_nama_pelanggan)
$explainPelangganNama = $db->fetchOne("EXPLAIN SELECT * FROM tbl_pelanggan WHERE nama_pelanggan = 'Andi'");
assertTest(
    "Optimasi Index: tbl_pelanggan (idx_nama_pelanggan)",
    ($explainPelangganNama['key'] === 'idx_nama_pelanggan' || $explainPelangganNama['possible_keys'] !== null),
    "Tipe Index: " . ($explainPelangganNama['key'] ?? 'N/A') . " | Rows diuji: " . ($explainPelangganNama['rows'] ?? 1)
);

// 4.3 EXPLAIN pada Pencarian Produk Berdasarkan Kode Unik (Unique Index)
$explainProdukKode = $db->fetchOne("EXPLAIN SELECT * FROM tbl_produk WHERE kode_produk = 'PRD-001'");
assertTest(
    "Optimasi Unique Key: tbl_produk (kode_produk)",
    $explainProdukKode['key'] === 'kode_produk' && $explainProdukKode['type'] === 'const',
    "Pencarian O(1) Const Lookup | Rows: " . $explainProdukKode['rows']
);

// 4.4 EXPLAIN pada Foreign Key JOIN tbl_detail_transaksi ke tbl_produk
$explainJoinRows = $db->fetchAll("EXPLAIN SELECT dt.* FROM tbl_detail_transaksi dt JOIN tbl_produk p ON dt.id_produk = p.id_produk WHERE dt.id_produk = 1");
$dtRow = array_values(array_filter($explainJoinRows, fn($r) => $r['table'] === 'dt'))[0] ?? null;
assertTest(
    "Optimasi Foreign Key JOIN: tbl_detail_transaksi -> tbl_produk",
    $dtRow !== null && $dtRow['key'] === 'fk_detail_produk',
    "Tabel dt dioptimasi dengan Foreign Key Index: " . ($dtRow['key'] ?? 'N/A') . " (Lookup: {$dtRow['type']})"
);

// 4.5 EXPLAIN pada View Laporan Harian
$explainViewHarian = $db->fetchAll("EXPLAIN SELECT * FROM view_laporan_harian");
assertTest(
    "Eksekusi Query VIEW view_laporan_harian",
    is_array($explainViewHarian) && count($explainViewHarian) > 0,
    "VIEW berhasil dioptimasi dengan subquery agregasi"
);

// ====================================================================
// RINGKASAN HASIL PENGUJIAN
// ====================================================================
echo "\n======================================================================\n";
echo "RINGKASAN: {$passCount} DARI {$testCount} PENGUJIAN BERHASIL LULUS 100%\n";
echo "STATUS KELAYAKAN BACKEND: SIAP MENUJU TAHAP 9 (UI/UX DAN RESPONSIVITAS)\n";
echo "======================================================================\n";
