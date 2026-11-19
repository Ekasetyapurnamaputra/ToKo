<?php
/**
 * Preferensi tampilan dan konfigurasi sistem aplikasi ToKo.
 * Mendukung pengaturan integrasi Payment Gateway DUITKU untuk Administrator.
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/core/Logger.php';
require_once dirname(dirname(__DIR__)) . '/core/Duitku.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';

Auth::requireLogin();
$currentUser = Auth::user();
$isAdmin = Auth::hasRole('admin');

// Proses Simpan Pengaturan Kredensial DUITKU (Khusus Admin)
if (is_post() && $isAdmin) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        Session::setFlash('danger', 'Validasi token keamanan CSRF gagal.');
        header('Location: index.php?page=pengaturan');
        exit;
    }

    if (isset($_POST['save_duitku'])) {
        $merchantCode     = clean($_POST['merchant_code'] ?? '');
        $apiKey           = trim($_POST['api_key'] ?? '');
        $sandbox          = isset($_POST['sandbox']) ? true : false;
        $callbackUrlInput = trim($_POST['callback_url'] ?? '');
        $returnUrlInput   = trim($_POST['return_url'] ?? '');

        $duitkuConfigFile = dirname(dirname(__DIR__)) . '/config/duitku.php';
        $currentDuitku = file_exists($duitkuConfigFile) ? require $duitkuConfigFile : [];
        $currentDuitku['merchant_code'] = $merchantCode;
        if (!empty($apiKey)) {
            $currentDuitku['api_key'] = $apiKey;
        }
        $currentDuitku['sandbox'] = $sandbox;
        $currentDuitku['callback_url'] = $callbackUrlInput;
        $currentDuitku['return_url']   = $returnUrlInput;

        $content = "<?php\n/**\n * Konfigurasi Integrasi Payment Gateway DUITKU\n * Dokumentasi: https://docs.duitku.com/\n */\n\nreturn " . var_export($currentDuitku, true) . ";\n";
        file_put_contents($duitkuConfigFile, $content);

        Logger::log('DUITKU_CONFIG_UPDATED', "Admin memperbarui kredensial Payment Gateway Duitku (Merchant: {$merchantCode}, Sandbox: " . ($sandbox ? 'Ya' : 'Tidak') . ")");
        Session::setFlash('success', 'Pengaturan Payment Gateway DUITKU berhasil diperbarui dan disimpan.');
        header('Location: index.php?page=pengaturan');
        exit;
    }
}

$duitkuConfig = Duitku::getConfig();
$callbackUrl = Duitku::getCallbackUrl();
$returnUrl = Duitku::getReturnUrl();

$pageTitle = 'Pengaturan';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<div class="page-intro">
    <div>
        <h2>Pengaturan</h2>
        <p>Sesuaikan tampilan dan integrasi sistem payment gateway toko.</p>
    </div>
</div>

<div class="settings-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
    <!-- Card 1: Preferensi Tampilan -->
    <section class="card settings-card">
        <div class="card-header">
            <div>
                <div class="card-title">Preferensi Tampilan</div>
                <small>Pengaturan ini disimpan di browser perangkat Anda.</small>
            </div>
        </div>

        <label class="settings-option" for="compact-sidebar">
            <span>
                <strong>Sidebar ringkas</strong>
                <small>Tampilkan navigasi ikon saja pada layar desktop.</small>
            </span>
            <input class="settings-switch" type="checkbox" id="compact-sidebar" data-preference="sidebar-compact">
        </label>

        <label class="settings-option" for="compact-tables">
            <span>
                <strong>Tabel lebih padat</strong>
                <small>Kurangi jarak baris untuk menampilkan lebih banyak data.</small>
            </span>
            <input class="settings-switch" type="checkbox" id="compact-tables" data-preference="tables-compact">
        </label>
    </section>

    <!-- Card 2: Informasi Akun Pengguna -->
    <section class="card settings-card">
        <div class="card-header">
            <div>
                <div class="card-title">Informasi Akun</div>
                <small>Akun yang sedang aktif di sistem.</small>
            </div>
            <span class="badge badge-<?= e($currentUser['role']) ?>"><?= e(ucfirst($currentUser['role'])) ?></span>
        </div>

        <dl class="settings-details">
            <div><dt>Nama pengguna</dt><dd><?= e($currentUser['nama_lengkap']) ?></dd></div>
            <div><dt>Username</dt><dd><?= e($currentUser['username']) ?></dd></div>
            <div><dt>Hak akses</dt><dd><?= e(ucfirst($currentUser['role'])) ?></dd></div>
            <div><dt>Versi aplikasi</dt><dd><?= e(APP_VERSION) ?></dd></div>
        </dl>
    </section>

    <?php if ($isAdmin): ?>
    <!-- Card 3: Konfigurasi Payment Gateway DUITKU (Khusus Admin) -->
    <section class="card settings-card" style="grid-column: 1 / -1; border-left: 4px solid #0284c7;">
        <div class="card-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
            <div>
                <div class="card-title" style="color: #0369a1; display: flex; align-items: center; gap: 8px;">
                    <span>⚡ Integrasi Payment Gateway DUITKU</span>
                    <span class="badge" style="background: <?= !empty($duitkuConfig['sandbox']) ? '#f59e0b' : '#10b981' ?>; color: #fff;">
                        <?= !empty($duitkuConfig['sandbox']) ? 'Mode Sandbox' : 'Mode Production' ?>
                    </span>
                </div>
                <small style="color: #64748b;">Kelola kredensial API Key untuk pembayaran instan kartu member (QRIS, DANA, GoPay, OVO, ShopeePay, VA BCA, VA BRI, dsb).</small>
            </div>
        </div>

        <form method="POST" action="index.php?page=pengaturan">
            <?= csrf_field() ?>
            <input type="hidden" name="save_duitku" value="1">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div class="form-group" style="margin: 0;">
                    <label for="merchant_code" style="font-weight: 700; font-size: 13px;">Merchant Code Duitku *</label>
                    <input class="form-control" type="text" id="merchant_code" name="merchant_code" 
                           value="<?= e($duitkuConfig['merchant_code'] ?? '') ?>" required placeholder="Contoh: D12345" style="font-family: monospace;">
                    <small style="color: #64748b; font-size: 11px;">Dapatkan Merchant Code dari dashboard merchant.duitku.com</small>
                </div>

                <div class="form-group" style="margin: 0;">
                    <label for="api_key" style="font-weight: 700; font-size: 13px;">API Key Duitku *</label>
                    <input class="form-control" type="password" id="api_key" name="api_key" 
                           value="<?= e($duitkuConfig['api_key'] ?? '') ?>" required placeholder="Masukkan API Key Duitku" style="font-family: monospace;">
                    <small style="color: #64748b; font-size: 11px;">Kunci rahasia API dari menu Integrasi Project Duitku.</small>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 18px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="sandbox" value="1" <?= !empty($duitkuConfig['sandbox']) ? 'checked' : '' ?> style="width: 16px; height: 16px;">
                    <span style="font-weight: 600; font-size: 13px; color: #1e293b;">
                        Aktifkan Mode Sandbox (Uji Coba Transaksi Tanpa Uang Asli)
                    </span>
                </label>
            </div>

            <!-- Box URL Webhook Callback & Return URL -->
            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 18px;">
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">
                        URL Webhook Callback (Public HTTPS via Ngrok / Domain):
                    </label>
                    <div style="display: flex; gap: 8px; margin-top: 3px;">
                        <input class="form-control" type="text" name="callback_url" value="<?= e($duitkuConfig['callback_url'] ?? $callbackUrl) ?>" id="input-callback-url" style="font-family: monospace; font-size: 12px; background: #fff;" placeholder="Contoh: https://xxxx.ngrok-free.app/ToKo/index.php?page=duitku_callback">
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-copy-cb">Salin</button>
                    </div>
                    <small style="color: #64748b; font-size: 11px;">URL ini akan dipanggil oleh server Duitku saat status transaksi selesai. Salin ke dashboard Project Duitku.</small>
                </div>

                <div>
                    <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">
                        URL Return Pelanggan:
                    </label>
                    <div style="display: flex; gap: 8px; margin-top: 3px;">
                        <input class="form-control" type="text" name="return_url" value="<?= e($duitkuConfig['return_url'] ?? $returnUrl) ?>" id="input-return-url" style="font-family: monospace; font-size: 12px; background: #fff;" placeholder="Contoh: https://xxxx.ngrok-free.app/ToKo/index.php?page=topup">
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-copy-ret">Salin</button>
                    </div>
                    <small style="color: #64748b; font-size: 11px;">Halaman tujuan pengguna setelah menyelesaikan pembayaran di gateway Duitku.</small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="padding: 9px 20px; font-weight: 700;">
                Simpan Kredensial Duitku
            </button>
        </form>
    </section>

    <script>
    document.getElementById('btn-copy-cb')?.addEventListener('click', function() {
        const input = document.getElementById('input-callback-url');
        navigator.clipboard.writeText(input.value).then(() => {
            alert('URL Callback Webhook tersalin ke clipboard!');
        });
    });
    document.getElementById('btn-copy-ret')?.addEventListener('click', function() {
        const input = document.getElementById('input-return-url');
        navigator.clipboard.writeText(input.value).then(() => {
            alert('URL Return tersalin ke clipboard!');
        });
    });
    </script>
    <?php endif; ?>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
