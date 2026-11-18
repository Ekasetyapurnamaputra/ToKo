<?php
/**
 * Modul Top-up Saldo Kartu Member & Pembayaran Multi-Kanal
 * Mendukung Kanal Pembayaran: DANA, GoPay, Bank BRI, dan Bank BCA
 * Dilengkapi Verifikasi Sisi Admin & Audit Trail Keuangan
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/MemberAccount.php';
require_once dirname(dirname(__DIR__)) . '/models/Wallet.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';

Auth::requireRole(['admin', 'pelanggan'], 'index.php?page=login');
$isAdmin = Auth::hasRole('admin');
$member = $isAdmin ? null : MemberAccount::getMemberForUser((int)Auth::id());
if (!$isAdmin && !$member) {
    Session::setFlash('danger', 'Akun ini tidak terhubung dengan data kartu member.');
    header('Location: index.php?page=login');
    exit;
}

// Konfigurasi Resmi Rekening & E-Wallet Toko
$paymentChannels = [
    'dana' => [
        'name'         => 'DANA',
        'type'         => 'E-Wallet',
        'account_no'   => '0812-3456-7890',
        'account_name' => 'ToKo Official',
        'color'        => '#118eea',
        'badge_class'  => 'badge-info',
        'icon'         => '📱',
        'guide'        => 'Buka aplikasi DANA > Kirim > Kirim ke Nomor Telepon 081234567890 > Masukkan nominal > Konfirmasi PIN > Salin Nomor Transaksi / ID Transaksi pada bukti transfer.'
    ],
    'gopay' => [
        'name'         => 'GoPay',
        'type'         => 'E-Wallet (Gojek)',
        'account_no'   => '0812-3456-7890',
        'account_name' => 'ToKo Official',
        'color'        => '#00aa13',
        'badge_class'  => 'badge-success',
        'icon'         => '🟢',
        'guide'        => 'Buka aplikasi Gojek / GoPay > Bayar / Transfer > Masukkan nomor 081234567890 > Masukkan nominal > Konfirmasi Transfer > Salin Nomor ID Transaksi GoPay.'
    ],
    'bri' => [
        'name'         => 'Bank BRI',
        'type'         => 'Transfer Bank (BRImo / ATM)',
        'account_no'   => '0123-01-000456-50-8',
        'account_name' => 'ToKo Retail Indonesia',
        'color'        => '#00529c',
        'badge_class'  => 'badge-primary',
        'icon'         => '🏦',
        'guide'        => 'Buka aplikasi BRImo atau ATM BRI > Transfer > Masukkan No. Rekening 012301000456508 (BRI) > Masukkan nominal > Simpan struk & salin Nomor Referensi Transfer.'
    ],
    'bca' => [
        'name'         => 'Bank BCA',
        'type'         => 'Transfer Bank (BCA Mobile / ATM)',
        'account_no'   => '8830-1234-56',
        'account_name' => 'ToKo Retail Indonesia',
        'color'        => '#00368a',
        'badge_class'  => 'badge-primary',
        'icon'         => '🏦',
        'guide'        => 'Buka aplikasi BCA mobile (m-BCA / myBCA) atau ATM > m-Transfer > Antar Rekening BCA 8830123456 > Masukkan nominal > Salin Nomor Referensi / No. Bukti Transaksi.'
    ]
];

// Proses POST Form
if (is_post()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        Session::setFlash('danger', 'Validasi keamanan gagal. Silakan muat ulang halaman, lalu coba lagi.');
        header('Location: index.php?page=topup');
        exit;
    }

    $action = clean($_POST['post_action'] ?? '');

    if ($action === 'request' && !$isAdmin) {
        $method = clean($_POST['metode_pembayaran'] ?? 'dana');
        $nominal = (float)($_POST['nominal'] ?? 0);
        $referensi = clean($_POST['referensi_pembayaran'] ?? '');
        $catatan = clean($_POST['catatan'] ?? '');

        if (!array_key_exists($method, $paymentChannels)) {
            $method = 'dana';
        }

        $result = Wallet::requestTopup(
            (int)$member['id_pelanggan'],
            $nominal,
            $referensi,
            $catatan,
            $method
        );
    } elseif ($isAdmin && $action === 'approve') {
        $result = Wallet::approveTopup((int)($_POST['id_topup'] ?? 0), (int)Auth::id());
    } elseif ($isAdmin && $action === 'reject') {
        $result = Wallet::rejectTopup((int)($_POST['id_topup'] ?? 0), (int)Auth::id());
    } else {
        $result = ['success' => false, 'message' => 'Aksi tidak diizinkan.'];
    }

    Session::setFlash($result['success'] ? 'success' : 'danger', $result['message']);
    header('Location: index.php?page=topup');
    exit;
}

$requests = Wallet::getRequests($isAdmin ? null : (int)$member['id_pelanggan']);
$pageTitle = $isAdmin ? 'Verifikasi Top-up Member' : 'Top-up Saldo & Pembayaran Member';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="margin: 0; display: flex; align-items: center; gap: 8px;">
            <span>💳 <?= $isAdmin ? 'Verifikasi Top-up Saldo Member' : 'Top-up Saldo Kartu Member' ?></span>
        </h2>
        <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">
            <?= $isAdmin 
                ? 'Periksa mutasi dana masuk pada kanal resmi (DANA, GoPay, BRI, BCA) dan setujui kredit saldo member.' 
                : 'Isi ulang saldo kartu member menggunakan transfer DANA, GoPay, Bank BRI, atau Bank BCA.' 
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
    <div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #fff; padding: 20px; border: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; opacity: 0.8; letter-spacing: 0.5px;">KARTU MEMBER ANDA</span>
                <div style="font-size: 18px; font-weight: 700; margin-top: 2px;">
                    <?= e($member['nama_pelanggan']) ?> (<?= e($member['kode_member'] ?: '-') ?>)
                </div>
                <div style="font-size: 12px; opacity: 0.9; margin-top: 4px;">
                    Gunakan saldo ini untuk pembayaran instan di kasir toko ToKo.
                </div>
            </div>
            <div style="text-align: right; background: rgba(255,255,255,0.15); padding: 10px 18px; border-radius: 8px; backdrop-filter: blur(4px);">
                <small style="font-size: 11px; opacity: 0.85; text-transform: uppercase; font-weight: 600;">Saldo Aktif Saat Ini</small>
                <div style="font-size: 24px; font-weight: 800; color: #fff;"><?= format_rupiah($member['saldo']) ?></div>
            </div>
        </div>
    </div>

    <!-- Form Top-up dengan Pilihan Kanal Pembayaran: DANA, GoPay, BRI, BCA -->
    <section class="card" style="margin-bottom: 25px; border: 2px solid #e2e8f0; box-shadow: 0 4px 14px rgba(0,0,0,0.04);">
        <div class="card-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 18px;">
            <div>
                <div class="card-title" style="font-size: 16px; font-weight: 700; color: #1e293b;">
                    Langkah 1: Pilih Metode Pembayaran
                </div>
                <small style="color: #64748b;">Pilih salah satu kanal pembayaran resmi toko kami:</small>
            </div>
        </div>

        <form method="POST" action="index.php?page=topup" id="form-topup-member">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="request">
            <input type="hidden" name="metode_pembayaran" id="selected_metode_pembayaran" value="dana">

            <!-- Pilihan 4 Kanal Pembayaran -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px;">
                <?php foreach ($paymentChannels as $key => $ch): ?>
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

            <!-- Box Rincian Rekening / Nomor Pembayaran Terpilih -->
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
                    <strong style="color: #1e293b;">Panduan Singkat:</strong>
                    <span id="display-ch-guide"><?= e($paymentChannels['dana']['guide']) ?></span>
                </div>
            </div>

            <div class="card-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px;">
                <div class="card-title" style="font-size: 16px; font-weight: 700; color: #1e293b;">
                    Langkah 2: Tentukan Nominal &amp; Konfirmasi
                </div>
            </div>

            <!-- Nominal Cepat -->
            <div style="margin-bottom: 15px;">
                <label style="font-size: 12px; font-weight: 600; color: #475569; display: block; margin-bottom: 8px;">
                    Pilih Nominal Cepat:
                </label>
                <div style="display: flex; flex-wrap: wrap; gap: 8px;" id="quick-nominal-chips">
                    <button type="button" class="btn btn-secondary btn-sm nominal-chip" data-nominal="20000">Rp 20.000</button>
                    <button type="button" class="btn btn-secondary btn-sm nominal-chip" data-nominal="50000">Rp 50.000</button>
                    <button type="button" class="btn btn-secondary btn-sm nominal-chip" data-nominal="100000">Rp 100.000</button>
                    <button type="button" class="btn btn-secondary btn-sm nominal-chip" data-nominal="200000">Rp 200.000</button>
                    <button type="button" class="btn btn-secondary btn-sm nominal-chip" data-nominal="500000">Rp 500.000</button>
                </div>
            </div>

            <!-- Input Form Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div class="form-group" style="margin: 0;">
                    <label for="nominal" style="font-weight: 600; font-size: 13px;">Nominal Top-up (Rp) *</label>
                    <input class="form-control" type="number" id="nominal" name="nominal" min="1000" max="9999999999" step="1000" required placeholder="Contoh: 50000" style="font-size: 15px; font-weight: 700;">
                    <small style="color: #6c757d; font-size: 11px;">Minimal Rp 1.000 (tanpa titik atau koma).</small>
                </div>

                <div class="form-group" style="margin: 0;">
                    <label for="referensi_pembayaran" style="font-weight: 600; font-size: 13px;">Nomor Referensi / ID Transaksi *</label>
                    <input class="form-control" type="text" id="referensi_pembayaran" name="referensi_pembayaran" maxlength="120" required placeholder="Contoh: 20261118123456" autocomplete="off" style="font-family: monospace;">
                    <small style="color: #6c757d; font-size: 11px;">Lihat Nomor Referensi pada bukti transfer DANA / GoPay / BRImo / m-BCA.</small>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label for="catatan" style="font-weight: 600; font-size: 13px;">Catatan Tambahan (Opsional)</label>
                <input class="form-control" type="text" id="catatan" name="catatan" maxlength="500" placeholder="Contoh: Transfer atas nama Budi Santoso pukul 13:45">
            </div>

            <div style="display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <span>Kirim Pengajuan Top-up</span>
                </button>
            </div>
        </form>
    </section>
<?php else: ?>
    <!-- Admin Notice Box -->
    <section class="card" style="margin-bottom: 20px; background: #fefce8; border-left: 4px solid #eab308; padding: 16px;">
        <strong style="color: #854d0e; font-size: 14px;">ℹ️ Verifikasi Top-up Manual Kasir &amp; Admin</strong>
        <p style="margin: 4px 0 0 0; color: #713f12; font-size: 13px;">
            Periksa dana masuk pada akun resmi toko (<strong>DANA</strong>: 0812-3456-7890, <strong>GoPay</strong>: 0812-3456-7890, <strong>BRI</strong>: 0123-01-000456-50-8, <strong>BCA</strong>: 8830-1234-56). Cocokkan nominal dan nomor referensi. Menyetujui pengajuan akan langsung menambahkan saldo ke kartu member.
        </p>
    </section>
<?php endif; ?>

<!-- Tabel Riwayat Pengajuan Top-up -->
<section class="card">
    <div class="card-header" style="border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; margin-bottom: 15px;">
        <div>
            <div class="card-title" style="font-size: 16px; font-weight: 700; color: #1e293b;">
                <?= $isAdmin ? 'Daftar Pengajuan Top-up Menunggu Verifikasi' : 'Riwayat Pengajuan Top-up Anda' ?>
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
                    <th style="padding: 10px 12px;">Metode Pembayaran</th>
                    <th style="padding: 10px 12px; text-align: right;">Nominal</th>
                    <th style="padding: 10px 12px;">No. Referensi</th>
                    <th style="padding: 10px 12px;">Catatan</th>
                    <th style="padding: 10px 12px;">Tanggal Diajukan</th>
                    <th style="padding: 10px 12px; text-align: center;">Status</th>
                    <?php if ($isAdmin): ?>
                        <th style="padding: 10px 12px; text-align: center;">Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$requests): ?>
                    <tr>
                        <td colspan="<?= $isAdmin ? 8 : 6 ?>" style="padding: 35px; text-align: center; color: #94a3b8;">
                            <?= $isAdmin ? 'Tidak ada permintaan top-up yang menunggu saat ini.' : 'Belum ada pengajuan top-up yang dilakukan.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $request): ?>
                        <?php
                            $metodeKey = strtolower($request['metode_pembayaran'] ?? 'dana');
                            $chInfo = $paymentChannels[$metodeKey] ?? [
                                'name' => strtoupper($metodeKey),
                                'icon' => '💳',
                                'color' => '#64748b'
                            ];
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
                                <span style="display: inline-flex; align-items: center; gap: 5px; font-weight: 700; color: <?= $chInfo['color'] ?>; background: #f1f5f9; padding: 3px 8px; border-radius: 4px; font-size: 12px;">
                                    <span><?= $chInfo['icon'] ?></span>
                                    <span><?= e($chInfo['name']) ?></span>
                                </span>
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

                            <?php if ($isAdmin): ?>
                                <td style="padding: 10px 12px; text-align: center; white-space: nowrap;">
                                    <?php if ($request['status'] === 'menunggu'): ?>
                                        <div style="display: inline-flex; gap: 6px;">
                                            <form method="POST" action="index.php?page=topup" onsubmit="return confirm('Pastikan dana via <?= e($chInfo['name']) ?> sebesar <?= format_rupiah($request['nominal']) ?> telah masuk. Tambahkan ke saldo member?');">
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
                                            Verifikator: <?= e($request['verifikator'] ?? 'Admin') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Script Interaktif Pemilihan Kanal & Salin Rekening -->
<script>
(function() {
    const paymentChannels = <?= json_encode($paymentChannels) ?>;
    const channelCards = document.querySelectorAll('.channel-card');
    const inputMetode = document.getElementById('selected_metode_pembayaran');

    const displayChName = document.getElementById('display-ch-name');
    const displayChAccount = document.getElementById('display-ch-account');
    const displayChHolder = document.getElementById('display-ch-holder');
    const displayChGuide = document.getElementById('display-ch-guide');
    const btnCopy = document.getElementById('btn-copy-account');

    let currentAccount = paymentChannels['dana'] ? paymentChannels['dana'].account_no : '';

    channelCards.forEach(card => {
        card.addEventListener('click', function() {
            const ch = this.getAttribute('data-channel');
            if (!paymentChannels[ch]) return;

            // Update UI Selected Card
            channelCards.forEach(c => {
                c.classList.remove('selected');
                c.style.borderColor = '#e2e8f0';
                c.style.background = '#fff';
            });

            this.classList.add('selected');
            this.style.borderColor = paymentChannels[ch].color;
            this.style.background = '#f0f9ff';

            // Update Hidden Input
            if (inputMetode) inputMetode.value = ch;

            // Update Details Box
            const data = paymentChannels[ch];
            currentAccount = data.account_no;
            if (displayChName) displayChName.textContent = data.name;
            if (displayChAccount) displayChAccount.textContent = data.account_no;
            if (displayChHolder) displayChHolder.textContent = data.account_name;
            if (displayChGuide) displayChGuide.textContent = data.guide;
        });
    });

    // Tombol Salin Nomor Rekening / E-Wallet
    if (btnCopy) {
        btnCopy.addEventListener('click', function() {
            const cleanAcc = currentAccount.replace(/[^0-9]/g, '');
            navigator.clipboard.writeText(cleanAcc).then(() => {
                const originalText = btnCopy.innerHTML;
                btnCopy.innerHTML = '<span>✓ Tersalin!</span>';
                btnCopy.style.background = '#10b981';
                btnCopy.style.color = '#fff';
                setTimeout(() => {
                    btnCopy.innerHTML = originalText;
                    btnCopy.style.background = '';
                    btnCopy.style.color = '';
                }, 1500);
            }).catch(err => {
                alert('Gagal menyalin: ' + cleanAcc);
            });
        });
    }

    // Chips Nominal Cepat
    const nominalInput = document.getElementById('nominal');
    document.querySelectorAll('.nominal-chip').forEach(chip => {
        chip.addEventListener('click', function() {
            const val = this.getAttribute('data-nominal');
            if (nominalInput) {
                nominalInput.value = val;
                nominalInput.focus();
            }
        });
    });
})();
</script>

<?php require_once dirname(dirname(__DIR__)) . '/templates/footer.php'; ?>
