-- Additive migration for Duitku checkout in cashier transactions.
USE db_tokokita;

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'metode_pembayaran'
);
SET @migration_sql := IF(
    @column_exists = 0,
    "ALTER TABLE tbl_transaksi ADD COLUMN metode_pembayaran VARCHAR(30) NOT NULL DEFAULT 'saldo_member'",
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'status_pembayaran'
);
SET @migration_sql := IF(
    @column_exists = 0,
    "ALTER TABLE tbl_transaksi ADD COLUMN status_pembayaran VARCHAR(20) NOT NULL DEFAULT 'lunas' AFTER metode_pembayaran",
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_reference'
);
SET @migration_sql := IF(
    @column_exists = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN duitku_reference VARCHAR(100) NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_payment_url'
);
SET @migration_sql := IF(
    @column_exists = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN duitku_payment_url VARCHAR(500) NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_payment_method'
);
SET @migration_sql := IF(
    @column_exists = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN duitku_payment_method VARCHAR(20) NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_va_number'
);
SET @migration_sql := IF(
    @column_exists = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN duitku_va_number VARCHAR(100) NULL AFTER duitku_payment_method',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_qr_string'
);
SET @migration_sql := IF(
    @column_exists = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN duitku_qr_string TEXT NULL AFTER duitku_va_number',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_created_at'
);
SET @migration_sql := IF(
    @column_exists = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN duitku_created_at DATETIME NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @index_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND INDEX_NAME = 'idx_transaksi_status_pembayaran'
);
SET @migration_sql := IF(
    @index_exists = 0,
    'ALTER TABLE tbl_transaksi ADD INDEX idx_transaksi_status_pembayaran (status_pembayaran)',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

CREATE OR REPLACE VIEW view_laporan_bulanan AS
SELECT DATE_FORMAT(t.tanggal, '%Y-%m') AS periode_bulan,
       COUNT(DISTINCT t.id_transaksi) AS total_transaksi,
       COUNT(DISTINCT t.id_pelanggan) AS total_pelanggan_unik,
       COALESCE(SUM(t.total_belanja), 0) AS total_pendapatan,
       ROUND(AVG(t.total_belanja), 2) AS rata_rata_transaksi
FROM tbl_transaksi t
WHERE t.status_pembayaran = 'lunas'
GROUP BY DATE_FORMAT(t.tanggal, '%Y-%m');

CREATE OR REPLACE VIEW view_laporan_harian AS
SELECT t.tanggal,
       COUNT(t.id_transaksi) AS total_transaksi,
       COALESCE(SUM(dt_agg.total_item), 0) AS total_item_terjual,
       COALESCE(SUM(dt_agg.total_qty), 0) AS total_qty_terjual,
       COALESCE(SUM(t.subtotal), 0) AS subtotal_omzet,
       COALESCE(SUM(t.nilai_diskon), 0) AS total_diskon,
       COALESCE(SUM(t.total_belanja), 0) AS total_pendapatan
FROM tbl_transaksi t
LEFT JOIN (
    SELECT id_transaksi, COUNT(id_detail) AS total_item, SUM(jumlah) AS total_qty
    FROM tbl_detail_transaksi
    GROUP BY id_transaksi
) dt_agg ON dt_agg.id_transaksi = t.id_transaksi
WHERE t.status_pembayaran = 'lunas'
GROUP BY t.tanggal;

CREATE OR REPLACE VIEW view_produk_terlaris AS
SELECT p.id_produk, p.kode_produk, p.nama_produk, k.nama_kategori,
       COALESCE(SUM(CASE WHEN t.id_transaksi IS NULL THEN 0 ELSE dt.jumlah END), 0) AS total_terjual,
       COALESCE(SUM(CASE WHEN t.id_transaksi IS NULL THEN 0 ELSE dt.subtotal END), 0) AS total_omzet,
       p.stok AS sisa_stok
FROM tbl_produk p
LEFT JOIN tbl_kategori k ON k.id_kategori = p.id_kategori
LEFT JOIN tbl_detail_transaksi dt ON dt.id_produk = p.id_produk
LEFT JOIN tbl_transaksi t ON t.id_transaksi = dt.id_transaksi AND t.status_pembayaran = 'lunas'
GROUP BY p.id_produk, p.kode_produk, p.nama_produk, k.nama_kategori, p.stok;

CREATE OR REPLACE VIEW view_total_belanja AS
SELECT p.id_pelanggan, p.nama_pelanggan,
       COUNT(t.id_transaksi) AS total_transaksi,
       COALESCE(SUM(t.total_belanja), 0) AS total_belanja
FROM tbl_pelanggan p
LEFT JOIN tbl_transaksi t ON t.id_pelanggan = p.id_pelanggan AND t.status_pembayaran = 'lunas'
GROUP BY p.id_pelanggan, p.nama_pelanggan;

CREATE OR REPLACE VIEW view_transaksi_pelanggan AS
SELECT t.id_transaksi, t.tanggal, p.nama_pelanggan, p.kota, t.total_belanja
FROM tbl_transaksi t
JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
WHERE t.status_pembayaran = 'lunas';
