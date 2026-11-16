<?php
/**
 * Modul Dashboard - Sistem Informasi Penjualan ToKoKita
 * Menampilkan data riil toko: pendapatan, produk terlaris, metrik, pelanggan, log
 * Layout mengikuti referensi visual SaaS dengan data domain retail Indonesia
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

$db = Database::getInstance();
$currentUser = Auth::user();

// ====================================================================
// QUERY DATA RIIL DARI DATABASE
// ====================================================================

// 1. Ringkasan Pendapatan (hari ini, bulan ini, keseluruhan)
$ringkasan = Laporan::getRingkasanPendapatan();

// 2. Produk Terlaris (Top 6 untuk Bar Chart)
$topProduk = Laporan::getProdukTerlaris(6);

// 3. Pelanggan Teratas (Top 5)
$topPelanggan = Laporan::getLaporanPelanggan(5);

// 4. Metrik Toko (total produk, kategori, pelanggan, stok rendah)
$totalProduk    = $db->fetchOne("SELECT COUNT(*) AS jml FROM tbl_produk WHERE status = 'aktif'")['jml'] ?? 0;
$totalKategori  = $db->fetchOne("SELECT COUNT(*) AS jml FROM tbl_kategori")['jml'] ?? 0;
$totalPelanggan = $db->fetchOne("SELECT COUNT(*) AS jml FROM tbl_pelanggan")['jml'] ?? 0;
$stokRendah     = $db->fetchOne("SELECT COUNT(*) AS jml FROM tbl_produk WHERE status = 'aktif' AND stok <= 10")['jml'] ?? 0;
$transaksiHariIni = (int)($ringkasan['transaksi_hari_ini'] ?? 0);
$totalTransaksi   = (int)($ringkasan['total_transaksi_semua'] ?? 0);

// 5. Rata-rata Transaksi
$avgTransaksi = $totalTransaksi > 0
    ? (float)$ringkasan['total_semua'] / $totalTransaksi
    : 0;

// 6. Transaksi Terbaru (5 terakhir)
$recentTrx = $db->fetchAll("SELECT t.id_transaksi, t.tanggal, t.total_belanja,
                                    p.nama_pelanggan, p.kota
                             FROM tbl_transaksi t
                             LEFT JOIN tbl_pelanggan p ON t.id_pelanggan = p.id_pelanggan
                             ORDER BY t.id_transaksi DESC LIMIT 5");

// 7. Log Aktivitas Terbaru (5 terakhir)
$recentLogs = Log::getLogs(null, null, null, null, 5);

// 8. Max terjual (untuk proporsi bar chart)
$maxTerjual = 1;
foreach ($topProduk as $tp) {
    if ((int)$tp['total_terjual'] > $maxTerjual) {
        $maxTerjual = (int)$tp['total_terjual'];
    }
}

$pageTitle = 'Dashboard';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<div class="dash-layout">
    
    <!-- LEFT & CENTER MAIN COLUMN -->
    <div class="dash-main-col">
        
        <!-- 1. WELCOME CARD -->
        <div class="welcome-card">
            <div class="welcome-content">
                <h2>Halo, <?= e($currentUser['nama_lengkap'] ?? $currentUser['username'] ?? 'Kasir') ?>!</h2>
                <p>
                    <?php if ($transaksiHariIni > 0): ?>
                        Hari ini sudah ada <strong><?= $transaksiHariIni ?></strong> transaksi senilai <strong><?= format_rupiah($ringkasan['total_hari_ini']) ?></strong>. Lanjutkan!
                    <?php else: ?>
                        Belum ada transaksi hari ini. Yuk mulai berjualan!
                    <?php endif; ?>
                </p>
                <a href="index.php?page=transaksi&action=create" class="welcome-link">+ Transaksi Baru</a>
            </div>

            <!-- Ilustrasi Toko -->
            <div class="welcome-illus no-print">
                <svg width="220" height="150" viewBox="0 0 240 160" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Desk Shadow -->
                    <ellipse cx="120" cy="148" rx="90" ry="8" fill="#e2e8f0"/>
                    <!-- Meja Kasir -->
                    <rect x="35" y="105" width="170" height="10" rx="5" fill="#3b82f6"/>
                    <rect x="50" y="115" width="10" height="30" rx="3" fill="#1d4ed8"/>
                    <rect x="180" y="115" width="10" height="30" rx="3" fill="#1d4ed8"/>
                    <!-- Mesin Kasir -->
                    <rect x="70" y="55" width="45" height="35" rx="4" fill="#334155"/>
                    <rect x="73" y="58" width="39" height="29" rx="2" fill="#0ea5e9"/>
                    <rect x="89" y="90" width="7" height="15" fill="#64748b"/>
                    <ellipse cx="92.5" cy="105" rx="14" ry="3" fill="#475569"/>
                    <!-- Barang Belanjaan -->
                    <rect x="150" y="80" width="22" height="25" rx="3" fill="#f59e0b"/>
                    <rect x="158" y="72" width="18" height="33" rx="3" fill="#10b981"/>
                    <rect x="170" y="85" width="15" height="20" rx="2" fill="#e11d48"/>
                    <!-- Karakter -->
                    <rect x="110" y="80" width="28" height="35" rx="8" fill="#3b82f6"/>
                    <path d="M102 90 L115 105" stroke="#3b82f6" stroke-width="7" stroke-linecap="round"/>
                    <path d="M135 88 L152 65" stroke="#fbcfe8" stroke-width="6" stroke-linecap="round"/>
                    <path d="M152 56 L154 50" stroke="#f59e0b" stroke-width="2" stroke-linecap="round"/>
                    <path d="M159 59 L165 55" stroke="#f59e0b" stroke-width="2" stroke-linecap="round"/>
                    <circle cx="124" cy="62" r="14" fill="#fed7aa"/>
                    <ellipse cx="124" cy="53" rx="16" ry="6" fill="#78350f"/>
                    <path d="M112 52 C114 42, 134 42, 136 52 Z" fill="#b45309"/>
                    <circle cx="120" cy="62" r="1.5" fill="#1e293b"/>
                    <circle cx="128" cy="62" r="1.5" fill="#1e293b"/>
                    <path d="M121 67 Q124 70 127 67" stroke="#1e293b" stroke-width="1.2" fill="none" stroke-linecap="round"/>
                    <!-- Kursi -->
                    <ellipse cx="124" cy="118" rx="20" ry="5" fill="#1e293b"/>
                    <line x1="124" y1="118" x2="124" y2="135" stroke="#64748b" stroke-width="4"/>
                    <circle cx="112" cy="138" r="4" fill="#334155"/>
                    <circle cx="136" cy="138" r="4" fill="#334155"/>
                    <line x1="124" y1="135" x2="112" y2="138" stroke="#334155" stroke-width="3"/>
                    <line x1="124" y1="135" x2="136" y2="138" stroke="#334155" stroke-width="3"/>
                </svg>
            </div>
        </div>

        <!-- 2. DUAL CARDS: Performa Penjualan & Metrik Toko -->
        <div class="dual-cards-row">
            
            <!-- Performa Penjualan (Bar Chart dari Produk Terlaris) -->
            <div class="sleek-card">
                <div class="card-top">
                    <span class="card-top-title">Performa Penjualan</span>
                    <div class="dropdown-pill">
                        <span>Semua</span>
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </div>
                </div>

                <?php if (!empty($topProduk)): ?>
                    <div class="perf-sub">Produk terlaris:</div>

                    <div class="perf-score-row">
                        <div>
                            <span class="perf-score"><?= number_format((int)$topProduk[0]['total_terjual']) ?></span>
                            <span class="perf-sub-label"><?= e($topProduk[0]['nama_produk']) ?><br>terjual</span>
                        </div>
                        <a href="index.php?page=laporan" class="pill-outline-btn">Lihat Laporan</a>
                    </div>

                    <!-- Bar Chart: Produk Terlaris -->
                    <div class="bars-row">
                        <?php foreach ($topProduk as $tp): 
                            $persen = round(((int)$tp['total_terjual'] / $maxTerjual) * 100);
                        ?>
                            <div class="bar-item">
                                <span class="bar-val"><?= (int)$tp['total_terjual'] ?></span>
                                <div class="bar-pillar" style="height: 100px;">
                                    <div class="bar-pillar-fill" style="height: <?= $persen ?>%;"></div>
                                </div>
                                <span class="bar-lbl"><?= e(mb_strimwidth($tp['nama_produk'], 0, 14, '..')) ?></span>
                            </div>
                        <?php endforeach; ?>

                        <?php for ($pad = count($topProduk); $pad < 6; $pad++): ?>
                            <div class="bar-item">
                                <span class="bar-val">0</span>
                                <div class="bar-pillar" style="height: 100px;">
                                    <div class="bar-pillar-fill" style="height: 0%;"></div>
                                </div>
                                <span class="bar-lbl">-</span>
                            </div>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <div style="padding: 40px 0; text-align: center; color: var(--text-muted); font-size: 13px;">
                        Belum ada data penjualan produk.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Metrik Toko (Ring Gauges) -->
            <div class="sleek-card">
                <div class="card-top">
                    <span class="card-top-title">Metrik Toko</span>
                    <div class="dropdown-pill">
                        <span>Realtime</span>
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </div>
                </div>

                <?php
                // Gauge data: label => [value, max, color_class_suffix]
                $gauges = [
                    ['label' => 'Produk<br>Aktif',       'val' => $totalProduk,      'max' => max($totalProduk, 1),      'pct' => min(100, $totalProduk > 0 ? 100 : 0)],
                    ['label' => 'Kategori',              'val' => $totalKategori,     'max' => max($totalKategori, 1),    'pct' => min(100, $totalKategori > 0 ? 100 : 0)],
                    ['label' => 'Pelanggan',             'val' => $totalPelanggan,    'max' => max($totalPelanggan, 1),   'pct' => min(100, $totalPelanggan > 0 ? 100 : 0)],
                    ['label' => 'Total<br>Transaksi',    'val' => $totalTransaksi,    'max' => max($totalTransaksi, 1),   'pct' => min(100, $totalTransaksi > 0 ? 100 : 0)],
                    ['label' => 'Transaksi<br>Hari Ini', 'val' => $transaksiHariIni,  'max' => max($transaksiHariIni, 1), 'pct' => min(100, $transaksiHariIni > 0 ? round(($transaksiHariIni / max($totalTransaksi, 1)) * 100) : 0)],
                    ['label' => 'Stok<br>Rendah',        'val' => $stokRendah,        'max' => max($totalProduk, 1),      'pct' => $totalProduk > 0 ? round(($stokRendah / $totalProduk) * 100) : 0],
                ];
                ?>

                <div class="gauges-grid">
                    <?php foreach ($gauges as $g): ?>
                        <div class="gauge-item">
                            <div class="gauge-circle">
                                <svg viewBox="0 0 36 36">
                                    <path class="gauge-circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                                    <path class="gauge-circle-stroke" stroke-dasharray="<?= $g['pct'] ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                                </svg>
                                <span class="gauge-percent"><?= $g['val'] ?></span>
                            </div>
                            <span class="gauge-lbl"><?= $g['label'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <!-- 3. PELANGGAN TERATAS -->
        <div class="teachers-card">
            <div class="card-top">
                <span class="card-top-title">Pelanggan Teratas</span>
                <a href="index.php?page=pelanggan" style="color: var(--primary); text-decoration: none; font-size: 12px; font-weight: 700;">Lihat semua</a>
            </div>

            <div class="teachers-list">
                <?php if (!empty($topPelanggan)): ?>
                    <?php 
                    $avatarColors = [
                        ['bg' => '#fed7aa', 'fg' => '#9a3412'],
                        ['bg' => '#e0e7ff', 'fg' => '#3730a3'],
                        ['bg' => '#d1fae5', 'fg' => '#065f46'],
                        ['bg' => '#fce7f3', 'fg' => '#9d174d'],
                        ['bg' => '#e0f2fe', 'fg' => '#0369a1'],
                    ];
                    foreach ($topPelanggan as $idx => $plg):
                        $initials = mb_strtoupper(mb_substr($plg['nama_pelanggan'], 0, 2));
                        $color = $avatarColors[$idx % count($avatarColors)];
                    ?>
                        <div class="teacher-row">
                            <div class="teacher-info">
                                <div class="teacher-avatar" style="background: <?= $color['bg'] ?>; color: <?= $color['fg'] ?>;"><?= e($initials) ?></div>
                                <div>
                                    <div class="teacher-name">
                                        <?= e($plg['nama_pelanggan']) ?>
                                        <?php if ($plg['status_loyalitas'] === 'Pelanggan VIP'): ?>
                                            <span style="font-size: 10px; background: #fbbf24; color: #78350f; padding: 1px 6px; border-radius: 10px; margin-left: 4px; font-weight: 700;">VIP</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="teacher-role"><?= e($plg['kota'] ?? '-') ?> · <?= (int)$plg['total_transaksi'] ?> transaksi · <?= format_rupiah($plg['total_belanja']) ?></div>
                                </div>
                            </div>
                            <div class="teacher-actions">
                                <a href="index.php?page=pelanggan" class="action-circle-btn" title="Lihat Detail">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                </a>
                                <a href="index.php?page=transaksi&action=create" class="action-circle-btn" title="Buat Transaksi">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="padding: 20px; text-align: center; color: var(--text-muted); font-size: 13px;">
                        Belum ada data pelanggan.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- RIGHT SIDEBAR COLUMN -->
    <div class="dash-right-col">
        
        <!-- 1. RINGKASAN FINANSIAL -->
        <div class="calendar-card">
            <div class="card-top">
                <div>
                    <span class="card-top-title">Ringkasan Finansial</span>
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;"><?= date('d M Y') ?></div>
                </div>
                <div class="dropdown-pill">
                    <span>Hari Ini</span>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </div>
            </div>

            <div class="cal-timeline">
                <!-- Pendapatan Hari Ini -->
                <div class="timeline-slot" style="align-items: flex-start;">
                    <span class="timeline-time" style="padding-top: 10px; font-size: 10px; min-width: 55px;">Hari Ini</span>
                    <div class="schedule-pill-active">
                        <div class="schedule-pill-active-icon">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                        </div>
                        <div>
                            <div class="schedule-pill-active-title"><?= format_rupiah($ringkasan['total_hari_ini']) ?></div>
                            <div class="schedule-pill-active-sub"><?= $transaksiHariIni ?> transaksi hari ini</div>
                        </div>
                    </div>
                </div>

                <!-- Dotted Separator -->
                <div class="timeline-slot">
                    <span class="timeline-time" style="font-size: 10px; min-width: 55px;"></span>
                    <div class="timeline-dotted-line"></div>
                </div>

                <!-- Pendapatan Bulan Ini -->
                <div class="timeline-slot" style="align-items: flex-start;">
                    <span class="timeline-time" style="padding-top: 8px; font-size: 10px; min-width: 55px;">Bulan Ini</span>
                    <div class="schedule-pill-gray">
                        <div class="schedule-pill-gray-icon">&#9679;</div>
                        <div>
                            <div class="schedule-pill-gray-title"><?= format_rupiah($ringkasan['total_bulan_ini']) ?></div>
                            <div class="schedule-pill-gray-sub"><?= (int)$ringkasan['transaksi_bulan_ini'] ?> transaksi bulan ini</div>
                        </div>
                    </div>
                </div>

                <!-- Total Keseluruhan -->
                <div class="timeline-slot" style="align-items: flex-start;">
                    <span class="timeline-time" style="padding-top: 8px; font-size: 10px; min-width: 55px;">Total</span>
                    <div class="schedule-pill-gray">
                        <div class="schedule-pill-gray-icon">&#9679;</div>
                        <div>
                            <div class="schedule-pill-gray-title"><?= format_rupiah($ringkasan['total_semua']) ?></div>
                            <div class="schedule-pill-gray-sub"><?= $totalTransaksi ?> transaksi keseluruhan</div>
                        </div>
                    </div>
                </div>

                <!-- Rata-rata per Transaksi -->
                <div class="timeline-slot" style="align-items: flex-start;">
                    <span class="timeline-time" style="padding-top: 8px; font-size: 10px; min-width: 55px;">Rata-rata</span>
                    <div class="schedule-pill-gray">
                        <div class="schedule-pill-gray-icon">&#9679;</div>
                        <div>
                            <div class="schedule-pill-gray-title"><?= format_rupiah($avgTransaksi) ?></div>
                            <div class="schedule-pill-gray-sub">Rata-rata per transaksi</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. AKTIVITAS TERKINI (Log & Transaksi Terakhir) -->
        <div class="events-card">
            <div class="card-top">
                <span class="card-top-title">Aktivitas Terkini</span>
                <a href="index.php?page=<?= Auth::hasRole('admin') ? 'log' : 'transaksi' ?>" style="color: var(--primary); text-decoration: none; font-size: 12px; font-weight: 700;">Lihat semua</a>
            </div>

            <div class="events-list">
                <?php if (!empty($recentTrx)): ?>
                    <?php foreach (array_slice($recentTrx, 0, 3) as $trx): ?>
                        <div class="event-row">
                            <div class="event-thumb" style="background: #0f172a;">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                            </div>
                            <div class="event-details">
                                <div class="event-title">Transaksi #<?= e($trx['id_transaksi']) ?> — <?= e($trx['nama_pelanggan'] ?? 'Umum') ?> (<?= format_rupiah($trx['total_belanja']) ?>)</div>
                                <div class="event-date">&#9711; <?= format_tanggal($trx['tanggal']) ?></div>
                            </div>
                            <a href="index.php?page=transaksi&action=struk&id=<?= e($trx['id_transaksi']) ?>" style="color: var(--primary); font-size: 11px; font-weight: 600; text-decoration: none; white-space: nowrap;">Struk</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($recentLogs)): ?>
                    <?php foreach (array_slice($recentLogs, 0, 2) as $log): ?>
                        <div class="event-row">
                            <div class="event-thumb" style="background: #0284c7;">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#e0f2fe" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            </div>
                            <div class="event-details">
                                <div class="event-title"><?= e(mb_strimwidth($log['deskripsi'] ?? $log['tipe_aktivitas'] ?? 'Aktivitas', 0, 60, '...')) ?></div>
                                <div class="event-date">&#9711; <?= e($log['nama_lengkap'] ?? $log['username'] ?? '-') ?> &nbsp;<?= date('d M Y H:i', strtotime($log['waktu'])) ?></div>
                            </div>
                            <div class="event-menu">&#8942;</div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (empty($recentTrx) && empty($recentLogs)): ?>
                    <div style="padding: 20px; text-align: center; color: var(--text-muted); font-size: 13px;">
                        Belum ada aktivitas tercatat.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

<?php
require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
