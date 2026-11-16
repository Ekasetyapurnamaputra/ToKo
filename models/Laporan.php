<?php
/**
 * Model Laporan
 * Mengelola penyajian data analitik penjualan
 * Menerapkan VIEW, JOIN, SUBQUERY, dan agregasi data untuk laporan akurat
 */

require_once dirname(__DIR__) . '/core/Database.php';

class Laporan
{
    /**
     * Mengambil ringkasan pendapatan keseluruhan, bulan ini, dan hari ini
     * Menerapkan subquery agregasi
     */
    public static function getRingkasanPendapatan(): array
    {
        $db = Database::getInstance();

        $sql = "SELECT 
                    COUNT(*) AS total_transaksi_semua,
                    COALESCE(SUM(total_belanja), 0) AS total_semua,
                    COUNT(CASE WHEN tanggal = CURDATE() THEN 1 END) AS transaksi_hari_ini,
                    COALESCE(SUM(CASE WHEN tanggal = CURDATE() THEN total_belanja ELSE 0 END), 0) AS total_hari_ini,
                    COUNT(CASE WHEN DATE_FORMAT(tanggal, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN 1 END) AS transaksi_bulan_ini,
                    COALESCE(SUM(CASE WHEN DATE_FORMAT(tanggal, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN total_belanja ELSE 0 END), 0) AS total_bulan_ini
                FROM tbl_transaksi";

        return $db->fetchOne($sql) ?? [
            'total_transaksi_semua' => 0,
            'total_semua'           => 0,
            'transaksi_hari_ini'    => 0,
            'total_hari_ini'        => 0,
            'transaksi_bulan_ini'   => 0,
            'total_bulan_ini'       => 0
        ];
    }

    /**
     * Mengambil Laporan Penjualan Harian
     * Memanfaatkan VIEW view_laporan_harian
     */
    public static function getLaporanHarian(?string $tglMulai = null, ?string $tglSelesai = null): array
    {
        $db = Database::getInstance();
        $conditions = [];
        $params = [];

        if (!empty($tglMulai)) {
            $conditions[] = "tanggal >= :tgl_mulai";
            $params[':tgl_mulai'] = $tglMulai;
        }

        if (!empty($tglSelesai)) {
            $conditions[] = "tanggal <= :tgl_selesai";
            $params[':tgl_selesai'] = $tglSelesai;
        }

        $where = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
        return $db->fetchAll("SELECT * FROM view_laporan_harian {$where} ORDER BY tanggal DESC", $params);
    }

    /**
     * Mengambil Laporan Penjualan Bulanan
     * Memanfaatkan VIEW view_laporan_bulanan
     */
    public static function getLaporanBulanan(?string $tahun = null): array
    {
        $db = Database::getInstance();
        $params = [];
        $where = "";

        if (!empty($tahun)) {
            $where = "WHERE periode_bulan LIKE :tahun";
            $params[':tahun'] = "{$tahun}%";
        }

        return $db->fetchAll("SELECT * FROM view_laporan_bulanan {$where} ORDER BY periode_bulan DESC", $params);
    }

    /**
     * Mengambil Laporan Berdasarkan Rentang Tanggal (Rincian Transaksi)
     * Menggunakan JOIN multi-tabel dan Subquery
     */
    public static function getLaporanRentangTanggal(string $tglMulai, string $tglSelesai): array
    {
        $db = Database::getInstance();
        $sql = "SELECT t.id_transaksi, t.tanggal, t.subtotal, t.diskon, t.nilai_diskon, t.total_belanja,
                       p.nama_pelanggan, p.kota,
                       COALESCE(dt_agg.total_item, 0) AS total_item,
                       COALESCE(dt_agg.total_qty, 0) AS total_qty
                FROM tbl_transaksi t
                LEFT JOIN tbl_pelanggan p ON t.id_pelanggan = p.id_pelanggan
                LEFT JOIN (
                    SELECT id_transaksi, COUNT(id_detail) AS total_item, SUM(jumlah) AS total_qty
                    FROM tbl_detail_transaksi
                    GROUP BY id_transaksi
                ) dt_agg ON t.id_transaksi = dt_agg.id_transaksi
                WHERE t.tanggal BETWEEN :tgl_mulai AND :tgl_selesai
                ORDER BY t.tanggal DESC, t.id_transaksi DESC";

        return $db->fetchAll($sql, [
            ':tgl_mulai'   => $tglMulai,
            ':tgl_selesai' => $tglSelesai
        ]);
    }

    /**
     * Mengambil Laporan Produk Terlaris
     * Memanfaatkan VIEW view_produk_terlaris
     */
    public static function getProdukTerlaris(int $limit = 10): array
    {
        $db = Database::getInstance();
        return $db->fetchAll("SELECT * FROM view_produk_terlaris ORDER BY total_terjual DESC LIMIT " . (int)$limit);
    }

    /**
     * Mengambil Laporan Loyalitas Pelanggan (Pelanggan Paling Aktif Belanja)
     * Memanfaatkan VIEW view_total_belanja digabung dengan tbl_pelanggan
     */
    public static function getLaporanPelanggan(int $limit = 15): array
    {
        $db = Database::getInstance();
        $sql = "SELECT v.id_pelanggan, v.nama_pelanggan, p.kota, 
                       v.total_transaksi, v.total_belanja,
                       CASE 
                           WHEN v.total_transaksi >= 3 THEN 'Pelanggan VIP'
                           WHEN v.total_transaksi >= 1 THEN 'Pelanggan Reguler'
                           ELSE 'Belum Bertransaksi'
                       END AS status_loyalitas
                FROM view_total_belanja v
                JOIN tbl_pelanggan p ON v.id_pelanggan = p.id_pelanggan
                ORDER BY v.total_belanja DESC, v.total_transaksi DESC
                LIMIT " . (int)$limit;

        return $db->fetchAll($sql);
    }
}
