<?php
/**
 * Model Log
 * Mengelola data audit trail sistem dan inspeksi log trigger database
 */

require_once dirname(__DIR__) . '/core/Database.php';

class Log
{
    /**
     * Mengambil daftar log aktivitas sistem dari tbl_log
     * Mendukung penyaringan tipe, kata kunci, dan rentang waktu
     */
    public static function getLogs(?string $tipe = null, ?string $keyword = null, ?string $tglMulai = null, ?string $tglSelesai = null, int $limit = 100): array
    {
        $db = Database::getInstance();
        $conditions = [];
        $params = [];

        if (!empty($tipe)) {
            $conditions[] = "l.tipe_aktivitas LIKE :tipe";
            $params[':tipe'] = "%{$tipe}%";
        }

        if (!empty($keyword)) {
            $conditions[] = "(l.deskripsi LIKE :kw OR u.username LIKE :kw OR u.nama_lengkap LIKE :kw OR l.ip_address LIKE :kw)";
            $params[':kw'] = "%{$keyword}%";
        }

        if (!empty($tglMulai)) {
            $conditions[] = "DATE(l.waktu) >= :tgl_mulai";
            $params[':tgl_mulai'] = $tglMulai;
        }

        if (!empty($tglSelesai)) {
            $conditions[] = "DATE(l.waktu) <= :tgl_selesai";
            $params[':tgl_selesai'] = $tglSelesai;
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $sql = "SELECT l.*, u.username, u.nama_lengkap, u.role 
                FROM tbl_log l
                LEFT JOIN tbl_user u ON l.id_user = u.id_user
                {$whereClause}
                ORDER BY l.id_log DESC
                LIMIT " . (int)$limit;

        return $db->fetchAll($sql, $params);
    }

    /**
     * Mengambil riwayat trigger insert pelanggan dari log_pelanggan
     */
    public static function getTriggerInsertPelanggan(): array
    {
        $db = Database::getInstance();
        $sql = "SELECT lp.*, p.nama_pelanggan, p.kota 
                FROM log_pelanggan lp
                LEFT JOIN tbl_pelanggan p ON lp.id_pelanggan = p.id_pelanggan
                ORDER BY lp.id_log DESC
                LIMIT 50";
        return $db->fetchAll($sql);
    }

    /**
     * Mengambil riwayat trigger update perubahan nama pelanggan dari log_perubahan_pelanggan
     */
    public static function getTriggerPerubahanPelanggan(): array
    {
        $db = Database::getInstance();
        $sql = "SELECT lpp.*, p.kota 
                FROM log_perubahan_pelanggan lpp
                LEFT JOIN tbl_pelanggan p ON lpp.id_pelanggan = p.id_pelanggan
                ORDER BY lpp.id_log DESC
                LIMIT 50";
        return $db->fetchAll($sql);
    }

    /**
     * Mengambil ringkasan statistik jenis aktivitas
     */
    public static function getStatistik(): array
    {
        $db = Database::getInstance();
        $sql = "SELECT 
                    COUNT(*) AS total_log,
                    COUNT(CASE WHEN tipe_aktivitas LIKE 'LOGIN%' THEN 1 END) AS total_login,
                    COUNT(CASE WHEN tipe_aktivitas LIKE 'TRANSAKSI%' THEN 1 END) AS total_transaksi,
                    COUNT(CASE WHEN tipe_aktivitas LIKE 'PRODUK%' THEN 1 END) AS total_produk,
                    COUNT(CASE WHEN tipe_aktivitas LIKE 'PELANGGAN%' THEN 1 END) AS total_pelanggan
                FROM tbl_log";
        return $db->fetchOne($sql) ?? [];
    }

    /**
     * Mengambil daftar tipe aktivitas unik yang ada di tbl_log
     */
    public static function getTipeList(): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll("SELECT DISTINCT tipe_aktivitas FROM tbl_log ORDER BY tipe_aktivitas ASC");
        return array_column($rows, 'tipe_aktivitas');
    }
}
