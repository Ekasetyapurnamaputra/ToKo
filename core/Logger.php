<?php
/**
 * Core Logger
 * Mencatat aktivitas audit sistem ke tabel `tbl_log`
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Session.php';

class Logger
{
    /**
     * Mencatat aktivitas ke tabel `tbl_log`
     * 
     * @param string $tipe Tipe aktivitas, misalnya: 'LOGIN', 'TRANSAKSI', 'PRODUK_TAMBAH', dsb.
     * @param string $deskripsi Keterangan detail aktivitas
     * @param int|null $userId ID Pengguna (jika null, diambil dari Session jika tersedia)
     * @return bool
     */
    public static function log(string $tipe, string $deskripsi, ?int $userId = null): bool
    {
        try {
            Session::start();
            if ($userId === null) {
                $user = Session::get('user');
                $userId = $user['id_user'] ?? null;
            }

            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            $db = Database::getInstance();
            $sql = "INSERT INTO tbl_log (tipe_aktivitas, deskripsi, id_user, ip_address, waktu) 
                    VALUES (:tipe, :deskripsi, :id_user, :ip, NOW())";

            $db->execute($sql, [
                ':tipe'      => strtoupper($tipe),
                ':deskripsi' => $deskripsi,
                ':id_user'   => $userId,
                ':ip'        => $ipAddress
            ]);

            return true;
        } catch (\Throwable $e) {
            // Jangan biarkan kegagalan logging menghentikan alur aplikasi, catat ke error_log lokal
            error_log("[Logger Error] " . $e->getMessage());
            return false;
        }
    }
}
