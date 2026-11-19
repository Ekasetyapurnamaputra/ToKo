<?php
/**
 * Staff dashboard with live sales metrics and recent store activity.
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Database.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/Laporan.php';
require_once dirname(dirname(__DIR__)) . '/models/Log.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';

Auth::requireLogin();
if (Auth::hasRole('pelanggan')) {
    header('Location: index.php?page=member');
    exit;
}

$db = Database::getInstance();
$user = Auth::user();
$summary = Laporan::getRingkasanPendapatan();
$totalCustomers = (int)($db->fetchOne('SELECT COUNT(*) AS total FROM tbl_pelanggan')['total'] ?? 0);
$totalProducts = (int)($db->fetchOne('SELECT COUNT(*) AS total FROM tbl_produk')['total'] ?? 0);

$dailyRows = $db->fetchAll(
    "SELECT DATE(tanggal) AS sale_day, COALESCE(SUM(total_belanja), 0) AS revenue, COUNT(*) AS transactions
     FROM tbl_transaksi
     WHERE status_pembayaran = 'lunas'
       AND tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
       AND tanggal < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
     GROUP BY DATE(tanggal)
     ORDER BY sale_day ASC"
);
$dailySales = [];
foreach ($dailyRows as $row) {
    $dailySales[$row['sale_day']] = [
        'revenue' => (float)$row['revenue'],
        'transactions' => (int)$row['transactions']
    ];
}

$chartDays = [];
$chartStart = new DateTimeImmutable('-6 days');
$dayLabels = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
$maxDailySales = 0.0;
$weeklyRevenue = 0.0;
for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
    $day = $chartStart->modify("+{$dayOffset} days");
    $key = $day->format('Y-m-d');
    $sales = $dailySales[$key] ?? ['revenue' => 0.0, 'transactions' => 0];
    $chartDays[] = [
        'date' => $key,
        'label' => $dayLabels[(int)$day->format('w')],
        'revenue' => $sales['revenue'],
        'transactions' => $sales['transactions']
    ];
    $maxDailySales = max($maxDailySales, $sales['revenue']);
    $weeklyRevenue += $sales['revenue'];
}

$topProducts = $db->fetchAll(
    "SELECT nama_produk, COALESCE(SUM(jumlah), 0) AS units
     FROM tbl_detail_transaksi dt
     JOIN tbl_transaksi t ON t.id_transaksi = dt.id_transaksi AND t.status_pembayaran = 'lunas'
     GROUP BY nama_produk
     ORDER BY units DESC, nama_produk ASC
     LIMIT 4"
);
$totalUnits = (int)($db->fetchOne(
    "SELECT COALESCE(SUM(dt.jumlah), 0) AS total
     FROM tbl_detail_transaksi dt
     JOIN tbl_transaksi t ON t.id_transaksi = dt.id_transaksi
     WHERE t.status_pembayaran = 'lunas'"
)['total'] ?? 0);
$featuredUnits = array_sum(array_map(static fn(array $product): int => (int)$product['units'], $topProducts));
$otherUnits = max(0, $totalUnits - $featuredUnits);
$palette = ['#4163c7', '#7990df', '#a8b7eb', '#d2daf3', '#e9edf7'];
$productMix = [];
$gradientStops = [];
$gradientOffset = 0.0;
foreach ($topProducts as $index => $product) {
    $units = (int)$product['units'];
    $percentage = $totalUnits > 0 ? ($units / $totalUnits) * 100 : 0;
    $productMix[] = [
        'name' => $product['nama_produk'],
        'units' => $units,
        'color' => $palette[$index]
    ];
    $gradientStops[] = $palette[$index] . ' ' . round($gradientOffset, 2) . '% ' . round($gradientOffset + $percentage, 2) . '%';
    $gradientOffset += $percentage;
}
if ($otherUnits > 0) {
    $percentage = $totalUnits > 0 ? ($otherUnits / $totalUnits) * 100 : 0;
    $productMix[] = ['name' => 'Produk lainnya', 'units' => $otherUnits, 'color' => $palette[4]];
    $gradientStops[] = $palette[4] . ' ' . round($gradientOffset, 2) . '% 100%';
}
$donutBackground = $gradientStops
    ? 'conic-gradient(' . implode(', ', $gradientStops) . ')'
    : 'conic-gradient(#e9edf7 0% 100%)';

$recentTransactions = $db->fetchAll(
    "SELECT t.id_transaksi, t.tanggal, t.total_belanja, p.nama_pelanggan
     FROM tbl_transaksi t
     LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     WHERE t.status_pembayaran = 'lunas'
     ORDER BY t.id_transaksi DESC
     LIMIT 5"
);
$recentLogs = Log::getLogs(null, null, null, null, 4);

$pageTitle = 'Dashboard';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<section class="dashboard-welcome">
    <div>
        <span class="eyebrow">RINGKASAN TOKO · <?= e(format_tanggal(date('Y-m-d'))) ?></span>
        <h1>Selamat datang, <?= e($user['nama_lengkap'] ?? $user['username']) ?></h1>
        <p>Pantau performa penjualan dan aktivitas toko Anda dari satu tempat.</p>
    </div>
    <div class="dashboard-actions">
        <a href="index.php?page=laporan" class="btn btn-secondary">Lihat laporan</a>
        <a href="index.php?page=transaksi&action=create" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"></path></svg>
            Transaksi baru
        </a>
    </div>
</section>

<section class="dashboard-kpis" aria-label="Ringkasan penjualan">
    <article class="kpi-card">
        <div class="kpi-heading"><span>Total penjualan</span><span class="kpi-icon kpi-icon-blue" aria-hidden="true">Rp</span></div>
        <strong class="kpi-value"><?= format_rupiah($summary['total_semua']) ?></strong>
        <span class="kpi-caption">Akumulasi seluruh transaksi</span>
    </article>
    <article class="kpi-card">
        <div class="kpi-heading"><span>Jumlah transaksi</span><span class="kpi-icon kpi-icon-violet" aria-hidden="true">#</span></div>
        <strong class="kpi-value"><?= format_angka($summary['total_transaksi_semua']) ?></strong>
        <span class="kpi-caption"><?= (int)$summary['transaksi_hari_ini'] ?> transaksi hari ini</span>
    </article>
    <article class="kpi-card">
        <div class="kpi-heading"><span>Total produk</span><span class="kpi-icon kpi-icon-teal" aria-hidden="true">▦</span></div>
        <strong class="kpi-value"><?= format_angka($totalProducts) ?></strong>
        <span class="kpi-caption">Produk terdaftar di katalog</span>
    </article>
    <article class="kpi-card">
        <div class="kpi-heading"><span>Total pelanggan</span><span class="kpi-icon kpi-icon-amber" aria-hidden="true">◎</span></div>
        <strong class="kpi-value"><?= format_angka($totalCustomers) ?></strong>
        <span class="kpi-caption">Pelanggan terdaftar</span>
    </article>
</section>

<section class="dashboard-main-grid">
    <article class="card sales-chart-card">
        <div class="card-header">
            <div><div class="card-title">Tren penjualan</div><small>Omzet harian selama 7 hari terakhir</small></div>
            <div class="chart-summary"><span>7 hari</span><strong><?= format_rupiah($weeklyRevenue) ?></strong></div>
        </div>
        <div class="sales-chart" role="img" aria-label="Grafik omzet harian 7 hari terakhir">
            <?php foreach ($chartDays as $day): ?>
                <?php $height = $maxDailySales > 0 ? max(4, (int)round(($day['revenue'] / $maxDailySales) * 100)) : 4; ?>
                <div class="sales-chart-column" title="<?= e(format_tanggal($day['date'])) ?> — <?= e(format_rupiah($day['revenue'])) ?>, <?= (int)$day['transactions'] ?> transaksi">
                    <div class="sales-chart-track"><div class="sales-chart-bar" style="height: <?= $height ?>%;"></div></div>
                    <span class="sales-chart-day"><?= e($day['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="chart-legend"><span></span>Omzet penjualan</div>
    </article>

    <article class="card top-products-card">
        <div class="card-header">
            <div><div class="card-title">Produk terlaris</div><small>Distribusi unit dari transaksi</small></div>
            <a href="index.php?page=laporan&tab=produk" class="text-link">Lihat laporan</a>
        </div>
        <div class="product-chart">
            <div class="donut-chart" role="img" aria-label="Komposisi unit terjual berdasarkan produk" style="--donut-background: <?= e($donutBackground) ?>;">
                <div class="donut-center"><strong><?= format_angka($totalUnits) ?></strong><span>unit terjual</span></div>
            </div>
            <ul class="product-legend">
                <?php if (!$productMix): ?>
                    <li class="empty-state">Belum ada data produk terjual.</li>
                <?php else: ?>
                    <?php foreach ($productMix as $item): ?>
                        <li><span class="legend-dot" style="--legend-color: <?= e($item['color']) ?>;"></span><span class="legend-name" title="<?= e($item['name']) ?>"><?= e($item['name']) ?></span><strong><?= format_angka($item['units']) ?></strong></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </article>
</section>

<section class="dashboard-lower-grid">
    <article class="card recent-sales-card">
        <div class="card-header">
            <div><div class="card-title">Transaksi terbaru</div><small>Penjualan yang baru diproses</small></div>
            <a href="index.php?page=transaksi" class="text-link">Semua transaksi</a>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>No. transaksi</th><th>Tanggal</th><th>Pelanggan</th><th class="text-right">Total</th><th></th></tr></thead>
                <tbody>
                    <?php if (!$recentTransactions): ?>
                        <tr><td colspan="5" class="table-empty">Belum ada transaksi tersimpan.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td><strong>#<?= e($transaction['id_transaksi']) ?></strong></td>
                                <td><?= e(format_tanggal($transaction['tanggal'])) ?></td>
                                <td><?= e($transaction['nama_pelanggan'] ?? 'Umum') ?></td>
                                <td class="text-right amount-cell"><?= format_rupiah($transaction['total_belanja']) ?></td>
                                <td class="text-right"><a href="index.php?page=transaksi&action=struk&id=<?= e($transaction['id_transaksi']) ?>" class="btn btn-secondary btn-sm">Detail</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="card activity-card">
        <div class="card-header">
            <div><div class="card-title">Aktivitas terbaru</div><small>Ringkasan aktivitas sistem</small></div>
            <?php if (Auth::hasRole('admin')): ?><a href="index.php?page=log" class="text-link">Buka log</a><?php endif; ?>
        </div>
        <div class="activity-list">
            <?php if (!$recentLogs): ?>
                <p class="empty-state">Belum ada aktivitas tercatat.</p>
            <?php else: ?>
                <?php foreach ($recentLogs as $log): ?>
                    <div class="activity-item">
                        <span class="activity-marker" aria-hidden="true"></span>
                        <div><strong><?= e($log['tipe_aktivitas']) ?></strong><p><?= e($log['deskripsi']) ?></p><time><?= e(format_tanggal($log['waktu'], true)) ?></time></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </article>
</section>

<?php require_once dirname(dirname(__DIR__)) . '/templates/footer.php'; ?>
