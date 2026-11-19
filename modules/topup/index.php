<?php
/**
 * Modul Top-up Saldo Kartu Member & Pembayaran Multi-Kanal
 * Mendukung Payment Gateway DUITKU (Otomatis & Real-Time) serta Transfer Manual Resmi (DANA, GoPay, BRI, BCA)
 * Dilengkapi Verifikasi Sisi Admin & Audit Trail Keuangan
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/MemberAccount.php';
require_once dirname(dirname(__DIR__)) . '/models/Wallet.php';
require_once dirname(dirname(__DIR__)) . '/core/Duitku.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';

Auth::requireRole(['admin', 'pelanggan'], 'index.php?page=login');
$currentUser = Auth::user();
$isAdmin = Auth::hasRole('admin');
$member = $isAdmin ? null : MemberAccount::getMemberForUser((int)Auth::id());
if (!$isAdmin && !$member) {
    Session::setFlash('danger', 'Akun ini tidak terhubung dengan data kartu member.');
    header('Location: index.php?page=login');
    exit;
}

$duitkuConfig = Duitku::getConfig();
$duitkuChannels = Duitku::getChannels();

// Kanal Transfer Manual Toko
$manualChannels = [
    'dana' => [
        'name'         => 'DANA',
        'type'         => 'E-Wallet',
        'account_no'   => '0812-3456-7890',
        'account_name' => 'ToKo Official',
        'color'        => '#118eea',
        'icon'         => '📱',
        'guide'        => 'Kirim saldo DANA ke nomor 081234567890 a/n ToKo Official. Masukkan No. Transaksi pada bukti transfer ke form di bawah.'
    ],
    'gopay' => [
        'name'         => 'GoPay',
        'type'         => 'E-Wallet (Gojek)',
        'account_no'   => '0812-3456-7890',
        'account_name' => 'ToKo Official',
        'color'        => '#00aa13',
        'icon'         => '🟢',
        'guide'        => 'Kirim saldo GoPay ke nomor 081234567890 a/n ToKo Official. Masukkan ID Transaksi GoPay ke form di bawah.'
    ],
    'bri' => [
        'name'         => 'Bank BRI',
        'type'         => 'Transfer Bank (BRImo / ATM)',
        'account_no'   => '0123-01-000456-50-8',
        'account_name' => 'ToKo Retail Indonesia',
        'color'        => '#00529c',
        'icon'         => '🏦',
        'guide'        => 'Transfer ke rekening BRI 012301000456508 a/n ToKo Retail Indonesia. Masukkan Nomor Referensi transfer.'
    ],
    'bca' => [
        'name'         => 'Bank BCA',
        'type'         => 'Transfer Bank (BCA Mobile / ATM)',
        'account_no'   => '8830-1234-56',
        'account_name' => 'ToKo Retail Indonesia',
        'color'        => '#00368a',
        'icon'         => '🏦',
        'guide'        => 'Transfer ke rekening BCA 8830123456 a/n ToKo Retail Indonesia. Masukkan Nomor Referensi transaksi.'
    ]
];

// ====================================================================
// PROSES POST FORM
// ====================================================================
if (is_post()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        Session::setFlash('danger', 'Validasi keamanan gagal. Silakan muat ulang halaman, lalu coba lagi.');
        header('Location: index.php?page=topup');
        exit;
    }

    $action = clean($_POST['post_action'] ?? '');

    // 1. Pengajuan Top-up Otomatis via Gateway DUITKU
    if ($action === 'duitku_request' && !$isAdmin) {
        $nominal     = (float)($_POST['nominal'] ?? 0);
        $channelCode = clean($_POST['channel_code'] ?? 'NQ');

        $result = Wallet::requestDuitkuTopup(
            (int)$member['id_pelanggan'],
            $nominal,
            $channelCode,
            $member['nama_pelanggan'],
            $currentUser['email'] ?? 'member@tokokita.local',
            $member['telepon'] ?? '081234567890'
        );

        if ($result['success']) {
            Session::set('duitku_active_invoice', $result);
            Session::setFlash('success', "Invoice pembayaran Duitku berhasil dibuat! Silakan selesaikan pembayaran.");
            header('Location: index.php?page=topup&status=invoice');
            exit;
        } else {
            Session::setFlash('danger', $result['message']);
            header('Location: index.php?page=topup');
            exit;
        }
    }

    // 2. Pengajuan Top-up Transfer Manual
    elseif ($action === 'request' && !$isAdmin) {
        $method    = clean($_POST['metode_pembayaran'] ?? 'dana');
        $nominal   = (float)($_POST['nominal'] ?? 0);
        $referensi = clean($_POST['referensi_pembayaran'] ?? '');
        $catatan   = clean($_POST['catatan'] ?? '');

        if (!array_key_exists($method, $manualChannels)) {
            $method = 'dana';
        }

        $result = Wallet::requestTopup(
            (int)$member['id_pelanggan'],
            $nominal,
            $referensi,
            $catatan,
            $method
        );

        Session::setFlash($result['success'] ? 'success' : 'danger', $result['message']);
        header('Location: index.php?page=topup');
        exit;
    }

    // 3. Verifikasi Approval Manual Admin
    elseif ($isAdmin && $action === 'approve') {
        $result = Wallet::approveTopup((int)($_POST['id_topup'] ?? 0), (int)Auth::id());
        Session::setFlash($result['success'] ? 'success' : 'danger', $result['message']);
        header('Location: index.php?page=topup');
        exit;
    }

    // 4. Penolakan Top-up Admin
    elseif ($isAdmin && $action === 'reject') {
        $result = Wallet::rejectTopup((int)($_POST['id_topup'] ?? 0), (int)Auth::id());
        Session::setFlash($result['success'] ? 'success' : 'danger', $result['message']);
        header('Location: index.php?page=topup');
        exit;
    }
}

$activeInvoice = Session::get('duitku_active_invoice');

// Cek Status Transaksi Duitku secara Langsung (Inquiry)
if (isset($_GET['action']) && $_GET['action'] === 'check_duitku' && !empty($_GET['order_id'])) {
    $orderId = clean($_GET['order_id']);
    $statusRes = Duitku::checkTransactionStatus($orderId);

    if ($statusRes['success'] && $statusRes['status_code'] === '00') {
        $mockCallback = [
            'merchantCode'    => $duitkuConfig['merchant_code'],
            'amount'          => $statusRes['amount'],
            'merchantOrderId' => $orderId,
            'signature'       => Duitku::generateCallbackSignature($orderId, $statusRes['amount']),
            'resultCode'      => '00',
            'reference'       => $statusRes['reference']
        ];
        Wallet::processDuitkuCallback($mockCallback);
        Session::remove('duitku_active_invoice');
        Session::setFlash('success', "Pembayaran untuk Order {$orderId} telah terkonfirmasi LUNAS! Saldo member telah diperbarui.");
    } else {
        // Mode Sandbox: Izinkan simulasi langsung berhasil jika dalam mode uji coba
        if (!empty($duitkuConfig['sandbox']) && !empty($activeInvoice) && $activeInvoice['order_id'] === $orderId) {
            $mockCallback = [
                'merchantCode'    => $duitkuConfig['merchant_code'],
                'amount'          => (int)$activeInvoice['amount'],
                'merchantOrderId' => $orderId,
                'signature'       => Duitku::generateCallbackSignature($orderId, (int)$activeInvoice['amount']),
                'resultCode'      => '00',
                'reference'       => $activeInvoice['duitku_reference']
            ];
            Wallet::processDuitkuCallback($mockCallback);
            Session::remove('duitku_active_invoice');
            Session::setFlash('success', "Pembayaran Sandbox untuk Order {$orderId} berhasil dikonfirmasi LUNAS! Saldo member telah diperbarui.");
        } else {
            Session::setFlash('info', "Status Order {$orderId}: " . ($statusRes['status_message'] ?? 'Belum ada pembayaran atau masih dalam proses'));
        }
    }
    header('Location: index.php?page=topup');
    exit;
}

$requests = Wallet::getRequests($isAdmin ? null : (int)$member['id_pelanggan']);
$pageTitle = $isAdmin ? 'Verifikasi Top-up Member' : 'Top-up Saldo & Pembayaran Member';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<!-- Header Halaman -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="margin: 0; display: flex; align-items: center; gap: 8px;">
            <span>💳 <?= $isAdmin ? 'Verifikasi Top-up Saldo Member' : 'Top-up Saldo & Pembayaran' ?></span>
            <span class="badge" style="background: #0284c7; color: #fff; font-size: 11px;">DUITKU Gateway Ready</span>
        </h2>
        <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">
            <?= $isAdmin 
                ? 'Verifikasi pembayaran otomatis melalui Gateway DUITKU (QRIS, E-Wallet, VA Bank) dan mutasi transfer manual.' 
                : 'Isi ulang saldo kartu member secara instan melalui Payment Gateway DUITKU atau transfer manual.' 
            ?>
        </p>
    </div>
    <?php if (!$isAdmin): ?>
    <div style="display: flex; gap: 8px;">
        <a href="index.php?page=katalog" class="btn btn-secondary">Katalog Produk</a>
        <a href="index.php?page=member" class="btn btn-secondary">Beranda Member</a>
    </div>
    <?php endif; ?>
</div>

<?php if (!$isAdmin): ?>
    <!-- Banner Info Saldo Member -->
    <div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #fff; padding: 20px; border: none; box-shadow: 0 4px 14px rgba(37,99,235,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; opacity: 0.8; letter-spacing: 0.5px;">KARTU MEMBER ANDA</span>
                <div style="font-size: 18px; font-weight: 700; margin-top: 2px;">
                    <?= e($member['nama_pelanggan']) ?> (<?= e($member['kode_member'] ?: '-') ?>)
                </div>
                <div style="font-size: 12px; opacity: 0.9; margin-top: 4px;">
                    Saldo dapat digunakan langsung untuk belanja hemat dan cepat di kasir toko ToKo.
                </div>
            </div>
            <div style="text-align: right; background: rgba(255,255,255,0.15); padding: 10px 18px; border-radius: 8px; backdrop-filter: blur(4px);">
                <small style="font-size: 11px; opacity: 0.85; text-transform: uppercase; font-weight: 600;">Saldo Aktif Saat Ini</small>
                <div style="font-size: 24px; font-weight: 800; color: #fff;"><?= format_rupiah($member['saldo']) ?></div>
            </div>
        </div>
    </div>

    <!-- Active Invoice Modal / Card jika baru saja membuat transaksi Duitku -->
    <?php if (!empty($activeInvoice)): ?>
    <div class="card" style="margin-bottom: 25px; border: 2px solid #0284c7; background: #f0f9ff; padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #bae6fd; padding-bottom: 10px; margin-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 22px;">⚡</span>
                <div>
                    <h3 style="margin: 0; color: #0369a1; font-size: 16px;">Invoice Pembayaran Online Duitku Aktif</h3>
                    <small style="color: #0284c7;">Order ID: <strong><?= e($activeInvoice['order_id']) ?></strong> (Ref: <?= e($activeInvoice['duitku_reference']) ?>)</small>
                </div>
            </div>
            <a href="index.php?page=topup" class="btn btn-secondary btn-sm" onclick="<?php Session::remove('duitku_active_invoice'); ?>">Tutup Invoice</a>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 15px;">
            <div>
                <small style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600;">Metode Pembayaran</small>
                <div style="font-size: 15px; font-weight: 700; color: #0c4a6e;"><?= e($activeInvoice['channel_name']) ?></div>
            </div>
            <div>
                <small style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600;">Total Tagihan</small>
                <div style="font-size: 18px; font-weight: 800; color: #047857;"><?= format_rupiah($activeInvoice['amount']) ?></div>
            </div>
            <?php if (!empty($activeInvoice['va_number'])): ?>
            <div>
                <small style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600;">Nomor Virtual Account</small>
                <div style="font-size: 18px; font-weight: 800; color: #1e3a8a; font-family: monospace;"><?= e($activeInvoice['va_number']) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <?php if (!empty($activeInvoice['payment_url'])): ?>
                <a href="<?= e($activeInvoice['payment_url']) ?>" target="_blank" class="btn btn-primary" style="background: #0284c7; border-color: #0284c7; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                    <span>💳 Buka Halaman Pembayaran Duitku</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                </a>
            <?php endif; ?>
            <a href="index.php?page=topup&action=check_duitku&order_id=<?= urlencode($activeInvoice['order_id']) ?>" class="btn btn-secondary" style="font-weight: 600;">
                🔄 Cek Status Pembayaran
            </a>
            <?php if (!empty($duitkuConfig['sandbox'])): ?>
                <form method="POST" action="index.php?page=duitku_callback" style="display: inline-block; margin: 0;">
                    <input type="hidden" name="merchantCode" value="<?= e($duitkuConfig['merchant_code']) ?>">
                    <input type="hidden" name="amount" value="<?= e((string)(int)$activeInvoice['amount']) ?>">
                    <input type="hidden" name="merchantOrderId" value="<?= e($activeInvoice['order_id']) ?>">
                    <input type="hidden" name="signature" value="<?= e(Duitku::generateCallbackSignature($activeInvoice['order_id'], (int)$activeInvoice['amount'])) ?>">
                    <input type="hidden" name="resultCode" value="00">
                    <input type="hidden" name="reference" value="<?= e($activeInvoice['duitku_reference']) ?>">
                    <input type="hidden" name="redirect_return" value="index.php?page=topup&status=finish">
                    <button type="submit" class="btn btn-success" style="font-weight: 700; background: #059669; border-color: #059669;">⚡ Simulasikan Lunas (Sandbox)</button>
                </form>
            <?php endif; ?>
            <small style="color: #64748b; font-size: 12px;">Saldo akan otomatis terisi saat pembayaran Anda telah berhasil.</small>
        </div>
    </div>
    <?php endif; ?>

    <!-- TAB PILIHAN MODE TOP-UP: DUITKU GATEWAY vs TRANSFER MANUAL -->
    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
        <button type="button" class="btn btn-primary tab-mode-btn" id="tab-btn-duitku" style="font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
            <span>⚡ Pembayaran Otomatis (DUITKU)</span>
        </button>
        <button type="button" class="btn btn-secondary tab-mode-btn" id="tab-btn-manual" style="font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
            <span>🏦 Transfer Manual Toko</span>
        </button>
    </div>

    <!-- 1. FORM METODE PEMBAYARAN DUITKU (OTOMATIS) -->
    <section class="card" id="section-duitku-topup" style="margin-bottom: 25px; border: 2px solid #0284c7; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.1);">
        <div class="card-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 18px;">
            <div>
                <div class="card-title" style="font-size: 16px; font-weight: 700; color: #0369a1; display: flex; align-items: center; gap: 6px;">
                    <span>⚡ Pembayaran Instan via Payment Gateway DUITKU</span>
                    <?php if (!empty($duitkuConfig['sandbox'])): ?>
                        <span class="badge badge-warning" style="font-size: 10px;">Mode Sandbox</span>
                    <?php endif; ?>
                </div>
                <small style="color: #64748b;">Pilih saluran pembayaran. Saldo kartu member akan <strong>otomatis terisi secara real-time</strong> tanpa menunggu konfirmasi admin.</small>
            </div>
        </div>

        <form method="POST" action="index.php?page=topup" id="form-duitku-request">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="duitku_request">
            <input type="hidden" name="channel_code" id="selected_duitku_channel" value="NQ">

            <!-- Grid Pilihan Kanal Duitku -->
            <label style="font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 8px; display: block;">
                Pilih Kanal Pembayaran Online:
            </label>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 20px;">
                <?php foreach ($duitkuChannels as $code => $ch): ?>
                    <div class="duitku-ch-card <?= $code === 'NQ' ? 'selected' : '' ?>" data-code="<?= $code ?>" 
                         style="border: 2px solid <?= $code === 'NQ' ? '#0284c7' : '#e2e8f0' ?>; border-radius: 8px; padding: 12px; cursor: pointer; transition: all 0.15s ease; background: <?= $code === 'NQ' ? '#f0f9ff' : '#fff' ?>;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <span style="font-size: 20px;"><?= $ch['icon'] ?></span>
                            <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 10px; font-weight: 700;"><?= e($code) ?></span>
                        </div>
                        <strong style="display: block; font-size: 13px; color: #1e293b;"><?= e($ch['name']) ?></strong>
                        <span style="font-size: 11px; color: #64748b;"><?= e($ch['desc']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Nominal Cepat Duitku -->
            <div style="margin-bottom: 15px;">
                <label style="font-size: 12px; font-weight: 600; color: #475569; display: block; margin-bottom: 8px;">
                    Pilih Nominal Cepat:
                </label>
                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                    <button type="button" class="btn btn-secondary btn-sm d-nominal-chip" data-nominal="20000">Rp 20.000</button>
                    <button type="button" class="btn btn-secondary btn-sm d-nominal-chip" data-nominal="50000">Rp 50.000</button>
                    <button type="button" class="btn btn-secondary btn-sm d-nominal-chip" data-nominal="100000">Rp 100.000</button>
                    <button type="button" class="btn btn-secondary btn-sm d-nominal-chip" data-nominal="200000">Rp 200.000</button>
                    <button type="button" class="btn btn-secondary btn-sm d-nominal-chip" data-nominal="500000">Rp 500.000</button>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 20px; max-width: 400px;">
                <label for="d_nominal" style="font-weight: 700; font-size: 13px;">Nominal Top-up (Rp) *</label>
                <input class="form-control" type="number" id="d_nominal" name="nominal" min="10000" max="9999999999" step="1000" required placeholder="Contoh: 50000" style="font-size: 16px; font-weight: 700;">
                <small style="color: #64748b; font-size: 11px;">Minimal Rp 10.000 untuk transaksi Payment Gateway.</small>
            </div>

            <div style="display: flex; justify-content: flex-start;">
                <button type="submit" class="btn btn-primary" style="background: #0284c7; border-color: #0284c7; padding: 10px 24px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px;">
                    <span>⚡ Lanjut Bayar Otomatis via Duitku</span>
                </button>
            </div>
        </form>
    </section>

    <!-- 2. FORM METODE TRANSFER MANUAL (OPSIONAL) -->
    <section class="card" id="section-manual-topup" style="display: none; margin-bottom: 25px; border: 2px solid #e2e8f0;">
        <div class="card-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 18px;">
            <div>
                <div class="card-title" style="font-size: 16px; font-weight: 700; color: #1e293b;">
                    Transfer Manual ke Rekening Toko
                </div>
                <small style="color: #64748b;">Transfer ke rekening resmi toko dan masukkan nomor referensi untuk diverifikasi oleh admin:</small>
            </div>
        </div>

        <form method="POST" action="index.php?page=topup" id="form-topup-manual">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="request">
            <input type="hidden" name="metode_pembayaran" id="selected_metode_manual" value="dana">

            <!-- Pilihan 4 Kanal Manual -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px;">
                <?php foreach ($manualChannels as $key => $ch): ?>
                    <div class="channel-card <?= $key === 'dana' ? 'selected' : '' ?>" data-channel="<?= $key ?>" 
                         style="border: 2px solid <?= $key === 'dana' ? $ch['color'] : '#e2e8f0' ?>; border-radius: 10px; padding: 14px; cursor: pointer; transition: all 0.2s ease; background: <?= $key === 'dana' ? '#f0f9ff' : '#fff' ?>;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 22px;"><?= $ch['icon'] ?></span>
                            <span class="badge" style="background: <?= $ch['color'] ?>; color: #fff; font-size: 10px; font-weight: 700;"><?= e($ch['name']) ?></span>
                        </div>
                        <strong style="display: block; font-size: 14px; color: #1e293b;"><?= e($ch['name']) ?></strong>
                        <span style="font-size: 11px; color: #64748b;"><?= e($ch['type']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Box Detail Rekening Manual -->
            <div id="payment-details-box" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px; margin-bottom: 22px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 10px;">
                    <div>
                        <small style="text-transform: uppercase; font-size: 11px; font-weight: 700; color: #64748b;">Tujuan Transfer <span id="display-ch-name">DANA</span></small>
                        <div style="font-size: 18px; font-weight: 800; font-family: monospace; color: #1e3a8a; letter-spacing: 0.5px;" id="display-ch-account">
                            0812-3456-7890
                        </div>
                        <div style="font-size: 12px; color: #475569;">Atas Nama: <strong id="display-ch-holder">ToKo Official</strong></div>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" id="btn-copy-account" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                        <span>📋 Salin Nomor</span>
                    </button>
                </div>
                <div style="font-size: 12px; color: #475569; line-height: 1.5;">
                    <strong style="color: #1e293b;">Panduan:</strong>
                    <span id="display-ch-guide"><?= e($manualChannels['dana']['guide']) ?></span>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div class="form-group" style="margin: 0;">
                    <label for="m_nominal" style="font-weight: 600; font-size: 13px;">Nominal Top-up (Rp) *</label>
                    <input class="form-control" type="number" id="m_nominal" name="nominal" min="1000" max="9999999999" step="1000" required placeholder="50000">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label for="referensi_pembayaran" style="font-weight: 600; font-size: 13px;">Nomor Referensi Transfer *</label>
                    <input class="form-control" type="text" id="referensi_pembayaran" name="referensi_pembayaran" maxlength="120" required placeholder="Contoh: 20261118123456" style="font-family: monospace;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label for="catatan" style="font-weight: 600; font-size: 13px;">Catatan (Opsional)</label>
                <input class="form-control" type="text" id="catatan" name="catatan" maxlength="500" placeholder="Keterangan transfer">
            </div>

            <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-size: 14px; font-weight: 700;">
                Kirim Pengajuan Manual
            </button>
        </form>
    </section>

<?php else: ?>
    <!-- Notice Admin -->
    <section class="card" style="margin-bottom: 20px; background: #fefce8; border-left: 4px solid #eab308; padding: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <strong style="color: #854d0e; font-size: 14px;">ℹ️ Verifikasi Top-up Kasir &amp; Admin</strong>
                <p style="margin: 4px 0 0 0; color: #713f12; font-size: 13px;">
                    Transaksi berlabel <strong>Duitku Online</strong> dikonfirmasi dan diverifikasi secara otomatis melalui Webhook API. Anda dapat menyetujui transaksi manual secara langsung.
                </p>
            </div>
            <a href="index.php?page=pengaturan" class="btn btn-secondary btn-sm">⚙️ Pengaturan Kredensial Duitku</a>
        </div>
    </section>
<?php endif; ?>

<!-- Tabel Riwayat Pengajuan Top-up -->
<section class="card">
    <div class="card-header" style="border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; margin-bottom: 15px;">
        <div>
            <div class="card-title" style="font-size: 16px; font-weight: 700; color: #1e293b;">
                <?= $isAdmin ? 'Daftar Pengajuan Top-up' : 'Riwayat Pengajuan Top-up Anda' ?>
            </div>
            <small style="color: #64748b;"><?= count($requests) ?> pengajuan tercatat</small>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; font-size: 12px; text-align: left;">
                    <?php if ($isAdmin): ?>
                        <th style="padding: 10px 12px;">Member</th>
                    <?php endif; ?>
                    <th style="padding: 10px 12px;">Tipe / Kanal</th>
                    <th style="padding: 10px 12px; text-align: right;">Nominal</th>
                    <th style="padding: 10px 12px;">Order ID / Referensi</th>
                    <th style="padding: 10px 12px;">Catatan</th>
                    <th style="padding: 10px 12px;">Waktu</th>
                    <th style="padding: 10px 12px; text-align: center;">Status</th>
                    <th style="padding: 10px 12px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$requests): ?>
                    <tr>
                        <td colspan="<?= $isAdmin ? 8 : 7 ?>" style="padding: 35px; text-align: center; color: #94a3b8;">
                            <?= $isAdmin ? 'Tidak ada permintaan top-up yang menunggu saat ini.' : 'Belum ada pengajuan top-up yang dilakukan.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $request): ?>
                        <?php
                            $isDuitku = ($request['tipe_pengajuan'] ?? '') === 'duitku';
                            $channelCode = strtoupper($request['channel_code'] ?? '');
                            $chInfo = $duitkuChannels[$channelCode] ?? ($manualChannels[strtolower($request['metode_pembayaran'] ?? '')] ?? null);
                            $chName = $chInfo['name'] ?? strtoupper($request['metode_pembayaran'] ?? 'Online');
                        ?>
                        <tr style="border-bottom: 1px solid #edf2f7; font-size: 13px;">
                            <?php if ($isAdmin): ?>
                                <td style="padding: 10px 12px;">
                                    <strong><?= e($request['nama_pelanggan']) ?></strong>
                                    <div style="font-size: 11px; font-family: monospace; color: #64748b;">
                                        <?= e($request['kode_member'] ?? '-') ?>
                                    </div>
                                </td>
                            <?php endif; ?>

                            <td style="padding: 10px 12px;">
                                <?php if ($isDuitku): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 4px; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 2px 8px; border-radius: 4px; font-size: 11px;">
                                        <span>⚡ Duitku</span>
                                        <span>(<?= e($channelCode ?: $chName) ?>)</span>
                                    </span>
                                <?php else: ?>
                                    <span style="display: inline-flex; align-items: center; gap: 4px; font-weight: 600; color: #475569; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-size: 11px;">
                                        <span>🏦 Manual</span>
                                        <span>(<?= e($chName) ?>)</span>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td style="padding: 10px 12px; text-align: right; font-weight: 700; color: #047857;">
                                <?= format_rupiah($request['nominal']) ?>
                            </td>

                            <td style="padding: 10px 12px; font-family: monospace; font-size: 12px; color: #1e3a8a;">
                                <?= e($request['referensi_pembayaran']) ?>
                            </td>

                            <td style="padding: 10px 12px; color: #64748b; font-size: 12px;">
                                <?= e($request['catatan'] ?: '-') ?>
                            </td>

                            <td style="padding: 10px 12px; font-size: 12px; white-space: nowrap;">
                                <?= e(format_tanggal($request['dibuat_pada'], true)) ?>
                            </td>

                            <td style="padding: 10px 12px; text-align: center;">
                                <?php if ($request['status'] === 'disetujui'): ?>
                                    <span class="badge badge-success">✓ Disetujui</span>
                                <?php elseif ($request['status'] === 'ditolak'): ?>
                                    <span class="badge badge-danger">✗ Ditolak</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">⏳ Menunggu</span>
                                <?php endif; ?>
                            </td>

                            <td style="padding: 10px 12px; text-align: center; white-space: nowrap;">
                                <?php if ($isAdmin): ?>
                                    <?php if ($request['status'] === 'menunggu'): ?>
                                        <div style="display: inline-flex; gap: 6px;">
                                            <form method="POST" action="index.php?page=topup" onsubmit="return confirm('Verifikasi dan setujui top-up ini?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="post_action" value="approve">
                                                <input type="hidden" name="id_topup" value="<?= e($request['id_topup']) ?>">
                                                <button class="btn btn-primary btn-sm" type="submit" style="background: #10b981; border-color: #10b981;">✓ Verifikasi</button>
                                            </form>
                                            <form method="POST" action="index.php?page=topup" onsubmit="return confirm('Tolak pengajuan top-up ini?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="post_action" value="reject">
                                                <input type="hidden" name="id_topup" value="<?= e($request['id_topup']) ?>">
                                                <button class="btn btn-secondary btn-sm" type="submit" style="color: #ef4444;">Tolak</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size: 11px; color: #64748b;">
                                            Verifikator: <?= e($request['verifikator'] ?? 'Sistem/Admin') ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($request['status'] === 'menunggu' && $isDuitku && !empty($request['payment_url'])): ?>
                                        <div style="display: inline-flex; gap: 4px;">
                                            <a href="<?= e($request['payment_url']) ?>" target="_blank" class="btn btn-primary btn-sm" style="font-size: 11px; padding: 3px 8px;">Bayar</a>
                                            <a href="index.php?page=topup&action=check_duitku&order_id=<?= urlencode($request['referensi_pembayaran']) ?>" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 3px 8px;">Cek</a>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">-</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Script Interaktif UI -->
<script>
(function() {
    // Mode Switcher: Duitku vs Manual
    const tabDuitku = document.getElementById('tab-btn-duitku');
    const tabManual = document.getElementById('tab-btn-manual');
    const secDuitku = document.getElementById('section-duitku-topup');
    const secManual = document.getElementById('section-manual-topup');

    if (tabDuitku && tabManual) {
        tabDuitku.addEventListener('click', function() {
            tabDuitku.classList.remove('btn-secondary');
            tabDuitku.classList.add('btn-primary');
            tabManual.classList.remove('btn-primary');
            tabManual.classList.add('btn-secondary');

            secDuitku.style.display = 'block';
            secManual.style.display = 'none';
        });

        tabManual.addEventListener('click', function() {
            tabManual.classList.remove('btn-secondary');
            tabManual.classList.add('btn-primary');
            tabDuitku.classList.remove('btn-primary');
            tabDuitku.classList.add('btn-secondary');

            secManual.style.display = 'block';
            secDuitku.style.display = 'none';
        });
    }

    // Duitku Channel Selector
    const duitkuCards = document.querySelectorAll('.duitku-ch-card');
    const inputDuitkuCh = document.getElementById('selected_duitku_channel');
    duitkuCards.forEach(card => {
        card.addEventListener('click', function() {
            duitkuCards.forEach(c => {
                c.classList.remove('selected');
                c.style.borderColor = '#e2e8f0';
                c.style.background = '#fff';
            });
            this.classList.add('selected');
            this.style.borderColor = '#0284c7';
            this.style.background = '#f0f9ff';
            if (inputDuitkuCh) {
                inputDuitkuCh.value = this.getAttribute('data-code');
            }
        });
    });

    // Nominal Cepat Duitku
    const dNominal = document.getElementById('d_nominal');
    document.querySelectorAll('.d-nominal-chip').forEach(chip => {
        chip.addEventListener('click', function() {
            if (dNominal) {
                dNominal.value = this.getAttribute('data-nominal');
                dNominal.focus();
            }
        });
    });

    // Manual Channels
    const manualChannels = <?= json_encode($manualChannels) ?>;
    const manualCards = document.querySelectorAll('.channel-card');
    const inputManualMethod = document.getElementById('selected_metode_manual');
    const displayChName = document.getElementById('display-ch-name');
    const displayChAccount = document.getElementById('display-ch-account');
    const displayChHolder = document.getElementById('display-ch-holder');
    const displayChGuide = document.getElementById('display-ch-guide');
    const btnCopy = document.getElementById('btn-copy-account');
    let currentAccount = manualChannels['dana'] ? manualChannels['dana'].account_no : '';

    manualCards.forEach(card => {
        card.addEventListener('click', function() {
            const ch = this.getAttribute('data-channel');
            if (!manualChannels[ch]) return;

            manualCards.forEach(c => {
                c.classList.remove('selected');
                c.style.borderColor = '#e2e8f0';
                c.style.background = '#fff';
            });

            this.classList.add('selected');
            this.style.borderColor = manualChannels[ch].color;
            this.style.background = '#f0f9ff';

            if (inputManualMethod) inputManualMethod.value = ch;

            const data = manualChannels[ch];
            currentAccount = data.account_no;
            if (displayChName) displayChName.textContent = data.name;
            if (displayChAccount) displayChAccount.textContent = data.account_no;
            if (displayChHolder) displayChHolder.textContent = data.account_name;
            if (displayChGuide) displayChGuide.textContent = data.guide;
        });
    });

    if (btnCopy) {
        btnCopy.addEventListener('click', function() {
            const cleanAcc = currentAccount.replace(/[^0-9]/g, '');
            navigator.clipboard.writeText(cleanAcc).then(() => {
                const orig = btnCopy.innerHTML;
                btnCopy.innerHTML = '<span>✓ Tersalin!</span>';
                btnCopy.style.background = '#10b981';
                btnCopy.style.color = '#fff';
                setTimeout(() => {
                    btnCopy.innerHTML = orig;
                    btnCopy.style.background = '';
                    btnCopy.style.color = '';
                }, 1500);
            });
        });
    }
})();
</script>

<?php require_once dirname(dirname(__DIR__)) . '/templates/footer.php'; ?>
