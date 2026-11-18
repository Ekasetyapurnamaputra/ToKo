-- ====================================================================
-- PATCH DATABASE: FITUR SCAN PRODUK DARI PABRIK (INBOUND / RESTOCK)
-- Sistem Informasi Penjualan ToKo
-- Menjaga 100% data lama dan struktur yang sudah ada
-- ====================================================================

USE db_tokokita;

-- 1. Tambah kolom barcode_pabrik dan harga_beli pada tbl_produk jika belum ada
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_produk' AND COLUMN_NAME = 'barcode_pabrik');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tbl_produk ADD COLUMN barcode_pabrik VARCHAR(50) NULL AFTER kode_produk', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_produk' AND COLUMN_NAME = 'harga_beli');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tbl_produk ADD COLUMN harga_beli DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER harga', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index untuk barcode_pabrik jika belum ada
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_produk' AND INDEX_NAME = 'idx_barcode_pabrik');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE tbl_produk ADD INDEX idx_barcode_pabrik (barcode_pabrik)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Update contoh barcode pabrik untuk produk sembako bawaan agar siap dites langsung
UPDATE tbl_produk SET barcode_pabrik = '8999999190111', harga_beli = 68000.00 WHERE kode_produk = 'PRD-001' AND (barcode_pabrik IS NULL OR barcode_pabrik = '');
UPDATE tbl_produk SET barcode_pabrik = '8999999190222', harga_beli = 15000.00 WHERE kode_produk = 'PRD-002' AND (barcode_pabrik IS NULL OR barcode_pabrik = '');
UPDATE tbl_produk SET barcode_pabrik = '8999999190333', harga_beli = 13500.00 WHERE kode_produk = 'PRD-003' AND (barcode_pabrik IS NULL OR barcode_pabrik = '');
UPDATE tbl_produk SET barcode_pabrik = '8998866200225', harga_beli = 2800.00 WHERE kode_produk = 'PRD-004' AND (barcode_pabrik IS NULL OR barcode_pabrik = '');
UPDATE tbl_produk SET barcode_pabrik = '8999999190555', harga_beli = 24000.00 WHERE kode_produk = 'PRD-005' AND (barcode_pabrik IS NULL OR barcode_pabrik = '');

-- 2. Tabel Header Penerimaan Barang dari Pabrik
CREATE TABLE IF NOT EXISTS tbl_penerimaan_pabrik (
    id_penerimaan INT(11) NOT NULL AUTO_INCREMENT,
    no_penerimaan VARCHAR(50) NOT NULL UNIQUE,
    nama_pabrik VARCHAR(120) NOT NULL,
    no_surat_jalan VARCHAR(100) NULL,
    tanggal_terima DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_item INT(11) NOT NULL DEFAULT 0,
    total_qty INT(11) NOT NULL DEFAULT 0,
    id_user INT(11) NULL,
    catatan TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_penerimaan),
    KEY idx_no_penerimaan (no_penerimaan),
    KEY idx_nama_pabrik (nama_pabrik),
    KEY idx_tanggal_terima (tanggal_terima)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Tabel Detail Penerimaan Barang dari Pabrik
CREATE TABLE IF NOT EXISTS tbl_penerimaan_pabrik_detail (
    id_detail INT(11) NOT NULL AUTO_INCREMENT,
    id_penerimaan INT(11) NOT NULL,
    id_produk INT(11) NOT NULL,
    kode_produk VARCHAR(30) NOT NULL,
    nama_produk VARCHAR(100) NOT NULL,
    jumlah_masuk INT(11) NOT NULL,
    stok_sebelum INT(11) NOT NULL,
    stok_sesudah INT(11) NOT NULL,
    harga_beli DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    catatan VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_detail),
    KEY idx_detail_penerimaan (id_penerimaan),
    KEY idx_detail_produk (id_produk),
    CONSTRAINT fk_penerimaan_detail_header FOREIGN KEY (id_penerimaan) 
        REFERENCES tbl_penerimaan_pabrik (id_penerimaan) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_penerimaan_detail_produk FOREIGN KEY (id_produk) 
        REFERENCES tbl_produk (id_produk) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
