<?php
/**
 * Modul Transaksi Penjualan & Riwayat Kasir
 * Desain POS Modern: Katalog Grid Interaktif, Barcode Scanner, Keranjang Belanja Dinamis,
 * Integrasi Payment Gateway Duitku (QRIS & VA), dan Riwayat Transaksi Terpadu.
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/Transaksi.php';
require_once dirname(dirname(__DIR__)) . '/models/Pelanggan.php';
require_once dirname(dirname(__DIR__)) . '/models/Produk.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/barcode_helper.php';
require_once dirname(dirname(__DIR__)) . '/models/MemberAccount.php';
require_once dirname(dirname(__DIR__)) . '/models/DuitkuPayment.php';
require_once dirname(dirname(__DIR__)) . '/core/Duitku.php';

$action = $_GET['action'] ?? 'index';
$error = '';

// ====================================================================
// 1. DUITKU CALLBACK WEBHOOK
// ====================================================================
if ($action === 'duitku_callback') {
    if (!is_post() || !DuitkuPayment::verifyCallback($_POST)) {
        http_response_code(400);
        echo 'FAILED';
        exit;
    }

    try {
        if (!Transaksi::resolveDuitkuCallback($_POST)) {
            http_response_code(400);
            echo 'FAILED';
            exit;
        }
        if (!empty($_POST['redirect_return'])) {
            Session::setFlash('success', 'Pembayaran transaksi berhasil dikonfirmasi lunas.');
            header('Location: ' . clean($_POST['redirect_return']));
            exit;
        }
        echo 'OK';
    } catch (\Throwable $exception) {
        error_log('[Duitku callback] ' . $exception->getMessage());
        http_response_code(500);
        echo 'FAILED';
    }
    exit;
}

Auth::requireLogin();

if (Auth::hasRole('pelanggan')) {
    if (!in_array($action, ['struk', 'detail'], true) || is_post()) {
        header('Location: index.php?page=member');
        exit;
    }

    $memberId = (int)(Auth::user()['id_pelanggan'] ?? 0);
    if ($memberId <= 0) {
        $m = MemberAccount::getMemberForUser((int)Auth::id());
        $memberId = (int)($m['id_pelanggan'] ?? 0);
    }

    $ownsReceipt = \Database::getInstance()->fetchOne(
        'SELECT id_transaksi FROM tbl_transaksi WHERE id_transaksi = :id AND id_pelanggan = :member_id',
        [
            ':id' => (int)($_GET['id'] ?? 0),
            ':member_id' => $memberId
        ]
    );
    if (!$ownsReceipt) {
        http_response_code(404);
        Session::setFlash('danger', 'Struk transaksi tidak ditemukan.');
        header('Location: index.php?page=member');
        exit;
    }
} else {
    Auth::requireRole(['admin', 'kasir']);
}

Transaksi::expireDuitkuPayments();

// ====================================================================
// 2. AJAX ENDPOINTS: DUITKU METHODS & STATUS CHECK
// ====================================================================
if ($action === 'duitku_methods') {
    header('Content-Type: application/json; charset=utf-8');
    $amount = filter_var($_GET['amount'] ?? null, FILTER_VALIDATE_INT);
    if ($amount === false || $amount <= 0) {
        http_response_code(422);
        echo json_encode(['message' => 'Nominal pembayaran tidak valid.']);
        exit;
    }

    try {
        echo json_encode(['methods' => DuitkuPayment::getPaymentMethods($amount)], JSON_THROW_ON_ERROR);
    } catch (\Throwable $exception) {
        error_log('[Duitku payment methods] ' . $exception->getMessage());
        http_response_code(502);
        echo json_encode(['message' => 'Metode pembayaran Duitku tidak dapat dimuat. Periksa konfigurasi gateway.']);
    }
    exit;
}

if ($action === 'check_status_ajax') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $transaksi = Transaksi::getById($id);
    if (!$transaksi || ($transaksi['metode_pembayaran'] ?? '') !== 'duitku') {
        echo json_encode(['status' => 'not_found']);
        exit;
    }

    if (($transaksi['status_pembayaran'] ?? '') === 'lunas') {
        echo json_encode(['status' => 'lunas']);
        exit;
    }

    // Cek status secara live ke server Duitku
    $orderId = 'TKO' . $transaksi['id_transaksi'];
    $statusRes = Duitku::checkTransactionStatus($orderId);
    if ($statusRes['success'] && ($statusRes['status_code'] ?? '') === '00') {
        $duitkuCfg = Duitku::getConfig();
        $callbackPayload = [
            'merchantCode'    => $duitkuCfg['merchant_code'],
            'amount'          => (string)(int)$transaksi['total_belanja'],
            'merchantOrderId' => $orderId,
            'signature'       => hash_hmac('sha256', $duitkuCfg['merchant_code'] . (int)$transaksi['total_belanja'] . $orderId, $duitkuCfg['api_key']),
            'resultCode'      => '00',
            'reference'       => $transaksi['duitku_reference']
        ];
        Transaksi::resolveDuitkuCallback($callbackPayload);
        echo json_encode(['status' => 'lunas', 'message' => 'Pembayaran berhasil dikonfirmasi']);
        exit;
    }

    echo json_encode([
        'status' => $transaksi['status_pembayaran'] ?? 'menunggu',
        'message' => $statusRes['status_message'] ?? 'Menunggu pembayaran'
    ]);
    exit;
}

// ====================================================================
// 3. HALAMAN INSTRUKSI PEMBAYARAN DUITKU (RETURN / DETAIL VA / QRIS)
// ====================================================================
if ($action === 'payment_return') {
    $id = (int)($_GET['id'] ?? 0);
    $transaksi = Transaksi::getById($id);
    if (!$transaksi || ($transaksi['metode_pembayaran'] ?? '') !== 'duitku') {
        Session::setFlash('danger', 'Transaksi pembayaran tidak ditemukan.');
        header('Location: index.php?page=transaksi');
        exit;
    }

    $duitkuCfg = Duitku::getConfig();

    if (($transaksi['status_pembayaran'] ?? '') === 'menunggu') {
        $orderId = 'TKO' . $transaksi['id_transaksi'];
        $statusRes = Duitku::checkTransactionStatus($orderId);
        if ($statusRes['success'] && ($statusRes['status_code'] ?? '') === '00') {
            $callbackPayload = [
                'merchantCode'    => $duitkuCfg['merchant_code'],
                'amount'          => (string)(int)$transaksi['total_belanja'],
                'merchantOrderId' => $orderId,
                'signature'       => hash_hmac('sha256', $duitkuCfg['merchant_code'] . (int)$transaksi['total_belanja'] . $orderId, $duitkuCfg['api_key']),
                'resultCode'      => '00',
                'reference'       => $transaksi['duitku_reference']
            ];
            Transaksi::resolveDuitkuCallback($callbackPayload);
            $transaksi = Transaksi::getById($id);
        }
    }

    $channelCode = $transaksi['duitku_payment_method'] ?? '';
    $channelInfo = $duitkuCfg['channels'][$channelCode] ?? null;
    $channelName = $channelInfo['name'] ?? ($channelCode ? strtoupper($channelCode) : 'Payment Gateway Duitku');
    $isQris = ($channelCode === 'NQ');
    $isVa = in_array($channelCode, ['BC', 'BR', 'M1', 'I1', 'VA', 'BT', 'B1'], true) || ($channelInfo['category'] ?? '') === 'va';

    $vaNumber = $transaksi['duitku_va_number'] ?? '';
    if (empty($vaNumber) && $isVa) {
        $vaPrefixes = [
            'BC' => '88301',
            'BR' => '01230',
            'M1' => '89022',
            'I1' => '98801',
            'VA' => '7812',
            'BT' => '92001',
            'B1' => '10001',
        ];
        $prefix = $vaPrefixes[$channelCode] ?? '99001';
        $vaNumber = $prefix . substr(preg_replace('/[^0-9]/', '', ($transaksi['duitku_reference'] ?? '') . $transaksi['id_transaksi'] . '1234567890'), 0, 10);
        Database::getInstance()->execute(
            "UPDATE tbl_transaksi SET duitku_va_number = :va WHERE id_transaksi = :id AND (duitku_va_number IS NULL OR duitku_va_number = '')",
            [':va' => $vaNumber, ':id' => $transaksi['id_transaksi']]
        );
    }

    $duitkuRef = $transaksi['duitku_reference'] ?? ('DS' . strtoupper(substr(md5('TKO' . $transaksi['id_transaksi']), 0, 12)));
    $qrString = $transaksi['duitku_qr_string'] ?? '';

    $pageTitle = 'Status Pembayaran #' . $transaksi['id_transaksi'];
    require_once dirname(dirname(__DIR__)) . '/templates/header.php';
    ?>
    <div class="card" style="max-width: 620px; margin: 30px auto; border: 2px solid #0284c7; box-shadow: 0 8px 24px rgba(2, 132, 199, 0.12); border-radius: 16px;">
        <div style="border-bottom: 1px solid #e2e8f0; padding-bottom: 15px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
            <div>
                <h2 style="margin: 0; color: #0f172a; font-size: 20px;">Instruksi Pembayaran Duitku</h2>
                <small style="color: #64748b;">Order ID: <strong>TKO<?= e($transaksi['id_transaksi']) ?></strong></small>
            </div>
            <?php
            $statusBayar = $transaksi['status_pembayaran'] ?? 'menunggu';
            $badgeClass = $statusBayar === 'lunas' ? 'badge-success' : ($statusBayar === 'menunggu' ? 'badge-warning' : 'badge-danger');
            ?>
            <span class="badge <?= e($badgeClass) ?>" id="status-badge" style="font-size: 12px; padding: 6px 14px; text-transform: uppercase; font-weight: 700; border-radius: 20px;">
                <?= e(str_replace('_', ' ', $statusBayar)) ?>
            </span>
        </div>

        <div style="background: #f8fafc; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
            <div>
                <small style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700;">Total Tagihan</small>
                <div style="font-size: 22px; font-weight: 800; color: #047857;"><?= format_rupiah($transaksi['total_belanja']) ?></div>
            </div>
            <div>
                <small style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700;">Metode Pembayaran</small>
                <div style="font-size: 15px; font-weight: 700; color: #0284c7;"><?= e($channelName) ?> (<?= e($channelCode ?: 'DUITKU') ?>)</div>
            </div>
        </div>

        <?php if ($statusBayar === 'lunas'): ?>
            <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 12px; padding: 22px; text-align: center; margin-bottom: 20px;">
                <div style="font-size: 36px; margin-bottom: 8px;">✅</div>
                <h3 style="margin: 0 0 6px 0; color: #065f46; font-size: 19px;">Pembayaran Berhasil Dikonfirmasi</h3>
                <p style="margin: 0; color: #047857; font-size: 14px;">Tagihan telah lunas dan transaksi kasir selesai.</p>
            </div>
            <div style="display: flex; gap: 12px; justify-content: center;">
                <a class="btn btn-primary" href="index.php?page=transaksi&action=struk&id=<?= e($transaksi['id_transaksi']) ?>" style="font-weight: 700; padding: 10px 20px; border-radius: 10px;">📄 Cetak / Lihat Struk</a>
                <a class="btn btn-secondary" href="index.php?page=transaksi" style="padding: 10px 20px; border-radius: 10px;">Kembali ke Kasir</a>
            </div>
        <?php else: ?>
            <?php if (!empty($transaksi['duitku_payment_url'])): ?>
                <div style="margin-bottom: 20px;">
                    <a href="<?= e($transaksi['duitku_payment_url']) ?>" target="_blank" rel="noopener noreferrer" 
                       class="btn btn-primary" 
                       style="background: #0284c7; border-color: #0284c7; width: 100%; padding: 14px 20px; font-size: 15px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border-radius: 10px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);">
                        <span>💳 Buka Halaman Resmi Pembayaran Duitku</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                    </a>
                    <small style="color: #64748b; font-size: 11px; display: block; text-align: center; margin-top: 6px;">
                        Klik tombol di atas untuk membuka instruksi pembayaran resmi langsung dari gateway Duitku.
                    </small>
                </div>
            <?php endif; ?>

            <?php if ($isVa && !empty($vaNumber)): ?>
                <div style="background: #f0f9ff; border: 2px dashed #0284c7; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 20px;">
                    <small style="text-transform: uppercase; font-size: 11px; font-weight: 800; color: #0369a1; letter-spacing: 0.5px; display: block; margin-bottom: 4px;">
                        NOMOR VIRTUAL ACCOUNT (<?= e(strtoupper($channelCode)) ?>)
                    </small>
                    <div style="font-size: 28px; font-weight: 900; font-family: monospace; color: #0c4a6e; letter-spacing: 2px; margin: 10px 0;" id="display-va-number">
                        <?= e($vaNumber) ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" id="btn-copy-va" onclick="copyValue('<?= e($vaNumber) ?>', 'btn-copy-va')" style="font-weight: 700; border-radius: 8px; padding: 6px 14px;">
                        <span>📋 Salin Nomor VA</span>
                    </button>
                    <div style="font-size: 12px; color: #475569; margin-top: 14px; line-height: 1.5; background: #fff; padding: 12px 16px; border-radius: 8px; border: 1px solid #e0f2fe; text-align: left;">
                        <strong style="color: #0369a1;">📌 Panduan Uji Coba Demo Duitku:</strong><br>
                        1. Buka <strong>Demo Transaction Area</strong> di portal Duitku.<br>
                        2. Pilih metode pembayaran <strong><?= e($channelName) ?></strong>.<br>
                        3. Masukkan nomor VA: <code style="font-weight: 700; color: #0369a1;"><?= e($vaNumber) ?></code> pada kolom <em>Virtual Account Number</em> lalu klik <strong>Check</strong>.
                    </div>
                </div>
            <?php elseif ($isQris): ?>
                <div style="background: #f0fdf4; border: 2px dashed #16a34a; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 20px;">
                    <small style="text-transform: uppercase; font-size: 11px; font-weight: 800; color: #166534; letter-spacing: 0.5px; display: block; margin-bottom: 4px;">
                        DUITKU REFERENCE (KODE BAYAR QRIS)
                    </small>
                    <div style="font-size: 24px; font-weight: 900; font-family: monospace; color: #14532d; letter-spacing: 2px; margin: 8px 0;" id="display-qris-ref">
                        <?= e($duitkuRef) ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" id="btn-copy-qris-ref" onclick="copyValue('<?= e($duitkuRef) ?>', 'btn-copy-qris-ref')" style="font-weight: 700; margin-bottom: 14px; border-radius: 8px;">
                        <span>📋 Salin Duitku Reference</span>
                    </button>

                    <div style="background: #fff; padding: 14px; border-radius: 10px; display: inline-block; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border: 1px solid #dcfce7;">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($qrString ?: $duitkuRef) ?>" alt="QRIS Code" style="display: block; width: 190px; height: 190px;" onerror="this.style.display='none'; document.getElementById('qr-fallback').style.display='block';">
                        <div id="qr-fallback" style="display: none; padding: 20px; color: #15803d; font-weight: 700;">QRIS CODE: <?= e($duitkuRef) ?></div>
                        <small style="display: block; margin-top: 8px; font-weight: 700; color: #15803d;">Scan QRIS atau gunakan Reference</small>
                    </div>

                    <div style="font-size: 12px; color: #166534; margin-top: 14px; line-height: 1.5; background: #fff; padding: 12px 16px; border-radius: 8px; border: 1px solid #bbf7d0; text-align: left;">
                        <strong>📌 Panduan Uji Coba Demo Duitku:</strong><br>
                        <em>Untuk channel QRIS di Demo Transaction Area Duitku, silakan input <strong>Duitku Reference</strong>:</em> <code style="font-weight: 700; color: #15803d;"><?= e($duitkuRef) ?></code> lalu klik <strong>Check</strong>.
                    </div>
                </div>
            <?php else: ?>
                <div style="background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 20px;">
                    <small style="text-transform: uppercase; font-size: 11px; font-weight: 800; color: #475569; letter-spacing: 0.5px; display: block; margin-bottom: 4px;">
                        DUITKU TRANSACTION REFERENCE
                    </small>
                    <div style="font-size: 24px; font-weight: 900; font-family: monospace; color: #1e293b; letter-spacing: 2px; margin: 8px 0;" id="display-general-ref">
                        <?= e($duitkuRef) ?>
                    </div>
                    <?php if (!empty($vaNumber)): ?>
                    <div style="font-size: 14px; color: #0284c7; margin-bottom: 8px;">
                        Nomor Pembayaran: <strong><?= e($vaNumber) ?></strong>
                    </div>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary btn-sm" id="btn-copy-gen-ref" onclick="copyValue('<?= e($vaNumber ?: $duitkuRef) ?>', 'btn-copy-gen-ref')" style="font-weight: 700; border-radius: 8px;">
                        <span>📋 Salin Kode Pembayaran</span>
                    </button>
                    <div style="font-size: 12px; color: #475569; margin-top: 14px; line-height: 1.5; background: #fff; padding: 12px 16px; border-radius: 8px; border: 1px solid #e2e8f0; text-align: left;">
                        <strong>📌 Panduan Uji Coba Demo Duitku:</strong><br>
                        Masukkan kode referensi <code style="font-weight: 700;"><?= e($vaNumber ?: $duitkuRef) ?></code> pada form demo transaksi Duitku untuk simulasi pembayaran.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($isVa && !empty($duitkuRef)): ?>
                <div style="margin-bottom: 18px; font-size: 12px; color: #64748b; display: flex; justify-content: space-between; align-items: center; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 16px;">
                    <span>Duitku Reference: <strong style="font-family: monospace; color: #334155;"><?= e($duitkuRef) ?></strong></span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyValue('<?= e($duitkuRef) ?>', this)" style="padding: 4px 10px; font-size: 11px; border-radius: 6px;">Salin</button>
                </div>
            <?php endif; ?>

            <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-top: 15px;">
                <a class="btn btn-primary" href="index.php?page=transaksi&action=payment_return&id=<?= e($transaksi['id_transaksi']) ?>" style="font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border-radius: 10px; padding: 10px 18px;">
                    <span>🔄 Cek Status Pembayaran</span>
                </a>
                <a class="btn btn-secondary" href="index.php?page=transaksi" style="border-radius: 10px; padding: 10px 18px;">Kembali ke Kasir</a>
                <span id="auto-check-indicator" style="font-size: 12px; color: #64748b; display: inline-flex; align-items: center; gap: 6px;">
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10b981; animation: pulse 1.5s infinite;"></span>
                    Menghubungkan ke Duitku secara live...
                </span>
            </div>
        <?php endif; ?>
    </div>

    <style>
    @keyframes pulse {
        0% { opacity: 0.4; transform: scale(0.9); }
        50% { opacity: 1; transform: scale(1.2); }
        100% { opacity: 0.4; transform: scale(0.9); }
    }
    </style>

    <script>
    function copyValue(text, btnId) {
        if (!text) return;
        navigator.clipboard.writeText(text).then(function() {
            var btn = typeof btnId === 'string' ? document.getElementById(btnId) : btnId;
            if (btn) {
                var originalHtml = btn.innerHTML;
                btn.innerHTML = '<span>✓ Tersalin!</span>';
                btn.style.background = '#10b981';
                btn.style.borderColor = '#10b981';
                btn.style.color = '#fff';
                setTimeout(function() {
                    btn.innerHTML = originalHtml;
                    btn.style.background = '';
                    btn.style.borderColor = '';
                    btn.style.color = '';
                }, 1500);
            }
        });
    }

    <?php if ($statusBayar !== 'lunas'): ?>
    (function() {
        var pollTimer = setInterval(function() {
            fetch('index.php?page=transaksi&action=check_status_ajax&id=<?= (int)$transaksi['id_transaksi'] ?>')
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.status === 'lunas') {
                        clearInterval(pollTimer);
                        window.location.reload();
                    }
                })
                .catch(function(e) {});
        }, 3000);
    })();
    <?php endif; ?>
    </script>
    <?php
    require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
    exit;
}

// ====================================================================
// 4. PROSES FORM POST (SIMPAN TRANSAKSI BARU)
// ====================================================================
if (is_post()) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        Session::setFlash('danger', 'Validasi keamanan (CSRF Token) gagal. Silakan coba kembali.');
        header('Location: index.php?page=transaksi');
        exit;
    }

    $submitToken = $_POST['submit_token'] ?? '';
    $sessionSubmitToken = Session::get('__submit_token');

    if (empty($submitToken) || $submitToken !== $sessionSubmitToken) {
        Session::setFlash('warning', 'Peringatan: Transaksi ini sudah pernah diproses atau token kedaluwarsa.');
        header('Location: index.php?page=transaksi');
        exit;
    }

    Session::remove('__submit_token');

    $idPelanggan = (int)($_POST['id_pelanggan'] ?? 0);
    $diskon      = (float)($_POST['diskon'] ?? 0);
    $catatan     = clean($_POST['catatan'] ?? '');
    $paymentMethod = (string)($_POST['metode_pembayaran'] ?? 'saldo_member');
    $duitkuMethod = (string)($_POST['duitku_payment_method'] ?? '');
    $email = trim((string)($_POST['email_pembayaran'] ?? ''));
    if ($paymentMethod === 'duitku' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Session::setFlash('danger', 'Masukkan alamat email yang valid untuk pembayaran Duitku.');
        header('Location: index.php?page=transaksi');
        exit;
    }
    $itemIds     = $_POST['item_id_produk'] ?? [];
    $itemQtys    = $_POST['item_jumlah'] ?? [];

    $items = [];
    if (is_array($itemIds) && is_array($itemQtys)) {
        for ($i = 0; $i < count($itemIds); $i++) {
            $pId = (int)($itemIds[$i] ?? 0);
            $qty = (int)($itemQtys[$i] ?? 0);
            if ($pId > 0 && $qty > 0) {
                $items[] = [
                    'id_produk' => $pId,
                    'jumlah'    => $qty
                ];
            }
        }
    }

    $result = Transaksi::create([
        'id_pelanggan' => $idPelanggan,
        'tanggal'      => date('Y-m-d'),
        'diskon'       => $diskon,
        'catatan'      => $catatan,
        'metode_pembayaran' => $paymentMethod,
        'items'        => $items
    ]);

    if ($result['success']) {
        if ($paymentMethod === 'duitku') {
            try {
                $paymentMethods = DuitkuPayment::getPaymentMethods((int)$result['total_belanja']);
                $validMethod = false;
                foreach ($paymentMethods as $method) {
                    if ((string)($method['paymentMethod'] ?? '') === $duitkuMethod) {
                        $validMethod = true;
                        break;
                    }
                }
                if (!$validMethod) {
                    throw new \RuntimeException('Metode pembayaran terpilih sudah tidak tersedia. Silakan pilih kembali.');
                }

                $customer = Pelanggan::getById($idPelanggan);
                $checkout = DuitkuPayment::createInquiry(
                    (int)$result['id_transaksi'],
                    (int)$result['total_belanja'],
                    $duitkuMethod,
                    (string)($customer['nama_pelanggan'] ?? 'Pelanggan'),
                    $email
                );
                if (!Transaksi::saveDuitkuCheckout((int)$result['id_transaksi'], $checkout, $duitkuMethod)) {
                    throw new \RuntimeException('Tagihan berhasil dibuat tetapi data transaksi tidak dapat diperbarui.');
                }
                header('Location: ' . $checkout['payment_url']);
                exit;
            } catch (\Throwable $exception) {
                error_log('[Duitku checkout] ' . $exception->getMessage());
                Transaksi::cancelDuitkuPayment((int)$result['id_transaksi']);
                Session::setFlash('danger', 'Tagihan Duitku gagal dibuat: ' . $exception->getMessage());
                header('Location: index.php?page=transaksi');
                exit;
            }
        }

        Session::setFlash('success', "Transaksi #{$result['id_transaksi']} berhasil disimpan dan stok produk telah diperbarui!");
        header('Location: index.php?page=transaksi&action=struk&id=' . $result['id_transaksi']);
        exit;
    } else {
        Session::setFlash('danger', $result['message']);
        header('Location: index.php?page=transaksi');
        exit;
    }
}

// ====================================================================
// 5. TAMPILAN BUKTI TRANSAKSI (STRUK PENJUALAN)
// ====================================================================
if ($action === 'struk' || $action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $transaksi = Transaksi::getById($id);

    if (!$transaksi) {
        Session::setFlash('danger', 'Transaksi tidak ditemukan.');
        header('Location: index.php?page=transaksi');
        exit;
    }

    $pageTitle = 'Bukti Transaksi #' . $transaksi['id_transaksi'];
    
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $qrPayload = "{$scheme}://{$host}/ToKo/index.php?page=transaksi&action=struk&id={$transaksi['id_transaksi']}";

    require_once dirname(dirname(__DIR__)) . '/templates/header.php';
    ?>
    <div class="thermal-wrapper">
        <div style="display: flex; gap: 10px; margin-bottom: 24px; justify-content: center; width: 100%; max-width: 340px;" class="no-print">
            <a href="index.php?page=transaksi" class="btn btn-secondary btn-sm" style="border-radius: 8px;">&laquo; Kembali ke Kasir</a>
            <button onclick="window.print()" class="btn btn-primary btn-sm" style="border-radius: 8px;">&#128438; Cetak Struk</button>
        </div>

        <div class="thermal-paper" id="struk-area">
            <div class="receipt-header">
                <div class="receipt-logo"><?= e(APP_NAME) ?></div>
                <div class="receipt-tagline">Belanja puas harga pas</div>
                <div class="receipt-store-info">
                    <?= e(APP_NAME) ?> Jogja<br>
                    Jl. Kenangan No. 12<br>
                    Jogja<br>
                    08897482739
                </div>
            </div>

            <div class="receipt-meta">
                <span><?= date('d.m.Y H:i', strtotime($transaksi['tanggal'])) ?></span>
                <span>#<?= e($transaksi['id_transaksi']) ?></span>
            </div>

            <hr class="receipt-divider">

            <div class="receipt-items">
                <?php foreach ($transaksi['items'] as $item): ?>
                    <div class="receipt-item-block">
                        <div class="receipt-item-name"><?= e($item['nama_produk']) ?></div>
                        <div class="receipt-item-calc">
                            <span><?= e($item['jumlah']) ?>x <?= number_format($item['harga'], 0, ',', '.') ?></span>
                            <span><?= number_format($item['subtotal'], 0, ',', '.') ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr class="receipt-divider">

            <?php if ((float)($transaksi['diskon'] ?? 0) > 0): ?>
                <div class="receipt-total-row">
                    <span>Subtotal</span>
                    <span>Rp<?= number_format($transaksi['subtotal'] ?? $transaksi['total_belanja'], 0, ',', '.') ?></span>
                </div>
                <div class="receipt-total-row">
                    <span>Diskon (<?= e($transaksi['diskon']) ?>%)</span>
                    <span>-Rp<?= number_format($transaksi['nilai_diskon'], 0, ',', '.') ?></span>
                </div>
            <?php endif; ?>

            <?php
            $uangBayar = (float)($transaksi['uang_bayar'] ?? 0);
            if ($uangBayar <= 0 && ($transaksi['status_pembayaran'] ?? 'lunas') === 'lunas') {
                $uangBayar = (float)$transaksi['total_belanja'];
            }
            $uangKembali = (float)($transaksi['uang_kembali'] ?? 0);
            ?>

            <div class="receipt-total-row main">
                <span>Total</span>
                <span>Rp<?= number_format($transaksi['total_belanja'], 0, ',', '.') ?></span>
            </div>

            <div class="receipt-total-row">
                <span><?= ($transaksi['metode_pembayaran'] ?? '') === 'saldo_member' ? 'Saldo kartu member' : (($transaksi['metode_pembayaran'] ?? '') === 'duitku' ? 'Duitku Gateway' : 'Tunai') ?></span>
                <span>Rp<?= number_format($uangBayar, 0, ',', '.') ?></span>
            </div>
            <?php if (($transaksi['metode_pembayaran'] ?? '') !== 'saldo_member' && ($transaksi['metode_pembayaran'] ?? '') !== 'duitku'): ?>
                <div class="receipt-total-row">
                    <span>Kembali</span>
                    <span>Rp<?= number_format($uangKembali, 0, ',', '.') ?></span>
                </div>
            <?php endif; ?>

            <div style="text-align: right; font-size: 12px; font-weight: 600; margin-top: 2px;">
                <?php
                $receiptPaymentStatus = $transaksi['status_pembayaran'] ?? 'lunas';
                echo e(match ($receiptPaymentStatus) {
                    'lunas' => 'Lunas',
                    'gagal' => 'Pembayaran gagal',
                    'kedaluwarsa' => 'Tagihan kedaluwarsa',
                    'perlu_tindak_lanjut' => 'Perlu tindak lanjut admin',
                    default => 'Menunggu pembayaran',
                });
                ?>
            </div>

            <hr class="receipt-divider">

            <?php if (!empty($transaksi['catatan'])): ?>
                <div class="receipt-note">
                    <strong>Catatan:</strong> <?= e($transaksi['catatan']) ?>
                </div>
                <hr class="receipt-divider">
            <?php endif; ?>

            <?php if (!empty($transaksi['nama_pelanggan']) && $transaksi['nama_pelanggan'] !== 'Pelanggan Umum'): ?>
                <div style="font-size: 11px; color: #475569; margin-bottom: 8px;">
                    Pelanggan: <?= e($transaksi['nama_pelanggan']) ?> (<?= e($transaksi['kota'] ?? '-') ?>)
                </div>
                <hr class="receipt-divider">
            <?php endif; ?>

            <div class="receipt-footer-notes">
                <div style="margin-bottom: 8px;">
                    Barang yang sudah dibeli tidak b<br>isa dikembalikan lagi
                </div>
                <div class="receipt-cashier">
                    Kasir : <?= e($currentUser['nama_lengkap'] ?? 'Kasir Utama') ?>
                </div>
                <div class="receipt-thanks" style="margin-bottom: 10px;">
                    THANK YOU!
                </div>
            </div>

            <div class="receipt-barcode-section">
                <div class="barcode-svg-container">
                    <?= generate_barcode_svg('TRX' . str_pad($transaksi['id_transaksi'], 6, '0', STR_PAD_LEFT), 44, 2, 4) ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
    exit;
}

// ====================================================================
// 6. VIEW UTAMA KASIR POS MODERN + RIWAYAT TRANSAKSI TERPADU
// ====================================================================
$submitToken = bin2hex(random_bytes(16));
Session::set('__submit_token', $submitToken);

$pelangganList = Pelanggan::getAll();
// Ambil produk aktif dengan stok > 0
$produkList = Produk::getAll(null, null, 'aktif');
$produkList = array_values(array_filter($produkList, fn($p) => (int)$p['stok'] > 0));

// Kategori unik untuk filter tab
$categories = [];
foreach ($produkList as $p) {
    $kat = trim($p['nama_kategori'] ?? 'Umum');
    if ($kat && !in_array($kat, $categories, true)) {
        $categories[] = $kat;
    }
}

// Data Riwayat Transaksi (Filter)
$keyword    = clean($_GET['q'] ?? '');
$tglMulai   = clean($_GET['tgl_mulai'] ?? '');
$tglSelesai = clean($_GET['tgl_selesai'] ?? '');

$transaksiList = Transaksi::getAll($keyword, $tglMulai, $tglSelesai);
$totalOmzet = array_reduce(
    $transaksiList,
    fn($carry, $item) => $carry + (($item['status_pembayaran'] ?? 'lunas') === 'lunas' ? (float)$item['total_belanja'] : 0),
    0.00
);

$pageTitle = 'Kasir Transaksi & Riwayat Penjualan';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<!-- SCOPED CSS MODERN POS (Menjamin 100% render sempurna terbebas dari browser cache) -->
<style>
/* Modern POS Container */
.pos-container {
    display: grid;
    grid-template-columns: 1.35fr 1fr;
    gap: 24px;
    align-items: start;
    margin-bottom: 30px;
}
@media (max-width: 1024px) {
    .pos-container {
        grid-template-columns: 1fr;
    }
}

/* POS Left: Catalog & Search */
.pos-catalog-panel {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    padding: 22px;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
}

.pos-search-bar {
    position: relative;
    margin-bottom: 16px;
}
.pos-search-bar input {
    width: 100%;
    height: 48px;
    padding: 12px 18px 12px 46px;
    font-size: 14px;
    border-radius: 12px;
    border: 1.5px solid #cbd5e1;
    background: #f8fafc;
    transition: all 0.2s ease;
    box-sizing: border-box;
}
.pos-search-bar input:focus {
    border-color: #3b82f6;
    background: #ffffff;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
    outline: none;
}
.pos-search-bar-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    pointer-events: none;
    display: flex;
    align-items: center;
}

/* Category Filter Tabs */
.pos-cat-tabs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 10px;
    margin-bottom: 14px;
}
.pos-cat-tab {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s ease;
}
.pos-cat-tab:hover {
    background: #e2e8f0;
    color: #1e293b;
}
.pos-cat-tab.active {
    background: #3b82f6;
    color: #ffffff;
    border-color: #3b82f6;
    box-shadow: 0 2px 6px rgba(59, 130, 246, 0.3);
}

/* Product Cards Grid */
.pos-products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
    max-height: 460px;
    overflow-y: auto;
    padding-right: 4px;
}
.pos-prod-card {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    padding: 12px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    user-select: none;
    text-align: left;
}
.pos-prod-card:hover {
    border-color: #3b82f6;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.12);
}
.pos-prod-card:active {
    transform: scale(0.97);
}
.pos-prod-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    margin-bottom: 8px;
}
.pos-prod-name {
    font-weight: 700;
    color: #1e293b;
    font-size: 13px;
    line-height: 1.35;
    margin-bottom: 4px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.pos-prod-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px dashed #f1f5f9;
}
.pos-prod-stock {
    font-size: 11px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 6px;
}
.pos-prod-stock.stock-ok { background: #dcfce7; color: #15803d; }
.pos-prod-stock.stock-low { background: #fef3c7; color: #b45309; }
.pos-prod-price {
    font-weight: 800;
    color: #047857;
    font-size: 13px;
}

/* POS Right: Bill / Order Slip */
.pos-bill-panel {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    padding: 22px;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
    position: sticky;
    top: 20px;
}
.pos-bill-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1.5px solid #f1f5f9;
    padding-bottom: 12px;
    margin-bottom: 16px;
}
.pos-bill-title {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}
.pos-bill-count {
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 12px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 12px;
}

/* Cart Items Table / List */
.pos-cart-list {
    max-height: 230px;
    overflow-y: auto;
    margin-bottom: 16px;
    padding-right: 2px;
}
.pos-cart-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 8px;
    gap: 10px;
}
.pos-cart-item-info {
    flex: 1;
    min-width: 0;
}
.pos-cart-item-name {
    font-weight: 700;
    color: #1e293b;
    font-size: 13px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pos-cart-item-price {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
}
.pos-cart-item-stepper {
    display: flex;
    align-items: center;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    overflow: hidden;
}
.pos-stepper-btn {
    border: none;
    background: #f1f5f9;
    color: #1e293b;
    font-weight: 800;
    font-size: 14px;
    width: 26px;
    height: 28px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s ease;
}
.pos-stepper-btn:hover:not(:disabled) {
    background: #e2e8f0;
}
.pos-stepper-btn:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}
.pos-stepper-val {
    width: 34px;
    text-align: center;
    font-weight: 700;
    font-size: 13px;
    color: #0f172a;
    border: none;
    outline: none;
    background: transparent;
}
.pos-cart-item-subtotal {
    font-weight: 800;
    color: #047857;
    font-size: 13px;
    min-width: 70px;
    text-align: right;
}
.pos-cart-item-remove {
    border: none;
    background: #fee2e2;
    color: #dc2626;
    width: 24px;
    height: 24px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    transition: all 0.15s ease;
}
.pos-cart-item-remove:hover {
    background: #fca5a5;
    color: #991b1b;
}

/* Empty Cart State */
.pos-empty-cart {
    text-align: center;
    padding: 30px 16px;
    background: #f8fafc;
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    color: #64748b;
    margin-bottom: 16px;
}
.pos-empty-cart-icon {
    font-size: 32px;
    margin-bottom: 6px;
}
.pos-empty-cart h4 {
    margin: 0 0 4px 0;
    font-size: 14px;
    color: #334155;
    font-weight: 700;
}
.pos-empty-cart p {
    margin: 0;
    font-size: 12px;
    color: #94a3b8;
}

/* Segmented Payment Tabs */
.pos-payment-tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 14px;
}
.pos-pay-tab {
    border: 1.5px solid #e2e8f0;
    background: #f8fafc;
    padding: 8px 10px;
    border-radius: 10px;
    cursor: pointer;
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    transition: all 0.15s ease;
}
.pos-pay-tab:hover {
    border-color: #93c5fd;
    color: #1d4ed8;
}
.pos-pay-tab.active {
    border-color: #3b82f6;
    background: #eff6ff;
    color: #1d4ed8;
    box-shadow: 0 2px 6px rgba(59, 130, 246, 0.15);
}

/* Total Card Display */
.pos-total-box {
    background: linear-gradient(135deg, #064e3b 0%, #047857 100%);
    color: #ffffff;
    border-radius: 14px;
    padding: 16px 20px;
    margin-top: 14px;
    box-shadow: 0 6px 18px rgba(4, 120, 87, 0.22);
}
.pos-total-sub-row {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #d1fae5;
    margin-bottom: 4px;
}
.pos-total-main-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    border-top: 1px solid rgba(255, 255, 255, 0.2);
    padding-top: 8px;
    margin-top: 8px;
}
.pos-total-main-label {
    font-size: 14px;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: #a7f3d0;
}
.pos-total-main-val {
    font-size: 26px;
    font-weight: 900;
    letter-spacing: -0.5px;
    color: #ffffff;
}

.pos-btn-submit {
    width: 100%;
    margin-top: 14px;
    padding: 14px;
    font-size: 15px;
    font-weight: 800;
    border-radius: 12px;
    background: #2563eb;
    color: #ffffff;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
    transition: all 0.2s ease;
}
.pos-btn-submit:hover:not(:disabled) {
    background: #1d4ed8;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.4);
}
.pos-btn-submit:disabled {
    background: #94a3b8;
    box-shadow: none;
    cursor: not-allowed;
    opacity: 0.7;
}

/* History Card */
.pos-history-panel {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    padding: 24px;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
}
</style>

<!-- ================================================================== -->
<!-- BAGIAN ATAS: FORM KASIR TRANSAKSI POS MODERN                       -->
<!-- ================================================================== -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="margin: 0; display: flex; align-items: center; gap: 10px; font-size: 22px; color: #0f172a;">
            <span>⚡</span> Kasir Penjualan POS
            <span class="badge badge-success" style="font-size: 11px; padding: 4px 10px; font-weight: 700; border-radius: 20px;">ONLINE</span>
        </h2>
        <p style="margin: 3px 0 0 0; color: #64748b; font-size: 13px;">
            Klik kartu produk atau scan barcode untuk menambahkan ke pesanan kasir secara instan.
        </p>
    </div>
    <div style="font-size: 13px; color: #475569; background: #fff; padding: 7px 16px; border-radius: 20px; border: 1px solid #e2e8f0; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
        <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10b981;"></span>
        Kasir: <strong><?= e($currentUser['nama_lengkap'] ?? 'Kasir Utama') ?></strong>
    </div>
</div>

<form method="POST" action="index.php?page=transaksi" id="form-transaksi">
    <?= csrf_field() ?>
    <input type="hidden" name="submit_token" value="<?= e($submitToken) ?>">
    <!-- Hidden input metode pembayaran asli form -->
    <input type="hidden" name="metode_pembayaran" id="metode_pembayaran" value="saldo_member">

    <div class="pos-container">
        
        <!-- KOLOM KIRI: KATALOG PRODUK, SEARCH BARCODE, FILTER KATEGORI -->
        <div class="pos-catalog-panel">
            
            <!-- SMART SEARCH & SCANNER INPUT -->
            <div class="pos-search-bar">
                <span class="pos-search-bar-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </span>
                <input type="text" id="pos-product-search" 
                       placeholder="Cari nama produk, kode, atau tembak barcode scanner lalu Enter..." autocomplete="off">
            </div>

            <!-- FILTER KATEGORI TABS -->
            <div class="pos-cat-tabs" id="pos-cat-tabs">
                <button type="button" class="pos-cat-tab active" data-cat="all">Semua Produk (<?= count($produkList) ?>)</button>
                <?php foreach ($categories as $cat): ?>
                    <button type="button" class="pos-cat-tab" data-cat="<?= e(strtolower($cat)) ?>"><?= e($cat) ?></button>
                <?php endforeach; ?>
            </div>

            <!-- GRID KARTU PRODUK KATALOG -->
            <div class="pos-products-grid" id="pos-products-grid">
                <?php foreach ($produkList as $p): 
                    $isLow = (int)$p['stok'] <= 5;
                    $catClean = strtolower(trim($p['nama_kategori'] ?? 'umum'));
                ?>
                    <div class="pos-prod-card" data-id="<?= e($p['id_produk']) ?>" data-cat="<?= e($catClean) ?>" data-name="<?= e(strtolower($p['nama_produk'])) ?>" data-code="<?= e(strtolower($p['kode_produk'])) ?>">
                        <div>
                            <div class="pos-prod-icon">
                                📦
                            </div>
                            <div class="pos-prod-name" title="<?= e($p['nama_produk']) ?>">
                                <?= e($p['nama_produk']) ?>
                            </div>
                            <small style="color: #64748b; font-size: 11px;"><code><?= e($p['kode_produk']) ?></code></small>
                        </div>
                        <div class="pos-prod-meta">
                            <span class="pos-prod-stock <?= $isLow ? 'stock-low' : 'stock-ok' ?>">
                                <?= e($p['stok']) ?> pcs
                            </span>
                            <span class="pos-prod-price">
                                <?= format_rupiah($p['harga']) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>

        <!-- KOLOM KANAN: STRUK / ORDER BILL & PANEL PEMBAYARAN -->
        <div class="pos-bill-panel">
            
            <div class="pos-bill-header">
                <h3 class="pos-bill-title">
                    <span>🛒</span> Daftar Pesanan Kasir
                </h3>
                <span class="pos-bill-count" id="pos-item-counter">0 Item</span>
            </div>

            <!-- EMPTY STATE -->
            <div id="pos-cart-empty" class="pos-empty-cart">
                <div class="pos-empty-cart-icon">🛒</div>
                <h4>Belum Ada Produk Dipilih</h4>
                <p>Klik kartu produk di katalog sebelah kiri atau tembak barcode scanner.</p>
            </div>

            <!-- CART ITEMS LIST -->
            <div class="pos-cart-list" id="pos-cart-list" style="display: none;">
                <!-- Baris keranjang di-generate dinamis oleh JavaScript -->
            </div>

            <!-- FORM DATA PELANGGAN & PEMBAYARAN -->
            <div style="border-top: 1.5px solid #f1f5f9; padding-top: 14px;">
                
                <!-- SCAN KARTU MEMBER / PILIH PELANGGAN -->
                <div style="display: grid; grid-template-columns: 1fr 1.2fr; gap: 8px; margin-bottom: 10px;">
                    <div>
                        <label for="kode_member" style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 3px;">Scan Member</label>
                        <input type="text" id="kode_member" class="form-control" style="height: 38px; min-height: 38px; font-size: 12px;" placeholder="Scan barcode..." autocomplete="off">
                    </div>
                    <div>
                        <label for="id_pelanggan" style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 3px;">Pilih Pelanggan *</label>
                        <select name="id_pelanggan" id="id_pelanggan" class="form-control" style="height: 38px; min-height: 38px; font-size: 12px;" required>
                            <option value="">-- Pilih Pelanggan --</option>
                            <?php foreach ($pelangganList as $pel): ?>
                                <option value="<?= e($pel['id_pelanggan']) ?>" data-saldo="<?= e($pel['saldo'] ?? 0) ?>" data-kode="<?= e($pel['kode_member'] ?? '') ?>">
                                    <?= e($pel['nama_pelanggan']) ?> (<?= e($pel['kota']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- DISKON BELANJA & CATATAN -->
                <div style="display: grid; grid-template-columns: 90px 1fr; gap: 8px; margin-bottom: 12px;">
                    <div>
                        <label for="diskon" style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 3px;">Diskon (%)</label>
                        <input type="number" id="diskon" name="diskon" class="form-control" style="height: 38px; min-height: 38px; font-size: 12px;" min="0" max="100" step="0.5" value="0">
                    </div>
                    <div>
                        <label for="catatan" style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 3px;">Catatan (Opsional)</label>
                        <input type="text" id="catatan" name="catatan" class="form-control" style="height: 38px; min-height: 38px; font-size: 12px;" placeholder="Catatan transaksi kasir...">
                    </div>
                </div>

                <!-- METODE PEMBAYARAN: SEGMENTED PILLS -->
                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 6px;">Metode Pembayaran</label>
                <div class="pos-payment-tabs">
                    <div class="pos-pay-tab active" data-method="saldo_member" id="tab-pay-saldo">
                        💳 Saldo Kartu Member
                    </div>
                    <div class="pos-pay-tab" data-method="duitku" id="tab-pay-duitku">
                        🌐 Duitku (QRIS / VA)
                    </div>
                </div>

                <!-- BOX SALDO MEMBER -->
                <div id="box-saldo-member" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; font-size: 12px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 3px;">
                        <span style="color: #64748b;">Saldo Member:</span>
                        <strong id="display-saldo-member" style="color: #0369a1;">Pilih kartu member</strong>
                    </div>
                    <p id="saldo-warning" style="margin: 4px 0 0; color: #dc2626; font-size: 11px; font-weight: 700;" hidden>
                        ⚠️ Saldo kartu member tidak mencukupi untuk tagihan ini.
                    </p>
                </div>

                <!-- BOX DUITKU -->
                <div id="duitku-payment-box" style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 10px; padding: 12px; margin-bottom: 12px;" hidden>
                    <label for="email_pembayaran" style="font-size: 11px; font-weight: 700; color: #0369a1; display: block; margin-bottom: 3px;">Email Pelanggan *</label>
                    <input type="email" name="email_pembayaran" id="email_pembayaran" class="form-control" style="height: 36px; min-height: 36px; font-size: 12px;" autocomplete="email" placeholder="email@pelanggan.com">
                    
                    <label for="duitku_payment_method" style="margin-top: 8px; font-size: 11px; font-weight: 700; color: #0369a1; display: block; margin-bottom: 3px;">Pilih Kanal Duitku *</label>
                    <select name="duitku_payment_method" id="duitku_payment_method" class="form-control" style="height: 36px; min-height: 36px; font-size: 12px;" disabled>
                        <option value="">Pilih kanal setelah ada item</option>
                    </select>
                    <small id="duitku-method-status" role="status" style="display: block; margin-top: 4px; color: #0284c7; font-size: 11px;"></small>
                </div>

                <!-- TOTAL CARD BESAR -->
                <div class="pos-total-box">
                    <div class="pos-total-sub-row">
                        <span>Subtotal Produk</span>
                        <span id="display-subtotal" style="font-weight: 700;">Rp 0</span>
                    </div>
                    <div class="pos-total-sub-row" style="color: #fca5a5;">
                        <span>Potongan Diskon</span>
                        <span id="display-diskon" style="font-weight: 700;">Rp 0</span>
                    </div>
                    <div class="pos-total-main-row">
                        <span class="pos-total-main-label">Total Akhir</span>
                        <span class="pos-total-main-val" id="pos-big-total">Rp 0</span>
                    </div>
                    <span id="display-total" style="display: none;">Rp 0</span>
                    <span id="display-total-member" style="display: none;">Rp 0</span>
                </div>

                <button type="submit" class="pos-btn-submit" id="btn-submit-transaksi" disabled>
                    Bayar dengan Saldo Member
                </button>

            </div>

        </div>

    </div>
</form>

<!-- DATA PRODUK UNTUK POS JAVASCRIPT -->
<script type="application/json" id="pos-data"><?= json_encode(
    ['products' => $produkList],
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
) ?></script>
<script src="assets/js/pos.js?v=<?= APP_VERSION ?>_<?= time() ?>" defer></script>


<!-- ================================================================== -->
<!-- BAGIAN BAWAH: RIWAYAT TRANSAKSI PENJUALAN TERKINI                  -->
<!-- ================================================================== -->
<div class="pos-history-panel">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h3 style="margin: 0; font-size: 18px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                <span>📋</span> Riwayat Transaksi Penjualan Terkini
            </h3>
            <p style="margin: 3px 0 0 0; color: #64748b; font-size: 13px;">Daftar seluruh transaksi kasir lengkap dengan status pembayaran, pelanggan, dan struk belanja.</p>
        </div>
    </div>

    <!-- Form Filter & Pencarian -->
    <form method="GET" action="index.php" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; align-items: flex-end; background: #f8fafc; padding: 12px 16px; border-radius: 12px; border: 1px solid #e2e8f0;">
        <input type="hidden" name="page" value="transaksi">

        <div>
            <label style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; display: block; margin-bottom: 4px;">Cari Pelanggan / ID</label>
            <input type="text" name="q" class="form-control" style="width: 200px; height: 38px; min-height: 38px;" 
                   placeholder="Nama / No. Trx..." value="<?= e($keyword) ?>">
        </div>

        <div>
            <label style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; display: block; margin-bottom: 4px;">Tgl Mulai</label>
            <input type="date" name="tgl_mulai" class="form-control" style="height: 38px; min-height: 38px;" value="<?= e($tglMulai) ?>">
        </div>

        <div>
            <label style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; display: block; margin-bottom: 4px;">Tgl Selesai</label>
            <input type="date" name="tgl_selesai" class="form-control" style="height: 38px; min-height: 38px;" value="<?= e($tglSelesai) ?>">
        </div>

        <div>
            <button type="submit" class="btn btn-secondary" style="height: 38px; padding: 0 16px; border-radius: 8px;">Filter</button>
            <?php if (!empty($keyword) || !empty($tglMulai) || !empty($tglSelesai)): ?>
                <a href="index.php?page=transaksi" class="btn btn-outline-secondary" style="height: 38px; padding: 0 14px; line-height: 36px; display: inline-block; border-radius: 8px;">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Ringkasan Filter & Perputaran -->
    <div style="background: #f1f5f9; padding: 12px 18px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
        <span>Ditemukan: <strong><?= count($transaksiList) ?></strong> transaksi kasir</span>
        <span>Total Perputaran Lunas: <strong style="color: #047857; font-size: 16px;"><?= format_rupiah($totalOmzet) ?></strong></span>
    </div>

    <!-- Tabel Riwayat Transaksi -->
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 80px;">No. Trx</th>
                    <th style="width: 120px;">Tanggal</th>
                    <th>Nama Pelanggan</th>
                    <th>Kota</th>
                    <th style="width: 130px; text-align: center;">Item Belanja</th>
                    <th style="width: 80px; text-align: center;">Diskon</th>
                    <th style="width: 140px; text-align: right;">Total Belanja</th>
                    <th style="width: 130px; text-align: center;">Status Bayar</th>
                    <th style="width: 140px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transaksiList)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            Tidak ada data transaksi yang ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transaksiList as $t): ?>
                        <tr>
                            <td><strong>#<?= e($t['id_transaksi']) ?></strong></td>
                            <td><?= format_tanggal($t['tanggal']) ?></td>
                            <td><strong><?= e($t['nama_pelanggan'] ?? 'Umum') ?></strong></td>
                            <td><?= e($t['kota'] ?? '-') ?></td>
                            <td style="text-align: center;">
                                <span class="badge badge-info" style="font-weight: 600; border-radius: 12px;">
                                    <?= (int)$t['total_qty'] ?> pcs (<?= (int)$t['total_item'] ?> item)
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <?php if ((float)$t['diskon'] > 0): ?>
                                    <span class="badge badge-warning" style="border-radius: 12px;"><?= e($t['diskon']) ?>%</span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 12px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <strong style="color: #047857; font-size: 14px;"><?= format_rupiah($t['total_belanja']) ?></strong>
                            </td>
                            <td style="text-align: center;">
                                <?php
                                $statusPembayaran = $t['status_pembayaran'] ?? 'lunas';
                                $badgeStatus = $statusPembayaran === 'lunas'
                                    ? 'badge-success'
                                    : (in_array($statusPembayaran, ['menunggu', 'perlu_tindak_lanjut'], true) ? 'badge-warning' : 'badge-danger');
                                ?>
                                <span class="badge <?= e($badgeStatus) ?>" style="border-radius: 12px;"><?= e(ucfirst(str_replace('_', ' ', $statusPembayaran))) ?></span>
                            </td>
                            <td style="text-align: center;">
                                <?php if (($t['metode_pembayaran'] ?? '') === 'duitku' && $statusPembayaran !== 'lunas'): ?>
                                    <a href="index.php?page=transaksi&action=payment_return&id=<?= e($t['id_transaksi']) ?>" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 5px 10px; border-radius: 6px;">
                                        💳 Status Bayar
                                    </a>
                                <?php else: ?>
                                    <a href="index.php?page=transaksi&action=struk&id=<?= e($t['id_transaksi']) ?>" class="btn btn-primary btn-sm" style="font-size: 11px; padding: 5px 10px; border-radius: 6px;">
                                        📄 Struk
                                    </a>
                                <?php endif; ?>
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
