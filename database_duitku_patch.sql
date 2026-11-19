-- ====================================================================
-- PATCH DATABASE: INTEGRASI PAYMENT GATEWAY DUITKU
-- ====================================================================

USE db_tokokita;

-- 1. Kolom tipe_pengajuan ('manual' vs 'duitku')
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_topup_request' AND COLUMN_NAME = 'tipe_pengajuan');
SET @sql := IF(@col_exists = 0, "ALTER TABLE tbl_topup_request ADD COLUMN tipe_pengajuan ENUM('manual', 'duitku') NOT NULL DEFAULT 'manual' AFTER status", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Kolom duitku_reference
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_topup_request' AND COLUMN_NAME = 'duitku_reference');
SET @sql := IF(@col_exists = 0, "ALTER TABLE tbl_topup_request ADD COLUMN duitku_reference VARCHAR(100) NULL AFTER tipe_pengajuan", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Kolom channel_code (NQ, DA, BC, BR, dsb)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_topup_request' AND COLUMN_NAME = 'channel_code');
SET @sql := IF(@col_exists = 0, "ALTER TABLE tbl_topup_request ADD COLUMN channel_code VARCHAR(30) NULL AFTER duitku_reference", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Kolom payment_url
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_topup_request' AND COLUMN_NAME = 'payment_url');
SET @sql := IF(@col_exists = 0, "ALTER TABLE tbl_topup_request ADD COLUMN payment_url TEXT NULL AFTER channel_code", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Kolom va_number
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_topup_request' AND COLUMN_NAME = 'va_number');
SET @sql := IF(@col_exists = 0, "ALTER TABLE tbl_topup_request ADD COLUMN va_number VARCHAR(100) NULL AFTER payment_url", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Kolom qr_string
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_topup_request' AND COLUMN_NAME = 'qr_string');
SET @sql := IF(@col_exists = 0, "ALTER TABLE tbl_topup_request ADD COLUMN qr_string TEXT NULL AFTER va_number", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Kolom waktu_bayar
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_topup_request' AND COLUMN_NAME = 'waktu_bayar');
SET @sql := IF(@col_exists = 0, "ALTER TABLE tbl_topup_request ADD COLUMN waktu_bayar TIMESTAMP NULL AFTER qr_string", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indeks duitku_reference
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_topup_request' AND INDEX_NAME = 'idx_duitku_reference');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE tbl_topup_request ADD INDEX idx_duitku_reference (duitku_reference)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
