<?php
/**
 * Model PenerimaanPabrik
 * Mengelola pencatatan stok masuk dan scanning produk dari pabrik / vendor / supplier
 * Menggunakan Database TCL (Transaction Control Language) untuk integritas stok
 */

require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/models/Produk.php';

class PenerimaanPabrik
{
    /**
     * Menghasilkan nomor bukti penerimaan pabrik unik (Format: RCV-YYYYMMDD-0001)
     */
    public static function generateNoPenerimaan(): string
    {
        $db = Database::getInstance();
        $todayPrefix = 'RCV-' . date('Ymd') . '-';
        
        $sql = "SELECT no_penerimaan FROM tbl_penerimaan_pabrik 
                WHERE no_penerimaan LIKE :prefix 
                ORDER BY id_penerimaan DESC LIMIT 1";
        
        $last = $db->fetchOne($sql, [':prefix' => $todayPrefix . '%']);
        
        if ($last && !empty($last['no_penerimaan'])) {
            $parts = explode('-', $last['no_penerimaan']);
            $lastNumber = (int)end($parts);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $todayPrefix . str_pad((string)$nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Memproses dan menyimpan transaksi penerimaan barang dari pabrik
     * Dilengkapi Atomic Transaction (TCL: BEGIN, COMMIT, ROLLBACK)
     *
     * @param array $data Header info (nama_pabrik, no_surat_jalan, tanggal_terima, catatan, id_user)
     * @param array $items Array of item ['id_produk', 'jumlah_masuk', 'harga_beli', 'barcode_pabrik', 'catatan']
     * @return array Status hasil transaksi
     */
    public static function createPenerimaan(array $data, array $items): array
    {
        if (empty($items)) {
            return ['success' => false, 'message' => 'Daftar produk yang diterima dari pabrik tidak boleh kosong.'];
        }

        $namaPabrik = trim($data['nama_pabrik'] ?? '');
        if (empty($namaPabrik)) {
            return ['success' => false, 'message' => 'Nama pabrik / supplier pengirim wajib diisi.'];
        }

        $db = Database::getInstance();
        $pdo = $db->getConnection();

        try {
            $pdo->beginTransaction();

            $noPenerimaan = trim($data['no_penerimaan'] ?? '');
            if (empty($noPenerimaan)) {
                $noPenerimaan = self::generateNoPenerimaan();
            }

            // Hitung total unit dan total item
            $totalItem = count($items);
            $totalQty = 0;
            foreach ($items as $it) {
                $qty = (int)($it['jumlah_masuk'] ?? 0);
                if ($qty <= 0) {
                    throw new Exception("Jumlah unit produk untuk '{$it['nama_produk']}' harus lebih dari 0.");
                }
                $totalQty += $qty;
            }

            $idUser = !empty($data['id_user']) ? (int)$data['id_user'] : (Auth::id() ?? null);
            $tglTerima = !empty($data['tanggal_terima']) ? $data['tanggal_terima'] : date('Y-m-d H:i:s');
            $noSuratJalan = !empty($data['no_surat_jalan']) ? trim($data['no_surat_jalan']) : null;
            $catatanHeader = !empty($data['catatan']) ? trim($data['catatan']) : null;

            // 1. Simpan Header Penerimaan
            $stmtHeader = $pdo->prepare("
                INSERT INTO tbl_penerimaan_pabrik 
                (no_penerimaan, nama_pabrik, no_surat_jalan, tanggal_terima, total_item, total_qty, id_user, catatan) 
                VALUES (:no_pen, :nama_pabrik, :no_sj, :tgl, :total_item, :total_qty, :id_user, :catatan)
            ");
            $stmtHeader->execute([
                ':no_pen'      => $noPenerimaan,
                ':nama_pabrik' => $namaPabrik,
                ':no_sj'       => $noSuratJalan,
                ':tgl'         => $tglTerima,
                ':total_item'  => $totalItem,
                ':total_qty'   => $totalQty,
                ':id_user'     => $idUser,
                ':catatan'     => $catatanHeader
            ]);

            $idPenerimaan = (int)$pdo->lastInsertId();

            // Prepared statements untuk detail dan update stok produk
            $stmtDetail = $pdo->prepare("
                INSERT INTO tbl_penerimaan_pabrik_detail 
                (id_penerimaan, id_produk, kode_produk, nama_produk, jumlah_masuk, stok_sebelum, stok_sesudah, harga_beli, catatan) 
                VALUES (:id_pen, :id_prd, :kode_prd, :nama_prd, :jml, :stok_seb, :stok_ses, :harga_beli, :catatan)
            ");

            $stmtLockProd = $pdo->prepare("SELECT id_produk, kode_produk, nama_produk, stok, barcode_pabrik, harga_beli FROM tbl_produk WHERE id_produk = :id FOR UPDATE");
            $stmtUpdateStock = $pdo->prepare("UPDATE tbl_produk SET stok = stok + :tambah_stok WHERE id_produk = :id");
            $stmtUpdateMeta = $pdo->prepare("UPDATE tbl_produk SET barcode_pabrik = COALESCE(:barcode, barcode_pabrik), harga_beli = :hb WHERE id_produk = :id");

            foreach ($items as $item) {
                $idProduk = (int)($item['id_produk'] ?? 0);
                $qtyMasuk = (int)($item['jumlah_masuk'] ?? 0);
                $hargaBeliInput = isset($item['harga_beli']) ? (float)$item['harga_beli'] : 0.00;
                $barcode = !empty($item['barcode_pabrik']) ? trim($item['barcode_pabrik']) : null;
                $itemNote = !empty($item['catatan']) ? trim($item['catatan']) : null;

                // Kunci baris produk
                $stmtLockProd->execute([':id' => $idProduk]);
                $currProd = $stmtLockProd->fetch(PDO::FETCH_ASSOC);

                if (!$currProd) {
                    throw new Exception("Produk dengan ID {$idProduk} tidak ditemukan di database.");
                }

                $stokSebelum = (int)$currProd['stok'];
                $stokSesudah = $stokSebelum + $qtyMasuk;
                $finalHargaBeli = $hargaBeliInput > 0 ? $hargaBeliInput : (float)($currProd['harga_beli'] ?? 0);

                // Simpan baris detail
                $stmtDetail->execute([
                    ':id_pen'     => $idPenerimaan,
                    ':id_prd'     => $idProduk,
                    ':kode_prd'   => $currProd['kode_produk'],
                    ':nama_prd'   => $currProd['nama_produk'],
                    ':jml'        => $qtyMasuk,
                    ':stok_seb'   => $stokSebelum,
                    ':stok_ses'   => $stokSesudah,
                    ':harga_beli' => $finalHargaBeli,
                    ':catatan'    => $itemNote
                ]);

                // Tambah stok fisik di database
                $stmtUpdateStock->execute([
                    ':tambah_stok' => $qtyMasuk,
                    ':id'          => $idProduk
                ]);

                // Update barcode pabrik / harga beli jika ada data baru
                $newBarcode = !empty($barcode) ? $barcode : $currProd['barcode_pabrik'];
                $stmtUpdateMeta->execute([
                    ':barcode' => $newBarcode,
                    ':hb'      => $finalHargaBeli,
                    ':id'      => $idProduk
                ]);
            }

            // Catat log audit sistem
            Logger::log(
                'PENERIMAAN_PABRIK', 
                "Penerimaan {$totalQty} unit ({$totalItem} SKU) dari pabrik '{$namaPabrik}' (No: {$noPenerimaan}" . 
                ($noSuratJalan ? ", SJ: {$noSuratJalan}" : "") . ")"
            );

            $pdo->commit();

            return [
                'success'       => true,
                'id_penerimaan' => $idPenerimaan,
                'no_penerimaan' => $noPenerimaan,
                'total_qty'     => $totalQty,
                'total_item'    => $totalItem,
                'message'       => "Penerimaan barang dari pabrik '{$namaPabrik}' sebanyak {$totalQty} unit berhasil disimpan."
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'message' => 'Gagal memproses penerimaan pabrik: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Mengambil daftar riwayat penerimaan produk dari pabrik
     */
    public static function getAll(?string $keyword = null, ?string $tglMulai = null, ?string $tglSelesai = null, int $limit = 100): array
    {
        $db = Database::getInstance();
        $conditions = [];
        $params = [];

        if (!empty($keyword)) {
            $conditions[] = "(p.no_penerimaan LIKE :kw1 OR p.nama_pabrik LIKE :kw2 OR p.no_surat_jalan LIKE :kw3)";
            $params[':kw1'] = "%{$keyword}%";
            $params[':kw2'] = "%{$keyword}%";
            $params[':kw3'] = "%{$keyword}%";
        }

        if (!empty($tglMulai)) {
            $conditions[] = "DATE(p.tanggal_terima) >= :tgl_mulai";
            $params[':tgl_mulai'] = $tglMulai;
        }

        if (!empty($tglSelesai)) {
            $conditions[] = "DATE(p.tanggal_terima) <= :tgl_selesai";
            $params[':tgl_selesai'] = $tglSelesai;
        }

        $where = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $sql = "SELECT p.*, u.nama_lengkap AS nama_petugas, u.username 
                FROM tbl_penerimaan_pabrik p
                LEFT JOIN tbl_user u ON p.id_user = u.id_user
                {$where}
                ORDER BY p.id_penerimaan DESC
                LIMIT " . (int)$limit;

        return $db->fetchAll($sql, $params);
    }

    /**
     * Mengambil satu data penerimaan beserta data petugas
     */
    public static function getById(int $id): ?array
    {
        $db = Database::getInstance();
        $sql = "SELECT p.*, u.nama_lengkap AS nama_petugas, u.username, u.role
                FROM tbl_penerimaan_pabrik p
                LEFT JOIN tbl_user u ON p.id_user = u.id_user
                WHERE p.id_penerimaan = :id";
        return $db->fetchOne($sql, [':id' => $id]);
    }

    /**
     * Mengambil rincian produk yang diterima untuk suatu nomor penerimaan
     */
    public static function getDetails(int $idPenerimaan): array
    {
        $db = Database::getInstance();
        $sql = "SELECT d.*, p.nama_kategori
                FROM tbl_penerimaan_pabrik_detail d
                LEFT JOIN (
                    SELECT pr.id_produk, k.nama_kategori 
                    FROM tbl_produk pr
                    LEFT JOIN tbl_kategori k ON pr.id_kategori = k.id_kategori
                ) p ON d.id_produk = p.id_produk
                WHERE d.id_penerimaan = :id
                ORDER BY d.id_detail ASC";
        return $db->fetchAll($sql, [':id' => $idPenerimaan]);
    }

    /**
     * Menghitung statistik ringkas penerimaan produk pabrik
     */
    public static function getStats(): array
    {
        $db = Database::getInstance();
        $bulanIni = date('Y-m');
        $hariIni = date('Y-m-d');

        $stats = $db->fetchOne("
            SELECT 
                COUNT(*) AS total_transaksi,
                COALESCE(SUM(total_qty), 0) AS total_unit_semua,
                COALESCE(SUM(CASE WHEN DATE(tanggal_terima) = :hari_ini THEN total_qty ELSE 0 END), 0) AS unit_hari_ini,
                COALESCE(SUM(CASE WHEN DATE_FORMAT(tanggal_terima, '%Y-%m') = :bulan_ini THEN total_qty ELSE 0 END), 0) AS unit_bulan_ini,
                COUNT(DISTINCT nama_pabrik) AS total_pabrik
            FROM tbl_penerimaan_pabrik
        ", [
            ':hari_ini'  => $hariIni,
            ':bulan_ini' => $bulanIni
        ]);

        return $stats ?: [
            'total_transaksi' => 0,
            'total_unit_semua' => 0,
            'unit_hari_ini' => 0,
            'unit_bulan_ini' => 0,
            'total_pabrik' => 0
        ];
    }
}
