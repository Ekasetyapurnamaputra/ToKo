<?php
/**
 * Webhook Callback Receiver dari Duitku Payment Gateway
 * Endpoint ini dipanggil secara asinkron (Server-to-Server) oleh server Duitku saat status pembayaran berubah
 * Dokumentasi: https://docs.duitku.com/
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Database.php';
require_once dirname(dirname(__DIR__)) . '/core/Logger.php';
require_once dirname(dirname(__DIR__)) . '/models/Wallet.php';
require_once dirname(dirname(__DIR__)) . '/core/Duitku.php';

// Tangkap payload POST (baik form-urlencoded maupun JSON)
$inputRaw = file_get_contents('php://input');
$postData = $_POST;

if (empty($postData) && !empty($inputRaw)) {
    $decoded = json_decode($inputRaw, true);
    if (is_array($decoded)) {
        $postData = $decoded;
    }
}

if (empty($postData)) {
    http_response_code(400);
    echo "Bad Request: Data callback tidak ditemukan.";
    exit;
}

$result = Wallet::processDuitkuCallback($postData);

if ($result['success']) {
    if (!empty($postData['redirect_return'])) {
        Session::setFlash('success', 'Pembayaran via Duitku berhasil diverifikasi dan saldo kartu member telah otomatis bertambah!');
        Session::remove('duitku_active_invoice');
        header('Location: ' . clean($postData['redirect_return']));
        exit;
    }
    http_response_code(200);
    // Duitku mengharapkan response HTTP 200 OK
    echo "OK";
} else {
    http_response_code($result['code'] ?? 400);
    echo "Callback Error: " . ($result['message'] ?? 'Gagal memproses callback');
}
exit;
