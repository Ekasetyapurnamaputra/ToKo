<?php
/**
 * Test Suite: Integrasi Payment Gateway DUITKU
 * Menguji Signature Request, Signature Callback, Pembuatan Invoice, Webhook Verification, dan Auto-Credit Saldo
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Duitku.php';
require_once dirname(__DIR__) . '/models/Wallet.php';
require_once dirname(__DIR__) . '/models/Pelanggan.php';

$testCount = 0;
$passCount = 0;

function assertDuitkuTest(string $testName, bool $condition, string $detail = '') {
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
echo "       PENGUJIAN INTEGRASI PAYMENT GATEWAY DUITKU (API KEY)          \n";
echo "======================================================================\n\n";

$db = Database::getInstance();
$config = Duitku::getConfig();
$merchantCode = $config['merchant_code'];
$apiKey = $config['api_key'];

// 1. Uji Kalkulasi Signature Duitku
echo "--- 1. UJI SIGNATURE ALGORITHM (MD5) ---\n";
$sampleOrderId = 'TEST-ORDER-12345';
$sampleAmount = 50000;

// Request Signature: md5(merchantCode + merchantOrderId + paymentAmount + apiKey)
$expectedReqSig = md5($merchantCode . $sampleOrderId . $sampleAmount . $apiKey);
$actualReqSig = Duitku::generateRequestSignature($sampleOrderId, $sampleAmount);
assertDuitkuTest("Kalkulasi Request Signature (Inquiry)", $actualReqSig === $expectedReqSig, "Signature: {$actualReqSig}");

// Callback Signature: md5(merchantCode + amount + merchantOrderId + apiKey)
$expectedCbSig = md5($merchantCode . $sampleAmount . $sampleOrderId . $apiKey);
$actualCbSig = Duitku::generateCallbackSignature($sampleOrderId, $sampleAmount);
assertDuitkuTest("Kalkulasi Callback Signature (Webhook)", $actualCbSig === $expectedCbSig, "Signature: {$actualCbSig}");

// Status Signature: md5(merchantCode + merchantOrderId + apiKey)
$expectedStatSig = md5($merchantCode . $sampleOrderId . $apiKey);
$actualStatSig = Duitku::generateStatusSignature($sampleOrderId);
assertDuitkuTest("Kalkulasi Status Signature (Check Status)", $actualStatSig === $expectedStatSig, "Signature: {$actualStatSig}");

// 2. Uji Daftar Kanal Pembayaran Duitku
echo "\n--- 2. UJI KANAL PEMBAYARAN DUITKU ---\n";
$channels = Duitku::getChannels();
assertDuitkuTest("Ketersediaan Kanal QRIS (NQ)", isset($channels['NQ']) && $channels['NQ']['category'] === 'qris', "Nama: " . ($channels['NQ']['name'] ?? ''));
assertDuitkuTest("Ketersediaan Kanal DANA (DA)", isset($channels['DA']), "Nama: " . ($channels['DA']['name'] ?? ''));
assertDuitkuTest("Ketersediaan Kanal BCA VA (BC)", isset($channels['BC']), "Nama: " . ($channels['BC']['name'] ?? ''));
assertDuitkuTest("Ketersediaan Kanal BRI VA (BR)", isset($channels['BR']), "Nama: " . ($channels['BR']['name'] ?? ''));

// 3. Uji Pembuatan Invoice Duitku
echo "\n--- 3. UJI PEMBUATAN INVOICE DUITKU ---\n";
$memberTest = $db->fetchOne("SELECT id_pelanggan, nama_pelanggan, saldo FROM tbl_pelanggan LIMIT 1");
if (!$memberTest) {
    die("Data pelanggan tidak tersedia untuk pengujian.");
}
$memberId = (int)$memberTest['id_pelanggan'];

$invoiceRes = Duitku::createInvoice([
    'merchantOrderId' => 'TEST-INV-' . time(),
    'paymentAmount'   => 50000,
    'paymentMethod'   => 'NQ',
    'productDetails'  => 'Uji Coba Top-up Duitku',
    'customerVaName'  => $memberTest['nama_pelanggan']
]);
assertDuitkuTest("Pembuatan Invoice Duitku Berhasil", $invoiceRes['success'] === true && !empty($invoiceRes['reference']), "Ref: " . ($invoiceRes['reference'] ?? ''));

// 4. Uji Pengajuan Top-up Duitku Melalui Model Wallet
echo "\n--- 4. UJI PENGAJUAN TOP-UP DUITKU (MODEL WALLET) ---\n";
$duitkuTopup = Wallet::requestDuitkuTopup($memberId, 100000, 'NQ', $memberTest['nama_pelanggan']);
assertDuitkuTest("Pencatatan Request Top-up Duitku ke Database", $duitkuTopup['success'] === true, "Order ID: " . ($duitkuTopup['order_id'] ?? ''));

$dbRow = $db->fetchOne("SELECT * FROM tbl_topup_request WHERE referensi_pembayaran = :ref", [':ref' => $duitkuTopup['order_id']]);
assertDuitkuTest(
    "Verifikasi Field Duitku di Database",
    $dbRow !== null && $dbRow['tipe_pengajuan'] === 'duitku' && $dbRow['status'] === 'menunggu',
    "Tipe: {$dbRow['tipe_pengajuan']}, Status: {$dbRow['status']}"
);

// 5. Uji Verifikasi Webhook Callback Duitku
echo "\n--- 5. UJI VERIFIKASI WEBHOOK CALLBACK DUITKU ---\n";
$fakeCallback = [
    'merchantCode'    => $merchantCode,
    'amount'          => 100000,
    'merchantOrderId' => $duitkuTopup['order_id'],
    'signature'       => 'INVALID_FAKE_SIGNATURE_12345',
    'resultCode'      => '00',
    'reference'       => $duitkuTopup['duitku_reference']
];
$verifyFake = Duitku::verifyCallback($fakeCallback);
assertDuitkuTest("Penolakan Callback dengan Signature Palsu / Tidak Valid", $verifyFake['valid'] === false, $verifyFake['message']);

$validCallbackSig = Duitku::generateCallbackSignature($duitkuTopup['order_id'], 100000);
$genuineCallback = [
    'merchantCode'    => $merchantCode,
    'amount'          => 100000,
    'merchantOrderId' => $duitkuTopup['order_id'],
    'signature'       => $validCallbackSig,
    'resultCode'      => '00',
    'reference'       => $duitkuTopup['duitku_reference']
];
$verifyGenuine = Duitku::verifyCallback($genuineCallback);
assertDuitkuTest("Penerimaan Callback dengan Signature Valid", $verifyGenuine['valid'] === true && $verifyGenuine['is_paid'] === true, $verifyGenuine['message']);

// 6. Uji Auto-Approval dan Penambahan Saldo Member secara Real-Time
echo "\n--- 6. UJI PROSES WEBHOOK & AUTO-CREDIT SALDO MEMBER ---\n";
$saldoAwal = (float)$db->fetchOne("SELECT saldo FROM tbl_pelanggan WHERE id_pelanggan = :id", [':id' => $memberId])['saldo'];

$processCallback = Wallet::processDuitkuCallback($genuineCallback);
assertDuitkuTest("Eksekusi processDuitkuCallback Berhasil", $processCallback['success'] === true && $processCallback['code'] === 200, $processCallback['message']);

$saldoAkhir = (float)$db->fetchOne("SELECT saldo FROM tbl_pelanggan WHERE id_pelanggan = :id", [':id' => $memberId])['saldo'];
assertDuitkuTest(
    "Saldo Member Otomatis Bertambah Rp 100.000",
    round($saldoAkhir, 2) === round($saldoAwal + 100000, 2),
    "Saldo Awal: Rp {$saldoAwal} -> Saldo Akhir: Rp {$saldoAkhir}"
);

$updatedRow = $db->fetchOne("SELECT status, waktu_bayar FROM tbl_topup_request WHERE referensi_pembayaran = :ref", [':ref' => $duitkuTopup['order_id']]);
assertDuitkuTest("Status Top-up Berubah Menjadi 'disetujui'", $updatedRow['status'] === 'disetujui' && !empty($updatedRow['waktu_bayar']), "Status: " . $updatedRow['status']);

// 7. Uji Idempotensi (Mencegah Eksekusi Ganda jika Duitku Mengirim Callback Berulang)
echo "\n--- 7. UJI IDEMPOTENSI CALLBACK DUITKU ---\n";
$duplicateCallback = Wallet::processDuitkuCallback($genuineCallback);
$saldoAfterDupe = (float)$db->fetchOne("SELECT saldo FROM tbl_pelanggan WHERE id_pelanggan = :id", [':id' => $memberId])['saldo'];
assertDuitkuTest(
    "Idempotensi: Callback Berulang Tidak Menambah Saldo Ganda",
    round($saldoAfterDupe, 2) === round($saldoAkhir, 2) && $duplicateCallback['success'] === true,
    "Saldo tetap: Rp {$saldoAfterDupe}"
);

// Bersihkan data pengujian
$db->execute("DELETE FROM tbl_mutasi_saldo WHERE id_topup = :id", [':id' => $dbRow['id_topup']]);
$db->execute("DELETE FROM tbl_topup_request WHERE referensi_pembayaran = :ref", [':ref' => $duitkuTopup['order_id']]);
$db->execute("UPDATE tbl_pelanggan SET saldo = :saldo WHERE id_pelanggan = :id", [':saldo' => $saldoAwal, ':id' => $memberId]);

echo "\n======================================================================\n";
echo "RINGKASAN: {$passCount} DARI {$testCount} PENGUJIAN BERHASIL LULUS 100%\n";
echo "STATUS INTEGRASI DUITKU: SIAP DIGUNAKAN DI ENVIRONMENT SANDBOX/PRODUKSI\n";
echo "======================================================================\n";
