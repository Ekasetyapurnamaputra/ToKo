<?php
/**
 * Model Transaksi
 * Mengelola proses transaksi penjualan dengan integritas data tinggi
 * Menerapkan Transaction Control Language (TCL: BEGIN, COMMIT, ROLLBACK),
 * Validasi stok ketat, kalkulasi server-side, dan pencegahan transaksi ganda.
 */

require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/helpers/security_helper.php';
require_once dirname(__DIR__) . '/models/Pelanggan.php';
require_once dirname(__DIR__) . '/models/Produk.php';

class Transaksi
{
    /**
     * Mengambil daftar seluruh riwayat transaksi
     * Mendukung pencarian nama pelanggan dan penyaringan rentang tanggal
     */
    public static function getAll(?string $keyword = null, ?string $tglMulai = null, ?string $tglSelesai = null): array
    {
        $db = Database::getInstance();
        $conditions = [];
        $params = [];

        if (!empty($keyword)) {
            $conditions[] = "(p.nama_pelanggan LIKE :kw1 OR p.kota LIKE :kw2 OR t.id_transaksi LIKE :kw_id)";
            $params[':kw1'] = "%{$keyword}%";
            $params[':kw2'] = "%{$keyword}%";
            $params[':kw_id'] = "%{$keyword}%";
        }

        if (!empty($tglMulai)) {
            $conditions[] = "t.tanggal >= :tgl_mulai";
            $params[':tgl_mulai'] = $tglMulai;
        }

        if (!empty($tglSelesai)) {
            $conditions[] = "t.tanggal <= :tgl_selesai";
            $params[':tgl_selesai'] = $tglSelesai;
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $sql = "SELECT t.*, p.nama_pelanggan, p.kota,
                       COUNT(dt.id_detail) AS total_item,
                       COALESCE(SUM(dt.jumlah), 0) AS total_qty
                FROM tbl_transaksi t
                LEFT JOIN tbl_pelanggan p ON t.id_pelanggan = p.id_pelanggan
                LEFT JOIN tbl_detail_transaksi dt ON t.id_transaksi = dt.id_transaksi
                {$whereClause}
                GROUP BY t.id_transaksi
                ORDER BY t.id_transaksi DESC";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Mengambil data detail lengkap satu transaksi beserta rincian produk
     */
    public static function getById(int $id): ?array
    {
        $db = Database::getInstance();

        // 1. Ambil data header transaksi
        $sqlHeader = "SELECT t.*, p.nama_pelanggan, p.kota 
                      FROM tbl_transaksi t
                      LEFT JOIN tbl_pelanggan p ON t.id_pelanggan = p.id_pelanggan
                      WHERE t.id_transaksi = :id";
        $header = $db->fetchOne($sqlHeader, [':id' => $id]);

        if (!$header) {
            return null;
        }

        // 2. Ambil data rincian barang dari tbl_detail_transaksi
        $sqlDetail = "SELECT dt.*, p.kode_produk 
                      FROM tbl_detail_transaksi dt
                      LEFT JOIN tbl_produk p ON dt.id_produk = p.id_produk
                      WHERE dt.id_transaksi = :id
                      ORDER BY dt.id_detail ASC";
        $header['items'] = $db->fetchAll($sqlDetail, [':id' => $id]);

        return $header;
    }

    /**
     * Menyimpan transaksi penjualan baru dengan kontrol transaksi TCL penuh
     * BEGIN -> INSERT HEADER -> INSERT DETAIL -> DECREASE STOK -> LOG -> COMMIT
     * Jika terjadi kegagalan sekecil apa pun, lakukan ROLLBACK otomatis!
     * 
     * @param array $data ['id_pelanggan' => int, 'diskon' => float, 'catatan' => string, 'items' => array]
     * @return array ['success' => bool, 'message' => string, 'id_transaksi' => int|null]
     */
    public static function create(array $data): array
    {
        $db = Database::getInstance();

        // Validasi 1: Pelanggan
        $idPelanggan = (int)($data['id_pelanggan'] ?? 0);
        $pelanggan = Pelanggan::getById($idPelanggan);
        if (!$pelanggan) {
            return ['success' => false, 'message' => 'Pelanggan tidak valid atau tidak ditemukan.'];
        }

        // Validasi 2: Keranjang Belanja (Items)
        $rawItems = $data['items'] ?? [];
        if (empty($rawItems) || !is_array($rawItems)) {
            return ['success' => false, 'message' => 'Transaksi gagal: Keranjang belanja kosong. Pilih minimal satu produk.'];
        }

        // Konsolidasi item ganda jika ada produk yang dipilih lebih dari sekali dalam satu transaksi
        $consolidatedItems = [];
        foreach ($rawItems as $item) {
            $idProd = (int)($item['id_produk'] ?? 0);
            $qty = (int)($item['jumlah'] ?? 0);

            if ($idProd <= 0 || $qty <= 0) {
                continue;
            }

            if (isset($consolidatedItems[$idProd])) {
                $consolidatedItems[$idProd] += $qty;
            } else {
                $consolidatedItems[$idProd] = $qty;
            }
        }

        if (empty($consolidatedItems)) {
            return ['success' => false, 'message' => 'Transaksi gagal: Jumlah produk harus lebih dari 0.'];
        }

        // Persentase Diskon (0% - 100%)
        $diskonPersen = (float)($data['diskon'] ?? 0);
        if ($diskonPersen < 0) {
            $diskonPersen = 0;
        } elseif ($diskonPersen > 100) {
            $diskonPersen = 100;
        }

        $tanggal = !empty($data['tanggal']) ? $data['tanggal'] : date('Y-m-d');
        $catatan = clean($data['catatan'] ?? '');

        // ================================================================
        // MEMULAI TRANSAKSI DATABASE (TCL BEGIN)
        // ================================================================
        $db->beginTransaction();

        try {
            $verifiedItems = [];
            $subtotalSemua = 0.00;

            // Periksa ketersediaan produk dan stok di database (Sisi Server Authoritative)
            foreach ($consolidatedItems as $idProd => $qty) {
                // Kunci baris dengan FOR UPDATE agar stok tidak terganggu proses konkuren
                $prod = $db->fetchOne("SELECT id_produk, kode_produk, nama_produk, harga, stok, status 
                                       FROM tbl_produk 
                                       WHERE id_produk = :id FOR UPDATE", [':id' => $idProd]);

                if (!$prod) {
                    throw new \Exception("Produk dengan ID #{$idProd} tidak ditemukan di sistem.");
                }

                if ($prod['status'] !== 'aktif') {
                    throw new \Exception("Produk '{$prod['nama_produk']}' saat ini berstatus NONAKTIF dan tidak dapat diproses.");
                }

                $stokTersedia = (int)$prod['stok'];
                if ($stokTersedia < $qty) {
                    throw new \Exception("Stok tidak mencukupi untuk '{$prod['nama_produk']}'. Tersedia: {$stokTersedia}, Diminta: {$qty}. Transaksi dibatalkan.");
                }

                // Kalkulasi harga murni dari database (tidak mempercayai input browser)
                $hargaSatuan = (float)$prod['harga'];
                
                // Gunakan Function database HitungTotal jika diinginkan, atau kalkulasi presisi
                $subtotalProduk = (float)($db->fetchOne("SELECT HitungTotal(:harga, :qty) as sub", [
                    ':harga' => $hargaSatuan,
                    ':qty'   => $qty
                ])['sub'] ?? ($hargaSatuan * $qty));

                $subtotalSemua += $subtotalProduk;

                $verifiedItems[] = [
                    'id_produk'   => (int)$prod['id_produk'],
                    'nama_produk' => $prod['nama_produk'],
                    'jumlah'      => $qty,
                    'harga'       => $hargaSatuan,
                    'subtotal'    => $subtotalProduk
                ];
            }

            // Hitung Diskon dan Total Akhir
            $nilaiDiskon = round(($subtotalSemua * $diskonPersen) / 100, 2);
            $totalAkhir = round($subtotalSemua - $nilaiDiskon, 2);

            if ($totalAkhir < 0) {
                throw new \Exception("Total belanja tidak boleh bernilai negatif.");
            }

            $memberBalance = $db->fetchOne(
                'SELECT saldo FROM tbl_pelanggan WHERE id_pelanggan = :id FOR UPDATE',
                [':id' => $idPelanggan]
            );
            if (!$memberBalance) {
                throw new \RuntimeException('Data member tidak ditemukan saat pembayaran.');
            }

            $balanceBefore = (float)$memberBalance['saldo'];
            if ($balanceBefore < $totalAkhir) {
                $kurang = number_format($totalAkhir - $balanceBefore, 0, ',', '.');
                throw new \RuntimeException("Saldo member kurang Rp {$kurang}. Silakan lakukan top-up terlebih dahulu.");
            }
            $balanceAfter = round($balanceBefore - $totalAkhir, 2);

            // 1. Simpan Header Transaksi ke tbl_transaksi
            $sqlHeader = "INSERT INTO tbl_transaksi (tanggal, id_pelanggan, subtotal, diskon, nilai_diskon, total_belanja, uang_bayar, uang_kembali, metode_pembayaran, catatan)
                          VALUES (:tanggal, :id_pelanggan, :subtotal, :diskon, :nilai_diskon, :total_belanja, :uang_bayar, 0, 'saldo_member', :catatan)";
            
            $db->execute($sqlHeader, [
                ':tanggal'       => $tanggal,
                ':id_pelanggan'  => $idPelanggan,
                ':subtotal'      => $subtotalSemua,
                ':diskon'        => $diskonPersen,
                ':nilai_diskon'  => $nilaiDiskon,
                ':total_belanja' => $totalAkhir,
                ':uang_bayar'    => $totalAkhir,
                ':catatan'       => !empty($catatan) ? $catatan : null
            ]);

            $idTransaksi = (int)$db->lastInsertId();
            $db->execute(
                'UPDATE tbl_pelanggan SET saldo = :saldo WHERE id_pelanggan = :id',
                [':saldo' => number_format($balanceAfter, 2, '.', ''), ':id' => $idPelanggan]
            );
            $db->execute(
                "INSERT INTO tbl_mutasi_saldo
                 (id_pelanggan, id_transaksi, jenis, nominal, saldo_sebelum, saldo_sesudah, id_user, keterangan)
                 VALUES (:member_id, :transaction_id, 'pembelian', :nominal, :before, :after, :user_id, :description)",
                [
                    ':member_id' => $idPelanggan,
                    ':transaction_id' => $idTransaksi,
                    ':nominal' => number_format($totalAkhir, 2, '.', ''),
                    ':before' => number_format($balanceBefore, 2, '.', ''),
                    ':after' => number_format($balanceAfter, 2, '.', ''),
                    ':user_id' => Auth::id(),
                    ':description' => "Pembayaran transaksi #{$idTransaksi}"
                ]
            );

            // 2. Simpan Setiap Detail Transaksi & Kurangi Stok Produk
            $sqlDetail = "INSERT INTO tbl_detail_transaksi (id_transaksi, id_produk, nama_produk, jumlah, harga, subtotal) 
                          VALUES (:id_transaksi, :id_produk, :nama_produk, :jumlah, :harga, :subtotal)";
            $sqlUpdateStok = "UPDATE tbl_produk SET stok = stok - :jumlah WHERE id_produk = :id_produk";

            foreach ($verifiedItems as $item) {
                // Simpan rincian
                $db->execute($sqlDetail, [
                    ':id_transaksi' => $idTransaksi,
                    ':id_produk'    => $item['id_produk'],
                    ':nama_produk'  => $item['nama_produk'],
                    ':jumlah'       => $item['jumlah'],
                    ':harga'        => $item['harga'],
                    ':subtotal'     => $item['subtotal']
                ]);

                // Kurangi stok di database
                $db->execute($sqlUpdateStok, [
                    ':jumlah'    => $item['jumlah'],
                    ':id_produk' => $item['id_produk']
                ]);
            }

            // 3. Catat Aktivitas ke Log Audit Sistem
            $totalFormatted = number_format($totalAkhir, 0, ',', '.');
            Logger::log(
                'TRANSAKSI_SUKSES', 
                "Transaksi #{$idTransaksi} berhasil diproses untuk pelanggan '{$pelanggan['nama_pelanggan']}'. Total: Rp {$totalFormatted} ({$diskonPersen}% diskon). " . count($verifiedItems) . " varian produk terjual."
            );

            // ================================================================
            // SEMUA PROSES BERHASIL: COMMIT PERUBAHAN KE DATABASE
            // ================================================================
            $db->commit();

            return [
                'success'      => true,
                'message'      => "Transaksi #{$idTransaksi} berhasil disimpan.",
                'id_transaksi' => $idTransaksi
            ];

        } catch (\Throwable $e) {
            // ================================================================
            // TERJADI KEGAGALAN: BATALKAN SELURUH PERUBAHAN (ROLLBACK)
            // ================================================================
            $db->rollBack();

            Logger::log('TRANSAKSI_GAGAL', "Transaksi gagal dan di-ROLLBACK: " . $e->getMessage());

            return [
                'success'      => false,
                'message'      => 'Gagal memproses transaksi: ' . $e->getMessage(),
                'id_transaksi' => null
            ];
        }
    }
}
