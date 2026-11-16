# 🏪 ToKoKita — Sistem Informasi Penjualan & Kasir POS Berbasis Web

Aplikasi Sistem Informasi Penjualan Toko dan Kasir Point of Sale (POS) berbasis Web yang dikembangkan menggunakan **PHP Native**, **PDO**, **MariaDB / MySQL**, serta HTML5, CSS3, dan Vanilla JavaScript tanpa dependensi framework luar.

---

## ✨ Fitur Utama

1. **Dashboard Operasional Modern Full Screen**:
   - Ringkasan omzet hari ini, bulan ini, total omzet, dan rata-rata per transaksi secara real-time.
   - Grafik batang (Bar Chart) 6 produk paling laris.
   - 6 Circular Ring Gauges metrik operasional toko (stok aktif, kategori, pelanggan, transaksi).
   - Daftar pelanggan teratas (VIP & Reguler) beserta tombol aksi langsung kasir.
   - Timeline aktivitas kasir dan log audit sistem terbaru.

2. **Kasir Point of Sale (POS) & Transaksi**:
   - Pemilihan pelanggan dinamis dengan opsi diskon belanja (%).
   - Keranjang belanja dinamis berbasis JavaScript.
   - Validasi stok server-side & client-side secara real-time.
   - Input uang tunai (`uang_bayar`) dengan tombol pintas nominal (Uang Pas, 50k, 100k, 200k, 500k).
   - Perhitungan uang kembalian (`uang_kembali`) otomatis dan peringatan kekurangan uang.
   - Perlindungan transaksi ganda (*Anti Double-Submission*) dan Database Transaction Control Language (**TCL: BEGIN, COMMIT, ROLLBACK**).

3. **Struk Thermal Kasir Elegan**:
   - Format struk belanja retail 58mm/80mm (gaya Alfamart).
   - Menampilkan tanggal, nomor transaksi, rincian barang, total, tunai, uang kembali, dan status lunas.
   - Menampilkan catatan transaksi.
   - Generator **Barcode 1D Vertikal** (Code 39 SVG native) dan QR Code.
   - Efek potongan kertas kasir dan fungsi cetak langsung (`window.print()`).

4. **Master Data & Manajemen**:
   - **Manajemen Produk**: CRUD produk, kode unik produk, kategori, harga, stok, serta penyesuaian stok manual (stock opname).
   - **Manajemen Kategori**: Pengelompokan jenis barang toko.
   - **Manajemen Pelanggan**: Data pelanggan beserta riwayat total belanja dan status loyalitas.

5. **Laporan & Analitik**:
   - Laporan Penjualan Harian, Bulanan, dan Rentang Tanggal (Filter).
   - Laporan Produk Terlaris (*Best Sellers*).
   - Laporan Loyalitas Pelanggan.
   - Ekspor & Cetak Laporan.

6. **Keamanan & Audit Trail**:
   - Password terenkripsi dengan algoritma BCRYPT (`password_hash`).
   - Prepared Statement PDO untuk proteksi penuh terhadap SQL Injection.
   - Proteksi XSS (`htmlspecialchars`) pada seluruh output form.
   - Proteksi CSRF Token pada setiap form mutasi data (POST).
   - Audit Log aktivitas pengguna dan tabel log trigger database.

---

## 📁 Struktur Direktori

```text
ToKo/
├── assets/                  # File statis (CSS, JS, Fonts, Icons)
│   ├── css/style.css        # Desain antarmuka Modern SaaS Full-Screen
│   └── js/                  # Helper script (JsBarcode, QRCode, POS logic)
├── config/                  # Konfigurasi aplikasi & database
│   ├── app.php              # Konfigurasi umum aplikasi & session
│   ├── database.php         # Konfigurasi koneksi PDO MariaDB/MySQL
│   └── database.example.php # Template konfigurasi database
├── core/                    # Engine inti sistem
│   ├── Auth.php             # Autentikasi & kontrol hak akses (RBAC)
│   ├── Database.php         # Singleton PDO wrapper & query runner
│   ├── Logger.php           # Pencatatan audit log ke database
│   └── Session.php          # Manajemen session & flash messages
├── database/                # Database dump lengkap
│   └── db_tokokita_full.sql # File SQL lengkap (struktur, data, view, trigger)
├── helpers/                 # Fungsi bantuan (Security, Format, Barcode)
│   ├── barcode_helper.php   # Generator Barcode 1D SVG offline
│   ├── format_helper.php    # Format mata uang Rupiah & tanggal Indonesia
│   └── security_helper.php  # Anti-XSS, CSRF token, sanitasi input
├── models/                  # Lapisan logika data (Data Access Object)
│   ├── Kategori.php
│   ├── Laporan.php
│   ├── Log.php
│   ├── Pelanggan.php
│   ├── Produk.php
│   └── Transaksi.php
├── modules/                 # Modul tampilan & controller halaman
│   ├── auth/                # Login & Logout
│   ├── dashboard/           # Halaman utama ringkasan toko
│   ├── kategori/            # Modul CRUD Kategori
│   ├── laporan/             # Modul Laporan & Analisis
│   ├── log/                 # Modul Audit Log (Khusus Admin)
│   ├── pelanggan/           # Modul Master Pelanggan
│   ├── produk/              # Modul Master Produk & Stok
│   └── transaksi/           # Modul Kasir POS & Cetak Struk
├── templates/               # Layout template (Header, Sidebar, Footer)
│   ├── header.php
│   └── footer.php
├── tests/                   # Skrip pengujian otomatis (Unit/System Test)
│   └── system_test.php      # 17 Test keamanan, TCL, & integritas data
├── .gitignore               # Aturan file yang diabaikan Git
├── index.php                # Front controller / Routing utama
└── README.md                # Dokumentasi proyek
```

---

## 🚀 Panduan Instalasi & Menjalankan

### 1. Prasyarat Sistem
- Web Server: **Apache** (XAMPP / Laragon / WampServer)
- Bahasa Pemrograman: **PHP 8.0** atau yang lebih baru (dengan ekstensi `pdo_mysql` aktif)
- Database: **MariaDB** atau **MySQL 5.7+**
- Git (opsional, untuk clone repository)

### 2. Penempatan Folder
Letakkan folder proyek ini di dalam folder web root:
- XAMPP: `C:/xampp/htdocs/ToKo`
- Laragon: `C:/laragon/www/ToKo`

### 3. Import Database
File database lengkap siap pakai tersimpan di folder `database/db_tokokita_full.sql`.

**Cara melalui phpMyAdmin**:
1. Buka browser dan akses `http://localhost/phpmyadmin`.
2. Klik tab **Import**.
3. Pilih file: `database/db_tokokita_full.sql`.
4. Klik tombol **Import** / **Kirim** di bagian bawah.
*(Database `db_tokokita` berserta tabel, data sampel, view, dan trigger akan dibuat secara otomatis).*

**Atau melalui Command Line (Terminal)**:
```bash
mysql -u root -p < database/db_tokokita_full.sql
```

### 4. Konfigurasi Koneksi Database
Buka file `config/database.php` dan sesuaikan kredensial server database lokal Anda:
```php
return [
    'driver'    => 'mysql',
    'host'      => '127.0.0.1',
    'port'      => 3306,
    'dbname'    => 'db_tokokita',
    'username'  => 'root',
    'password'  => '', // Kosongkan jika default XAMPP
    'charset'   => 'utf8mb4',
    // ...
];
```

### 5. Akses Aplikasi
Buka web browser dan akses URL:
```text
http://localhost/ToKo
```

---

## 🔐 Akun Login Default

| Role | Username | Password | Hak Akses |
|---|---|---|---|
| **Administrator** | `admin` | `admin123` | Akses penuh: Dashboard, Produk, Kategori, Pelanggan, Kasir, Laporan, dan Audit Log |
| **Kasir** | `kasir` | `admin123` | Akses operasional: Dashboard, Kasir Transaksi, Produk, dan Pelanggan |

---

## 🧪 Pengujian Sistem (Automated Test)

Aplikasi dilengkapi skrip pengujian sistem otomatis (17 pengujian keamanan, integritas relasi, proteksi TCL rollback, optimasi query `EXPLAIN`, dan pencegahan defisit stok).

Untuk menjalankan pengujian melalui terminal:
```bash
php tests/system_test.php
```

---

## 📄 Lisensi

Proyek ini dibuat untuk keperluan manajemen toko retail, kasir point-of-sale, dan pembelajaran pengembangan web PHP Native berstandar modern & aman.
