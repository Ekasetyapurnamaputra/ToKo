<?php
/**
 * Model Produk
 * Mengelola data produk, harga, stok, relasi kategori, dan proteksi riwayat transaksi
 */

require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';

class Produk
{
    /**
     * Mengambil daftar seluruh produk dengan JOIN ke tbl_kategori
     */
    public static function getAll(?string $keyword = null, ?int $kategoriId = null, ?string $status = null): array
    {
        $db = Database::getInstance();
        $conditions = [];
        $params = [];

        if (!empty($keyword)) {
            $conditions[] = "(p.nama_produk LIKE :kw1 OR p.kode_produk LIKE :kw2)";
            $params[':kw1'] = "%{$keyword}%";
            $params[':kw2'] = "%{$keyword}%";
        }

        if (!empty($kategoriId)) {
            $conditions[] = "p.id_kategori = :kat_id";
            $params[':kat_id'] = $kategoriId;
        }

        if (!empty($status)) {
            $conditions[] = "p.status = :status";
            $params[':status'] = $status;
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $sql = "SELECT p.*, k.nama_kategori 
                FROM tbl_produk p
                LEFT JOIN tbl_kategori k ON p.id_kategori = k.id_kategori
                {$whereClause}
                ORDER BY p.id_produk DESC";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Mengambil data satu produk berdasarkan ID
     */
    public static function getById(int $id): ?array
    {
        $db = Database::getInstance();
        $sql = "SELECT p.*, k.nama_kategori 
                FROM tbl_produk p
                LEFT JOIN tbl_kategori k ON p.id_kategori = k.id_kategori
                WHERE p.id_produk = :id";
        return $db->fetchOne($sql, [':id' => $id]);
    }

    /**
     * Menghasilkan kode produk baru secara otomatis (Contoh: PRD-006)
     */
    public static function generateKode(): string
    {
        $db = Database::getInstance();
        $last = $db->fetchOne("SELECT id_produk FROM tbl_produk ORDER BY id_produk DESC LIMIT 1");
        $nextId = ($last['id_produk'] ?? 0) + 1;
        return 'PRD-' . str_pad((string)$nextId, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Menambahkan produk baru
     */
    public static function create(array $data): array
    {
        $db = Database::getInstance();

        $kode = trim($data['kode_produk'] ?? '');
        if (empty($kode)) {
            $kode = self::generateKode();
        }

        // Cek duplikasi kode produk
        $existing = $db->fetchOne("SELECT id_produk FROM tbl_produk WHERE kode_produk = :kode", [':kode' => $kode]);
        if ($existing) {
            return ['success' => false, 'message' => "Kode produk '{$kode}' sudah digunakan oleh produk lain."];
        }

        $sql = "INSERT INTO tbl_produk (kode_produk, nama_produk, id_kategori, harga, stok, status) 
                VALUES (:kode, :nama, :kategori, :harga, :stok, :status)";

        $db->execute($sql, [
            ':kode'     => $kode,
            ':nama'     => trim($data['nama_produk']),
            ':kategori' => !empty($data['id_kategori']) ? (int)$data['id_kategori'] : null,
            ':harga'    => (float)$data['harga'],
            ':stok'     => (int)$data['stok'],
            ':status'   => in_array($data['status'] ?? '', ['aktif', 'nonaktif']) ? $data['status'] : 'aktif'
        ]);

        $newId = $db->lastInsertId();
        Logger::log('PRODUK_TAMBAH', "Menambahkan produk baru: {$data['nama_produk']} (Kode: {$kode}, Stok: {$data['stok']}, Harga: Rp {$data['harga']})");

        return ['success' => true, 'message' => 'Produk berhasil ditambahkan.', 'id' => $newId];
    }

    /**
     * Memperbarui data produk (Perubahan harga tidak akan merusak catatan detail transaksi lama)
     */
    public static function update(int $id, array $data): array
    {
        $db = Database::getInstance();
        $produk = self::getById($id);
        if (!$produk) {
            return ['success' => false, 'message' => 'Produk tidak ditemukan.'];
        }

        $kode = trim($data['kode_produk'] ?? $produk['kode_produk']);

        // Cek duplikasi kode jika kode diubah
        if ($kode !== $produk['kode_produk']) {
            $existing = $db->fetchOne("SELECT id_produk FROM tbl_produk WHERE kode_produk = :kode AND id_produk != :id", [
                ':kode' => $kode,
                ':id'   => $id
            ]);
            if ($existing) {
                return ['success' => false, 'message' => "Kode produk '{$kode}' sudah digunakan oleh produk lain."];
            }
        }

        $sql = "UPDATE tbl_produk SET 
                    kode_produk = :kode,
                    nama_produk = :nama,
                    id_kategori = :kategori,
                    harga       = :harga,
                    stok        = :stok,
                    status      = :status
                WHERE id_produk = :id";

        $db->execute($sql, [
            ':kode'     => $kode,
            ':nama'     => trim($data['nama_produk']),
            ':kategori' => !empty($data['id_kategori']) ? (int)$data['id_kategori'] : null,
            ':harga'    => (float)$data['harga'],
            ':stok'     => (int)$data['stok'],
            ':status'   => in_array($data['status'] ?? '', ['aktif', 'nonaktif']) ? $data['status'] : 'aktif',
            ':id'       => $id
        ]);

        Logger::log('PRODUK_UBAH', "Memperbarui produk ID #{$id} ({$data['nama_produk']}): Harga baru Rp {$data['harga']}, Stok: {$data['stok']}, Status: {$data['status']}");

        return ['success' => true, 'message' => 'Data produk berhasil diperbarui.'];
    }

    /**
     * Mengatur stok produk secara langsung
     */
    public static function adjustStok(int $id, int $newStok, string $alasan = 'Penyesuaian stok manual'): array
    {
        if ($newStok < 0) {
            return ['success' => false, 'message' => 'Stok produk tidak boleh bernilai negatif.'];
        }

        $db = Database::getInstance();
        $produk = self::getById($id);
        if (!$produk) {
            return ['success' => false, 'message' => 'Produk tidak ditemukan.'];
        }

        $stokLama = (int)$produk['stok'];
        $db->execute("UPDATE tbl_produk SET stok = :stok WHERE id_produk = :id", [
            ':stok' => $newStok,
            ':id'   => $id
        ]);

        Logger::log('PRODUK_STOK', "Penyesuaian stok produk '{$produk['nama_produk']}' (ID: {$id}) dari {$stokLama} menjadi {$newStok}. Alasan: {$alasan}");

        return ['success' => true, 'message' => "Stok produk '{$produk['nama_produk']}' berhasil disesuaikan menjadi {$newStok}."];
    }

    /**
     * Menghapus atau menonaktifkan produk dengan proteksi integritas transaksi lama
     */
    public static function deleteOrDeactivate(int $id): array
    {
        $db = Database::getInstance();
        $produk = self::getById($id);
        if (!$produk) {
            return ['success' => false, 'message' => 'Produk tidak ditemukan.'];
        }

        // Cek apakah produk memiliki riwayat transaksi di tbl_detail_transaksi
        $detailCount = (int)($db->fetchOne("SELECT COUNT(*) as total FROM tbl_detail_transaksi WHERE id_produk = :id", [':id' => $id])['total'] ?? 0);

        if ($detailCount > 0) {
            // Jika ada riwayat transaksi, JANGAN DIHAPUS KERAS agar histori penjualan tetap utuh.
            // Sistem akan menonaktifkannya (Soft Deactivate)
            $db->execute("UPDATE tbl_produk SET status = 'nonaktif' WHERE id_produk = :id", [':id' => $id]);
            Logger::log('PRODUK_NONAKTIF', "Produk '{$produk['nama_produk']}' (ID: {$id}) dinonaktifkan karena telah memiliki {$detailCount} riwayat penjualan.");

            return [
                'success' => true,
                'message' => "Produk '{$produk['nama_produk']}' memiliki {$detailCount} riwayat transaksi penjualan, sehingga statusnya otomatis diubah menjadi 'NONAKTIF' agar tidak dapat dipilih lagi pada transaksi baru, sekaligus menjaga keutuhan riwayat transaksi masa lalu."
            ];
        }

        // Jika belum pernah ditransaksikan sama sekali, aman untuk dihapus
        $db->execute("DELETE FROM tbl_produk WHERE id_produk = :id", [':id' => $id]);
        Logger::log('PRODUK_HAPUS', "Menghapus produk '{$produk['nama_produk']}' (ID: {$id}) karena belum memiliki riwayat transaksi.");

        return ['success' => true, 'message' => "Produk '{$produk['nama_produk']}' berhasil dihapus secara permanen."];
    }
}
