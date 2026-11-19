<?php
/**
 * Customer-only member dashboard, balance, and personal sales history.
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/core/Database.php';
require_once dirname(dirname(__DIR__)) . '/models/MemberAccount.php';
require_once dirname(dirname(__DIR__)) . '/models/Wallet.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';

Auth::requireRole('pelanggan', 'index.php?page=login');
$member = MemberAccount::getMemberForUser((int)Auth::id());
if (!$member) {
    Auth::logout();
    Session::setFlash('danger', 'Akun ini tidak terhubung dengan data kartu member. Hubungi admin toko.');
    header('Location: index.php?page=login');
    exit;
}

$transactions = Database::getInstance()->fetchAll(
    "SELECT id_transaksi, tanggal, total_belanja, metode_pembayaran
     FROM tbl_transaksi
     WHERE id_pelanggan = :member_id AND status_pembayaran = 'lunas'
     ORDER BY id_transaksi DESC
     LIMIT 8",
    [':member_id' => $member['id_pelanggan']]
);
$purchaseCount = (int)(Database::getInstance()->fetchOne(
    "SELECT COUNT(*) AS total FROM tbl_transaksi WHERE id_pelanggan = :member_id AND status_pembayaran = 'lunas'",
    [':member_id' => $member['id_pelanggan']]
)['total'] ?? 0);
$mutations = Wallet::getLedger((int)$member['id_pelanggan'], 6);
$topups = Wallet::getRequests((int)$member['id_pelanggan']);
$pageTitle = 'Akun Member';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<section class="member-welcome">
    <div>
        <span class="eyebrow">RUANG MEMBER</span>
        <h1>Halo, <?= e($member['nama_pelanggan']) ?></h1>
        <p>Kelola saldo, cek pembelian, dan lihat aktivitas kartu member Anda.</p>
        <div class="member-quick-actions">
            <a class="btn btn-primary" href="index.php?page=topup">Top-up Saldo (DANA / GoPay / Bank)</a>
            <a class="btn btn-secondary" href="index.php?page=katalog">Jelajahi Katalog</a>
        </div>
    </div>
    <div class="member-card-preview">
        <span>ToKo member</span>
        <strong><?= e($member['kode_member'] ?: 'Kartu member') ?></strong>
        <span><?= e(strtoupper($member['nama_pelanggan'])) ?></span>
    </div>
</section>

<section class="member-overview">
    <article class="member-balance-card">
        <span>Saldo tersedia</span>
        <strong><?= format_rupiah($member['saldo']) ?></strong>
        <small>Saldo digunakan untuk pembayaran di kasir.</small>
        <a href="index.php?page=topup">Lihat pengajuan saldo <span aria-hidden="true">→</span></a>
    </article>
    <article class="member-stat-card">
        <span>Total pembelian</span>
        <strong><?= format_angka($purchaseCount) ?></strong>
        <small>Total transaksi menggunakan kartu</small>
    </article>
    <article class="member-stat-card">
        <span>Kartu member</span>
        <strong class="member-code"><?= e($member['kode_member'] ?: '-') ?></strong>
        <small>Tunjukkan kode ini saat membayar di kasir.</small>
    </article>
</section>

<section class="member-content-grid">
    <article class="card">
        <div class="card-header">
            <div><div class="card-title">Riwayat pembelian</div><small>Pembelian yang terkait kartu member Anda</small></div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Transaksi</th><th>Tanggal</th><th>Metode</th><th class="text-right">Total</th></tr></thead>
                <tbody>
                    <?php if (!$transactions): ?>
                        <tr><td colspan="4" class="table-empty">Belum ada pembelian menggunakan kartu ini.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><a class="member-transaction-link" href="index.php?page=transaksi&action=struk&id=<?= e($transaction['id_transaksi']) ?>">#<?= e($transaction['id_transaksi']) ?></a></td>
                                <td><?= e(format_tanggal($transaction['tanggal'])) ?></td>
                                <td><span class="badge badge-info"><?= ($transaction['metode_pembayaran'] ?? '') === 'saldo_member' ? 'Saldo member' : 'Tunai' ?></span></td>
                                <td class="text-right"><?= format_rupiah($transaction['total_belanja']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="card">
        <div class="card-header">
            <div><div class="card-title">Aktivitas saldo</div><small>Mutasi saldo terbaru</small></div>
        </div>
        <div class="wallet-activity-list">
            <?php if (!$mutations): ?>
                <p class="empty-state">Belum ada mutasi saldo.</p>
            <?php else: ?>
                <?php foreach ($mutations as $mutation): ?>
                    <div class="wallet-activity-row">
                        <span class="wallet-mutation-icon <?= $mutation['jenis'] === 'topup' ? 'is-credit' : 'is-debit' ?>" aria-hidden="true"><?= $mutation['jenis'] === 'topup' ? '+' : '−' ?></span>
                        <div>
                            <strong><?= $mutation['jenis'] === 'topup' ? 'Saldo ditambahkan' : ($mutation['jenis'] === 'pembelian' ? 'Pembayaran belanja' : 'Penyesuaian saldo') ?></strong>
                            <small><?= e(format_tanggal($mutation['dibuat_pada'], true)) ?></small>
                        </div>
                        <span class="<?= $mutation['jenis'] === 'topup' ? 'amount-credit' : 'amount-debit' ?>">
                            <?= $mutation['jenis'] === 'topup' ? '+' : '−' ?><?= format_rupiah($mutation['nominal']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </article>
</section>

<section class="card member-topups-card">
    <div class="card-header">
        <div><div class="card-title">Pengajuan top-up</div><small>Saldo hanya dikreditkan setelah admin mencocokkan pembayaran.</small></div>
        <a href="index.php?page=topup" class="text-link">Kelola top-up</a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Nominal</th><th>Referensi</th><th>Diajukan</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (!$topups): ?>
                    <tr><td colspan="4" class="table-empty">Belum ada pengajuan top-up.</td></tr>
                <?php else: ?>
                    <?php foreach (array_slice($topups, 0, 5) as $topup): ?>
                        <tr>
                            <td><?= format_rupiah($topup['nominal']) ?></td>
                            <td><?= e($topup['referensi_pembayaran']) ?></td>
                            <td><?= e(format_tanggal($topup['dibuat_pada'], true)) ?></td>
                            <td><span class="badge <?= $topup['status'] === 'disetujui' ? 'badge-success' : ($topup['status'] === 'ditolak' ? 'badge-danger' : 'badge-warning') ?>"><?= e(ucfirst($topup['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once dirname(dirname(__DIR__)) . '/templates/footer.php'; ?>
