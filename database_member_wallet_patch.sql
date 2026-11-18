-- Additive migration for customer accounts and stored member balances.
-- Back up db_tokokita before applying this patch.
USE db_tokokita;

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_pelanggan' AND COLUMN_NAME = 'saldo'
);
SET @migration_sql := IF(
    @column_exists = 0,
    'ALTER TABLE tbl_pelanggan ADD COLUMN saldo DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_user' AND COLUMN_NAME = 'id_pelanggan'
);
SET @migration_sql := IF(
    @column_exists = 0,
    'ALTER TABLE tbl_user ADD COLUMN id_pelanggan INT(11) NULL AFTER id_user',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @index_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_user' AND INDEX_NAME = 'uq_user_pelanggan'
);
SET @migration_sql := IF(
    @index_exists = 0,
    'ALTER TABLE tbl_user ADD UNIQUE KEY uq_user_pelanggan (id_pelanggan)',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

ALTER TABLE tbl_user
    MODIFY role ENUM('admin', 'kasir', 'pelanggan') NOT NULL DEFAULT 'admin';

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'metode_pembayaran'
);
SET @migration_sql := IF(
    @column_exists = 0,
    "ALTER TABLE tbl_transaksi ADD COLUMN metode_pembayaran VARCHAR(30) NOT NULL DEFAULT 'tunai'",
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

CREATE TABLE IF NOT EXISTS tbl_topup_request (
    id_topup BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_pelanggan INT(11) NOT NULL,
    nominal DECIMAL(12,2) NOT NULL,
    referensi_pembayaran VARCHAR(120) NOT NULL,
    catatan VARCHAR(500) NULL,
    status ENUM('menunggu', 'disetujui', 'ditolak') NOT NULL DEFAULT 'menunggu',
    id_verifikator INT(11) NULL,
    dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    diverifikasi_pada TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id_topup),
    UNIQUE KEY uq_topup_payment_ref (referensi_pembayaran),
    KEY idx_topup_status (status, dibuat_pada),
    KEY idx_topup_pelanggan (id_pelanggan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_mutasi_saldo (
    id_mutasi BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_pelanggan INT(11) NOT NULL,
    id_topup BIGINT UNSIGNED NULL,
    id_transaksi INT(11) NULL,
    jenis ENUM('topup', 'pembelian', 'penyesuaian') NOT NULL,
    nominal DECIMAL(12,2) NOT NULL,
    saldo_sebelum DECIMAL(12,2) NOT NULL,
    saldo_sesudah DECIMAL(12,2) NOT NULL,
    id_user INT(11) NULL,
    keterangan VARCHAR(255) NOT NULL,
    dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_mutasi),
    UNIQUE KEY uq_mutasi_topup (id_topup),
    KEY idx_mutasi_pelanggan (id_pelanggan, dibuat_pada),
    KEY idx_mutasi_transaksi (id_transaksi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
