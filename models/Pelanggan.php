<?php
/**
 * Model Pelanggan
 * Mengelola data pelanggan dengan integrasi Stored Procedure & Trigger
 */

require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';

class Pelanggan
{
    /**
     * Mengambil seluruh pelanggan (opsional filter pencarian)
     * Menggunakan VIEW view_total_belanja untuk data agregasi transaksi
     */
    public static function getAll(?string $keyword = null): array
    {
        $db = Database::getInstance();
        if (!empty($keyword)) {
            $sql = "SELECT p.id_pelanggan, p.nama_pelanggan, p.kota,
                           COUNT(t.id_transaksi) AS total_transaksi,
                           COALESCE(SUM(t.total_belanja), 0) AS total_belanja
                    FROM tbl_pelanggan p
                    LEFT JOIN tbl_transaksi t ON p.id_pelanggan = t.id_pelanggan
                    WHERE p.nama_pelanggan LIKE :kw1 OR p.kota LIKE :kw2
                    GROUP BY p.id_pelanggan, p.nama_pelanggan, p.kota
                    ORDER BY p.id_pelanggan DESC";
            return $db->fetchAll($sql, [':kw1' => "%{$keyword}%", ':kw2' => "%{$keyword}%"]);
        }

        // Jika tanpa pencarian, manfaatkan VIEW view_total_belanja
        $sql = "SELECT v.*, p.kota 
                FROM view_total_belanja v
                JOIN tbl_pelanggan p ON v.id_pelanggan = p.id_pelanggan
                ORDER BY v.id_pelanggan DESC";
        return $db->fetchAll($sql);
    }

    /**
     * Mengambil data satu pelanggan berdasarkan ID
     * Memanfaatkan Stored Procedure CariPelanggan
     */
    public static function getById(int $id): ?array
    {
        $db = Database::getInstance();
        // Memanggil Stored Procedure CariPelanggan
        return $db->fetchOne("CALL CariPelanggan(:id)", [':id' => $id]);
    }

    /**
     * Menambahkan pelanggan baru
     * Memanfaatkan Stored Procedure TambahPelanggan
     * Trigger `after_insert_pelanggan` pada database akan otomatis mencatat ke `log_pelanggan`
     */
    public static function create(string $nama, string $kota): bool
    {
        $db = Database::getInstance();
        $sql = "CALL TambahPelanggan(:nama, :kota)";
        $db->execute($sql, [
            ':nama' => $nama,
            ':kota' => $kota
        ]);

        Logger::log('PELANGGAN_TAMBAH', "Menambahkan pelanggan baru: '{$nama}' (Kota: {$kota})");
        return true;
    }

    /**
     * Memperbarui data pelanggan
     * Trigger `after_update_pelanggan` akan otomatis mencatat perubahan ke `log_perubahan_pelanggan`
     */
    public static function update(int $id, string $nama, string $kota): bool
    {
        $db = Database::getInstance();
        $sql = "UPDATE tbl_pelanggan SET nama_pelanggan = :nama, kota = :kota WHERE id_pelanggan = :id";
        $db->execute($sql, [
            ':nama' => $nama,
            ':kota' => $kota,
            ':id'   => $id
        ]);

        Logger::log('PELANGGAN_UBAH', "Memperbarui data pelanggan ID #{$id} menjadi '{$nama}' (Kota: {$kota})");
        return true;
    }

    /**
     * Menghapus pelanggan dengan validasi integritas transaksi
     * @return array ['success' => bool, 'message' => string]
     */
    public static function delete(int $id): array
    {
        $db = Database::getInstance();

        // Cek data pelanggan
        $pelanggan = self::getById($id);
        if (!$pelanggan) {
            return ['success' => false, 'message' => 'Pelanggan tidak ditemukan.'];
        }

        // Cek apakah pelanggan memiliki relasi transaksi
        $transaksiCount = (int)($db->fetchOne("SELECT COUNT(*) as total FROM tbl_transaksi WHERE id_pelanggan = :id", [':id' => $id])['total'] ?? 0);
        if ($transaksiCount > 0) {
            return [
                'success' => false,
                'message' => "Pelanggan '{$pelanggan['nama_pelanggan']}' tidak dapat dihapus karena memiliki {$transaksiCount} riwayat transaksi penjualan. Hal ini untuk menjaga integritas data keuangan."
            ];
        }

        $db->execute("DELETE FROM tbl_pelanggan WHERE id_pelanggan = :id", [':id' => $id]);
        Logger::log('PELANGGAN_HAPUS', "Menghapus data pelanggan: '{$pelanggan['nama_pelanggan']}' (ID: {$id})");

        return ['success' => true, 'message' => "Pelanggan '{$pelanggan['nama_pelanggan']}' berhasil dihapus."];
    }

    /**
     * Mengambil riwayat transaksi pelanggan
     * Memanfaatkan Stored Procedure LaporanTransaksi
     */
    public static function getRiwayatTransaksi(int $id): array
    {
        $db = Database::getInstance();
        return $db->fetchAll("CALL LaporanTransaksi(:id)", [':id' => $id]);
    }
}
