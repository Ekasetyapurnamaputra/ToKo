<?php
/**
 * Model Kategori
 * Mengelola data kategori produk dan validasi relasinya
 */

require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';

class Kategori
{
    /**
     * Mengambil seluruh kategori beserta jumlah produk yang terhubung
     */
    public static function getAll(?string $keyword = null): array
    {
        $db = Database::getInstance();
        if (!empty($keyword)) {
            $sql = "SELECT k.*, COUNT(p.id_produk) AS total_produk
                    FROM tbl_kategori k
                    LEFT JOIN tbl_produk p ON k.id_kategori = p.id_kategori
                    WHERE k.nama_kategori LIKE :kw1 OR k.deskripsi LIKE :kw2
                    GROUP BY k.id_kategori
                    ORDER BY k.id_kategori ASC";
            return $db->fetchAll($sql, [':kw1' => "%{$keyword}%", ':kw2' => "%{$keyword}%"]);
        }

        $sql = "SELECT k.*, COUNT(p.id_produk) AS total_produk
                FROM tbl_kategori k
                LEFT JOIN tbl_produk p ON k.id_kategori = p.id_kategori
                GROUP BY k.id_kategori
                ORDER BY k.id_kategori ASC";
        return $db->fetchAll($sql);
    }

    /**
     * Mengambil data satu kategori berdasarkan ID
     */
    public static function getById(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->fetchOne("SELECT * FROM tbl_kategori WHERE id_kategori = :id", [':id' => $id]);
    }

    /**
     * Menambahkan kategori baru
     */
    public static function create(string $nama, ?string $deskripsi = null): bool
    {
        $db = Database::getInstance();
        $sql = "INSERT INTO tbl_kategori (nama_kategori, deskripsi) VALUES (:nama, :deskripsi)";
        $db->execute($sql, [
            ':nama'      => $nama,
            ':deskripsi' => $deskripsi
        ]);

        Logger::log('KATEGORI_TAMBAH', "Menambahkan kategori produk: '{$nama}'");
        return true;
    }

    /**
     * Memperbarui kategori
     */
    public static function update(int $id, string $nama, ?string $deskripsi = null): bool
    {
        $db = Database::getInstance();
        $sql = "UPDATE tbl_kategori SET nama_kategori = :nama, deskripsi = :deskripsi WHERE id_kategori = :id";
        $db->execute($sql, [
            ':nama'      => $nama,
            ':deskripsi' => $deskripsi,
            ':id'        => $id
        ]);

        Logger::log('KATEGORI_UBAH', "Memperbarui kategori ID #{$id} menjadi '{$nama}'");
        return true;
    }

    /**
     * Menghapus kategori dengan pemeriksaan relasi ke produk
     */
    public static function delete(int $id): array
    {
        $db = Database::getInstance();

        $kategori = self::getById($id);
        if (!$kategori) {
            return ['success' => false, 'message' => 'Kategori tidak ditemukan.'];
        }

        // Hitung produk yang masih terhubung
        $totalProduk = (int)($db->fetchOne("SELECT COUNT(*) as total FROM tbl_produk WHERE id_kategori = :id", [':id' => $id])['total'] ?? 0);
        if ($totalProduk > 0) {
            return [
                'success' => false,
                'message' => "Kategori '{$kategori['nama_kategori']}' tidak dapat dihapus karena masih digunakan oleh {$totalProduk} produk. Silakan ubah kategori produk terkait terlebih dahulu."
            ];
        }

        $db->execute("DELETE FROM tbl_kategori WHERE id_kategori = :id", [':id' => $id]);
        Logger::log('KATEGORI_HAPUS', "Menghapus kategori: '{$kategori['nama_kategori']}' (ID: {$id})");

        return ['success' => true, 'message' => "Kategori '{$kategori['nama_kategori']}' berhasil dihapus."];
    }
}
