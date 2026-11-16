-- ====================================================================
-- MASTER PATCH DATABASE db_tokokita (NON-DESTRUKTIF)
-- Pengembangan Sistem Informasi Penjualan ToKo
-- Menjaga 100% data lama dan struktur yang sudah ada
-- ====================================================================

USE db_tokokita;

-- --------------------------------------------------------------------
-- 1. TABEL tbl_kategori (Manajemen Kategori Produk)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_kategori (
    id_kategori INT(11) NOT NULL AUTO_INCREMENT,
    nama_kategori VARCHAR(100) NOT NULL,
    deskripsi TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_kategori),
    KEY idx_nama_kategori (nama_kategori)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------------------
-- 2. TABEL tbl_produk (Manajemen Produk & Stok)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_produk (
    id_produk INT(11) NOT NULL AUTO_INCREMENT,
    kode_produk VARCHAR(30) NOT NULL UNIQUE,
    nama_produk VARCHAR(100) NOT NULL,
    id_kategori INT(11) NULL,
    harga DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    stok INT(11) NOT NULL DEFAULT 0,
    status ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_produk),
    KEY idx_kategori (id_kategori),
    KEY idx_nama_produk (nama_produk),
    CONSTRAINT fk_produk_kategori FOREIGN KEY (id_kategori) 
        REFERENCES tbl_kategori (id_kategori) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------------------
-- 3. SEEDING AWAL: Kategori & Produk Sesuai Riwayat Transaksi Lama
--    Memastikan produk pada transaksi lama (Beras, Minyak, dll) terdaftar
-- --------------------------------------------------------------------
INSERT IGNORE INTO tbl_kategori (id_kategori, nama_kategori, deskripsi) VALUES
(1, 'Sembako', 'Kebutuhan pokok sehari-hari'),
(2, 'Makanan Instan', 'Makanan cepat saji dan mie instan');

INSERT IGNORE INTO tbl_produk (id_produk, kode_produk, nama_produk, id_kategori, harga, stok, status) VALUES
(1, 'PRD-001', 'Beras 5 Kg', 1, 75000.00, 50, 'aktif'),
(2, 'PRD-002', 'Minyak Goreng 1 L', 1, 18000.00, 60, 'aktif'),
(3, 'PRD-003', 'Gula Pasir 1 Kg', 1, 16000.00, 40, 'aktif'),
(4, 'PRD-004', 'Mie Instan', 2, 3500.00, 200, 'aktif'),
(5, 'PRD-005', 'Telur 1 Kg', 1, 28000.00, 30, 'aktif');

-- --------------------------------------------------------------------
-- 4. PENYESUAIAN AMAN PADA tbl_detail_transaksi
--    Menambahkan id_produk & subtotal tanpa merusak data lama
-- --------------------------------------------------------------------
-- Tambah kolom id_produk jika belum ada
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_detail_transaksi' AND COLUMN_NAME = 'id_produk');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tbl_detail_transaksi ADD COLUMN id_produk INT(11) NULL AFTER id_transaksi', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Tambah kolom subtotal jika belum ada
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_detail_transaksi' AND COLUMN_NAME = 'subtotal');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tbl_detail_transaksi ADD COLUMN subtotal DECIMAL(12,2) NULL AFTER harga', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Sinkronkan id_produk dan subtotal untuk data lama yang sudah ada
UPDATE tbl_detail_transaksi dt
JOIN tbl_produk p ON dt.nama_produk = p.nama_produk
SET dt.id_produk = p.id_produk
WHERE dt.id_produk IS NULL;

UPDATE tbl_detail_transaksi
SET subtotal = jumlah * harga
WHERE subtotal IS NULL OR subtotal = 0;

-- Tambah foreign key fk_detail_produk jika belum ada
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_detail_transaksi' AND CONSTRAINT_NAME = 'fk_detail_produk');
SET @sql := IF(@fk_exists = 0, 'ALTER TABLE tbl_detail_transaksi ADD CONSTRAINT fk_detail_produk FOREIGN KEY (id_produk) REFERENCES tbl_produk (id_produk) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 5. PENYESUAIAN AMAN PADA tbl_transaksi
--    Menambahkan subtotal, diskon, nilai_diskon, catatan
-- --------------------------------------------------------------------
-- Kolom subtotal
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'subtotal');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tbl_transaksi ADD COLUMN subtotal DECIMAL(12,2) NULL AFTER id_pelanggan', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Kolom diskon (persen)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'diskon');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tbl_transaksi ADD COLUMN diskon DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER subtotal', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Kolom nilai_diskon (rupiah)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'nilai_diskon');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tbl_transaksi ADD COLUMN nilai_diskon DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER diskon', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Kolom catatan
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'catatan');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tbl_transaksi ADD COLUMN catatan VARCHAR(255) NULL AFTER total_belanja', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Kolom uang_bayar
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'uang_bayar');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tbl_transaksi ADD COLUMN uang_bayar DECIMAL(12,2) NULL DEFAULT 0.00 AFTER total_belanja', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Kolom uang_kembali
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'uang_kembali');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tbl_transaksi ADD COLUMN uang_kembali DECIMAL(12,2) NULL DEFAULT 0.00 AFTER uang_bayar', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Untuk data lama, set subtotal = total_belanja jika subtotal masih NULL
UPDATE tbl_transaksi 
SET subtotal = total_belanja 
WHERE subtotal IS NULL;

-- --------------------------------------------------------------------
-- 6. INDEX OPTIMASI (Untuk mempercepat JOIN dan Laporan)
-- --------------------------------------------------------------------
-- Index tanggal transaksi untuk filter laporan cepat
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_transaksi' AND INDEX_NAME = 'idx_transaksi_tanggal');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE tbl_transaksi ADD INDEX idx_transaksi_tanggal (tanggal)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index nama pelanggan untuk pencarian cepat
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = 'db_tokokita' AND TABLE_NAME = 'tbl_pelanggan' AND INDEX_NAME = 'idx_nama_pelanggan');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE tbl_pelanggan ADD INDEX idx_nama_pelanggan (nama_pelanggan)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 7. TABEL tbl_user (Autentikasi Aman & Hak Akses Role)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_user (
    id_user INT(11) NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nama_lengkap VARCHAR(100) NOT NULL,
    role ENUM('admin', 'kasir') NOT NULL DEFAULT 'admin',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Akun default:
-- admin / admin123 (hash password_hash BCRYPT)
-- kasir / kasir123 (hash password_hash BCRYPT)
INSERT IGNORE INTO tbl_user (id_user, username, password, nama_lengkap, role) VALUES
(1, 'admin', '$2y$10$QGMV7WPwxYwxb0dKevvgIe3Wys9FkJzvboZHlflQPjxVDrYFba5I6', 'Administrator Toko', 'admin'),
(2, 'kasir', '$2y$10$QGMV7WPwxYwxb0dKevvgIe3Wys9FkJzvboZHlflQPjxVDrYFba5I6', 'Kasir Utama', 'kasir');
-- Kredensial default:
-- Admin: username 'admin', password 'admin123'
-- Kasir: username 'kasir', password 'admin123'

-- --------------------------------------------------------------------
-- 8. TABEL tbl_log (Audit Trail Sistem Terpadu)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_log (
    id_log INT(11) NOT NULL AUTO_INCREMENT,
    tipe_aktivitas VARCHAR(50) NOT NULL,
    deskripsi TEXT NOT NULL,
    id_user INT(11) NULL,
    ip_address VARCHAR(45) NULL,
    waktu TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_log),
    KEY idx_log_waktu (waktu),
    KEY idx_log_tipe (tipe_aktivitas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
