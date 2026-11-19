<?php

require_once dirname(__DIR__) . '/models/DuitkuPayment.php';

$merchantCode = 'DTEST';
$apiKey = 'test-secret-key';
$orderId = 'TKO123';
$amount = '25000';
putenv('DUITKU_MERCHANT_CODE=' . $merchantCode);
putenv('DUITKU_API_KEY=' . $apiKey);

$callback = [
    'merchantCode' => $merchantCode,
    'amount' => $amount,
    'merchantOrderId' => $orderId,
    'signature' => hash_hmac('sha256', $merchantCode . $amount . $orderId, $apiKey),
];

$tests = [
    'Menerima tanda tangan callback Duitku yang valid' => DuitkuPayment::verifyCallback($callback),
    'Menolak tanda tangan callback yang salah' => !DuitkuPayment::verifyCallback(array_merge($callback, ['signature' => 'invalid'])),
    'Menolak merchant code yang berbeda' => !DuitkuPayment::verifyCallback(array_merge($callback, ['merchantCode' => 'DOTHER'])),
    'Menolak nominal yang diubah' => !DuitkuPayment::verifyCallback(array_merge($callback, ['amount' => '25001'])),
    'Menolak nomor pesanan yang diubah' => !DuitkuPayment::verifyCallback(array_merge($callback, ['merchantOrderId' => 'TKO124'])),
];

$failed = 0;
foreach ($tests as $name => $passed) {
    echo ($passed ? '[LULUS] ' : '[GAGAL] ') . $name . PHP_EOL;
    if (!$passed) {
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
