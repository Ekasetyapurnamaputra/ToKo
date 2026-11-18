-- ====================================================================
-- PATCH DATABASE: METODE PEMBAYARAN TOP-UP (DANA, GOPAY, BRI, BCA)
-- ====================================================================

USE db_tokokita;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_topup_request' AND COLUMN_NAME = 'metode_pembayaran');
SET @sql := IF(@col_exists = 0, "ALTER TABLE tbl_topup_request ADD COLUMN metode_pembayaran VARCHAR(50) NOT NULL DEFAULT 'dana' AFTER nominal", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indeks metode pembayaran
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_topup_request' AND INDEX_NAME = 'idx_topup_metode');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE tbl_topup_request ADD INDEX idx_topup_metode (metode_pembayaran)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
