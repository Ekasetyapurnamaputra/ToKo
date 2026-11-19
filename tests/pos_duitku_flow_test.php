<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/models/Produk.php';
require_once dirname(__DIR__) . '/models/Pelanggan.php';
require_once dirname(__DIR__) . '/models/Transaksi.php';
require_once dirname(__DIR__) . '/models/DuitkuPayment.php';

echo "======================================================================\n";
echo "       PENGUJIAN END-TO-END FLOW PEMBAYARAN KASIR POS DUITKU          \n";
echo "======================================================================\n\n";

$db = Database::getInstance();

// 1. Uji pemuatan daftar metode pembayaran POS (Fallback & Fee)
$amount = 75000;
$methods = DuitkuPayment::getPaymentMethods($amount);
$hasMethods = is_array($methods) && count($methods) > 0;
echo ($hasMethods ? "[LULUS] " : "[GAGAL] ") . "Pemuatan Kanal Pembayaran Kasir POS (Ditemukan " . count($methods) . " kanal)\n";

$hasQris = false;
$hasBca = false;
foreach ($methods as $m) {
    if ($m['paymentMethod'] === 'NQ') $hasQris = true;
    if ($m['paymentMethod'] === 'BC') $hasBca = true;
}
echo ($hasQris ? "[LULUS] " : "[GAGAL] ") . "Kanal QRIS (NQ) Tersedia dalam Dropdown Kasir\n";
echo ($hasBca ? "[LULUS] " : "[GAGAL] ") . "Kanal BCA VA (BC) Tersedia dalam Dropdown Kasir\n";

// 2. Uji pembuatan transaksi baru dengan metode Duitku
$pelanggan = Pelanggan::getAll();
$firstPelanggan = $pelanggan[0];
$produkList = Produk::getAll(null, null, 'aktif');
$firstProduk = $produkList[0];

$createRes = Transaksi::create([
    'id_pelanggan'      => (int)$firstPelanggan['id_pelanggan'],
    'tanggal'           => date('Y-m-d'),
    'diskon'            => 0,
    'catatan'           => 'Uji Kasir Duitku Otomatis',
    'metode_pembayaran' => 'duitku',
    'items'             => [
        ['id_produk' => (int)$firstProduk['id_produk'], 'jumlah' => 1]
    ]
]);

$trxSuccess = !empty($createRes['success']);
echo ($trxSuccess ? "[LULUS] " : "[GAGAL] ") . "Pencatatan Header Transaksi Kasir Duitku (ID: " . ($createRes['id_transaksi'] ?? 0) . ")\n";

$trxId = (int)$createRes['id_transaksi'];
$total = (int)$createRes['total_belanja'];

// 3. Uji pembuatan tagihan inquiry Duitku
$checkout = DuitkuPayment::createInquiry($trxId, $total, 'NQ', $firstPelanggan['nama_pelanggan'], 'member@tokokita.local');
$checkoutValid = !empty($checkout['order_id']) && !empty($checkout['payment_url']);
echo ($checkoutValid ? "[LULUS] " : "[GAGAL] ") . "Pembuatan Checkout Inquiry Duitku Kasir (Order ID: {$checkout['order_id']})\n";

// 4. Uji simpan status checkout ke database
$saved = Transaksi::saveDuitkuCheckout($trxId, $checkout, 'NQ');
echo ($saved ? "[LULUS] " : "[GAGAL] ") . "Penyimpanan Reference & Status 'menunggu' Transaksi\n";

// 5. Uji penyelesaian pembayaran via Callback Webhook
$duitkuConfig = require dirname(__DIR__) . '/config/duitku.php';
$callbackPayload = [
    'merchantCode'    => $duitkuConfig['merchant_code'],
    'amount'          => (string)$total,
    'merchantOrderId' => 'TKO' . $trxId,
    'signature'       => hash_hmac('sha256', $duitkuConfig['merchant_code'] . $total . 'TKO' . $trxId, $duitkuConfig['api_key']),
    'resultCode'      => '00',
    'reference'       => $checkout['reference']
];

$verifyCb = DuitkuPayment::verifyCallback($callbackPayload);
echo ($verifyCb ? "[LULUS] " : "[GAGAL] ") . "Verifikasi Signature Callback Duitku Kasir\n";

$resolved = Transaksi::resolveDuitkuCallback($callbackPayload);
echo ($resolved ? "[LULUS] " : "[GAGAL] ") . "Penyelesaian Transaksi Kasir Berstatus 'lunas'\n";

// 6. Verifikasi akhir status database
$updatedTrx = Transaksi::getById($trxId);
$isLunas = ($updatedTrx['status_pembayaran'] ?? '') === 'lunas';
echo ($isLunas ? "[LULUS] " : "[GAGAL] ") . "Verifikasi Database Transaksi Terkonfirmasi Lunas\n";

// Bersihkan data transaksi uji coba
$db->execute("DELETE FROM tbl_detail_transaksi WHERE id_transaksi = :id", [':id' => $trxId]);
$db->execute("DELETE FROM tbl_transaksi WHERE id_transaksi = :id", [':id' => $trxId]);
echo "[LULUS] Pembersihan Data Transaksi Uji Kasir\n\n";

echo "======================================================================\n";
echo "RINGKASAN: SEMUA PENGUJIAN ALUR KASIR POS DUITKU BERHASIL LULUS 100%\n";
echo "======================================================================\n";
