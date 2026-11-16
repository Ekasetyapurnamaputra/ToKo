<?php
/**
 * Modul Transaksi Penjualan & Riwayat Kasir
 * Mendukung form kasir interaktif, validasi sisi server, transaksi TCL,
 * pencegahan form ganda (double-submission), dan cetak bukti transaksi/struk.
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';
require_once dirname(dirname(__DIR__)) . '/models/Transaksi.php';
require_once dirname(dirname(__DIR__)) . '/models/Pelanggan.php';
require_once dirname(dirname(__DIR__)) . '/models/Produk.php';
require_once dirname(dirname(__DIR__)) . '/helpers/security_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/format_helper.php';
require_once dirname(dirname(__DIR__)) . '/helpers/barcode_helper.php';

Auth::requireLogin();

$action = $_GET['action'] ?? 'list';
$error = '';

// ====================================================================
// 1. PROSES FORM POST (SIMPAN TRANSAKSI BARU)
// ====================================================================
if (is_post()) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        Session::setFlash('danger', 'Validasi keamanan (CSRF Token) gagal. Silakan coba kembali.');
        header('Location: index.php?page=transaksi&action=create');
        exit;
    }

    // Cegah Pengiriman Ganda (Double-Submission Prevention via Token)
    $submitToken = $_POST['submit_token'] ?? '';
    $sessionSubmitToken = Session::get('__submit_token');

    if (empty($submitToken) || $submitToken !== $sessionSubmitToken) {
        Session::setFlash('warning', 'Peringatan: Transaksi ini sudah pernah diproses atau token kedaluwarsa untuk mencegah transaksi ganda.');
        header('Location: index.php?page=transaksi');
        exit;
    }

    // Segera hapus token agar tidak dapat digunakan lagi
    Session::remove('__submit_token');

    $idPelanggan = (int)($_POST['id_pelanggan'] ?? 0);
    $diskon      = (float)($_POST['diskon'] ?? 0);
    $uangBayar   = (float)($_POST['uang_bayar'] ?? 0);
    $catatan     = clean($_POST['catatan'] ?? '');
    $itemIds     = $_POST['item_id_produk'] ?? [];
    $itemQtys    = $_POST['item_jumlah'] ?? [];

    $items = [];
    if (is_array($itemIds) && is_array($itemQtys)) {
        for ($i = 0; $i < count($itemIds); $i++) {
            $pId = (int)($itemIds[$i] ?? 0);
            $qty = (int)($itemQtys[$i] ?? 0);
            if ($pId > 0 && $qty > 0) {
                $items[] = [
                    'id_produk' => $pId,
                    'jumlah'    => $qty
                ];
            }
        }
    }

    $result = Transaksi::create([
        'id_pelanggan' => $idPelanggan,
        'tanggal'      => date('Y-m-d'),
        'diskon'       => $diskon,
        'uang_bayar'   => $uangBayar,
        'catatan'      => $catatan,
        'items'        => $items
    ]);

    if ($result['success']) {
        Session::setFlash('success', "Transaksi #{$result['id_transaksi']} berhasil disimpan dan stok produk telah diperbarui!");
        header('Location: index.php?page=transaksi&action=struk&id=' . $result['id_transaksi']);
        exit;
    } else {
        Session::setFlash('danger', $result['message']);
        header('Location: index.php?page=transaksi&action=create');
        exit;
    }
}

// ====================================================================
// 2. TAMPILAN BUKTI TRANSAKSI (STRUK PENJUALAN)
// ====================================================================
if ($action === 'struk' || $action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $transaksi = Transaksi::getById($id);

    if (!$transaksi) {
        Session::setFlash('danger', 'Transaksi tidak ditemukan.');
        header('Location: index.php?page=transaksi');
        exit;
    }

    $pageTitle = 'Bukti Transaksi #' . $transaksi['id_transaksi'];
    
    // Payload QR Code: URL Verifikasi Transaksi Digital
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $qrPayload = "{$scheme}://{$host}/ToKo/index.php?page=transaksi&action=struk&id={$transaksi['id_transaksi']}";

    require_once dirname(dirname(__DIR__)) . '/templates/header.php';
    ?>
    <div class="thermal-wrapper">
        
        <!-- Action Buttons (No Print) -->
        <div style="display: flex; gap: 10px; margin-bottom: 24px; justify-content: center; width: 100%; max-width: 340px;" class="no-print">
            <a href="index.php?page=transaksi" class="btn btn-secondary btn-sm">&laquo; Riwayat</a>
            <a href="index.php?page=transaksi&action=create" class="btn btn-secondary btn-sm">+ Transaksi Baru</a>
            <button onclick="window.print()" class="btn btn-primary btn-sm">&#128438; Cetak Struk</button>
        </div>

        <!-- Realistic Thermal Paper Receipt (Identik dengan Foto Struk Kasir) -->
        <div class="thermal-paper" id="struk-area">
            <!-- Header Struk (Logo & Alamat Toko) -->
            <div class="receipt-header">
                <div class="receipt-logo"><?= e(APP_NAME) ?></div>
                <div class="receipt-tagline">Belanja puas harga pas</div>
                <div class="receipt-store-info">
                    <?= e(APP_NAME) ?> Jogja<br>
                    Jl. Kenangan No. 12<br>
                    Jogja<br>
                    08897482739
                </div>
            </div>

            <!-- Meta Transaksi: Tanggal & No. Transaksi -->
            <div class="receipt-meta">
                <span><?= date('d.m.Y H:i', strtotime($transaksi['tanggal'])) ?></span>
                <span>#<?= e($transaksi['id_transaksi']) ?></span>
            </div>

            <hr class="receipt-divider">

            <!-- Rincian Daftar Item (Format Baris Item Sesuai Foto) -->
            <div class="receipt-items">
                <?php foreach ($transaksi['items'] as $item): ?>
                    <div class="receipt-item-block">
                        <div class="receipt-item-name"><?= e($item['nama_produk']) ?></div>
                        <div class="receipt-item-calc">
                            <span><?= e($item['jumlah']) ?>x <?= number_format($item['harga'], 0, ',', '.') ?></span>
                            <span><?= number_format($item['subtotal'], 0, ',', '.') ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr class="receipt-divider">

            <!-- Ringkasan Total & Pembayaran -->
            <?php if ((float)($transaksi['diskon'] ?? 0) > 0): ?>
                <div class="receipt-total-row">
                    <span>Subtotal</span>
                    <span>Rp<?= number_format($transaksi['subtotal'] ?? $transaksi['total_belanja'], 0, ',', '.') ?></span>
                </div>
                <div class="receipt-total-row">
                    <span>Diskon (<?= e($transaksi['diskon']) ?>%)</span>
                    <span>-Rp<?= number_format($transaksi['nilai_diskon'], 0, ',', '.') ?></span>
                </div>
            <?php endif; ?>

            <?php
            $uangBayar = (float)($transaksi['uang_bayar'] ?? 0);
            if ($uangBayar <= 0) {
                $uangBayar = (float)$transaksi['total_belanja'];
            }
            $uangKembali = (float)($transaksi['uang_kembali'] ?? 0);
            ?>

            <div class="receipt-total-row main">
                <span>Total</span>
                <span>Rp<?= number_format($transaksi['total_belanja'], 0, ',', '.') ?></span>
            </div>

            <div class="receipt-total-row">
                <span>Tunai</span>
                <span>Rp<?= number_format($uangBayar, 0, ',', '.') ?></span>
            </div>

            <div class="receipt-total-row">
                <span>Kembali</span>
                <span>Rp<?= number_format($uangKembali, 0, ',', '.') ?></span>
            </div>

            <div style="text-align: right; font-size: 12px; font-weight: 600; margin-top: 2px;">
                Lunas
            </div>

            <hr class="receipt-divider">

            <!-- Catatan Transaksi (Ditampilkan Jelas) -->
            <?php if (!empty($transaksi['catatan'])): ?>
                <div class="receipt-note">
                    <strong>Catatan:</strong> <?= e($transaksi['catatan']) ?>
                </div>
                <hr class="receipt-divider">
            <?php endif; ?>

            <!-- Catatan Pelanggan (Jika ada) -->
            <?php if (!empty($transaksi['nama_pelanggan']) && $transaksi['nama_pelanggan'] !== 'Pelanggan Umum'): ?>
                <div style="font-size: 11px; color: #475569; margin-bottom: 8px;">
                    Pelanggan: <?= e($transaksi['nama_pelanggan']) ?> (<?= e($transaksi['kota'] ?? '-') ?>)
                </div>
                <hr class="receipt-divider">
            <?php endif; ?>

            <!-- Catatan Kaki & Penutup Struk -->
            <div class="receipt-footer-notes">
                <div style="margin-bottom: 8px;">
                    Barang yang sudah dibeli tidak b<br>isa dikembalikan lagi
                </div>
                <div class="receipt-cashier">
                    Kasir : <?= e($currentUser['nama_lengkap'] ?? 'Sinta') ?>
                </div>
                <div class="receipt-thanks" style="margin-bottom: 10px;">
                    THANK YOU!
                </div>
            </div>

            <!-- Barcode Garis-Garis Vertikal (1D Linear Barcode Sesuai Contoh Gambar) -->
            <div class="receipt-barcode-section">
                <div class="barcode-svg-container">
                    <?= generate_barcode_svg('TRX' . str_pad($transaksi['id_transaksi'], 6, '0', STR_PAD_LEFT), 44, 2, 4) ?>
                </div>
            </div>
        </div>

    </div>
    <?php
    require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
    exit;
}

// ====================================================================
// 3. FORM TRANSAKSI KASIR BARU
// ====================================================================
if ($action === 'create') {
    // Generate token khusus pencegah pengiriman ganda (anti double-submission)
    $submitToken = bin2hex(random_bytes(16));
    Session::set('__submit_token', $submitToken);

    $pelangganList = Pelanggan::getAll();
    // Hanya ambil produk aktif dengan stok > 0
    $produkList = Produk::getAll(null, null, 'aktif');
    $produkList = array_values(array_filter($produkList, fn($p) => (int)$p['stok'] > 0));

    $pageTitle = 'Transaksi Penjualan Baru';
    require_once dirname(dirname(__DIR__)) . '/templates/header.php';
    ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0;">Kasir Penjualan Baru</h2>
            <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">Proses transaksi dengan validasi database transaction (TCL BEGIN/COMMIT/ROLLBACK).</p>
        </div>
        <div>
            <a href="index.php?page=transaksi" class="btn btn-secondary">&laquo; Riwayat Transaksi</a>
        </div>
    </div>

    <form method="POST" action="index.php?page=transaksi" id="form-transaksi">
        <?= csrf_field() ?>
        <input type="hidden" name="submit_token" value="<?= e($submitToken) ?>">

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
            
            <!-- Kolom Kiri: Pemilihan Produk & Keranjang -->
            <div class="card">
                <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #dee2e6; padding-bottom: 10px;">
                    Daftar Produk Belanja
                </h3>

                <table class="table" id="table-items">
                    <thead>
                        <tr>
                            <th>Produk *</th>
                            <th style="width: 130px;">Harga Satuan</th>
                            <th style="width: 100px;">Jumlah *</th>
                            <th style="width: 140px; text-align: right;">Subtotal</th>
                            <th style="width: 50px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="cart-rows">
                        <!-- Baris akan ditambahkan via JavaScript -->
                    </tbody>
                </table>

                <div style="margin-top: 15px;">
                    <button type="button" class="btn btn-secondary" id="btn-add-item">+ Tambah Produk Lain</button>
                </div>
            </div>

            <!-- Kolom Kanan: Pelanggan, Diskon & Ringkasan Pembayaran -->
            <div>
                <div class="card">
                    <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #dee2e6; padding-bottom: 10px;">
                        Data Transaksi
                    </h3>

                    <div class="form-group">
                        <label for="id_pelanggan">Pilih Pelanggan *</label>
                        <select name="id_pelanggan" id="id_pelanggan" class="form-control" required>
                            <option value="">-- Pilih Pelanggan --</option>
                            <?php foreach ($pelangganList as $pel): ?>
                                <option value="<?= e($pel['id_pelanggan']) ?>">
                                    <?= e($pel['nama_pelanggan']) ?> (<?= e($pel['kota']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="diskon">Diskon Belanja (%)</label>
                        <input type="number" id="diskon" name="diskon" class="form-control" 
                               min="0" max="100" step="0.5" value="0" placeholder="0">
                        <small style="color: #6c757d; font-size: 11px;">Maksimal 100%.</small>
                    </div>

                    <div class="form-group">
                        <label for="catatan">Catatan Transaksi</label>
                        <textarea id="catatan" name="catatan" class="form-control" rows="2" 
                                  placeholder="Opsional (misal: bayar lunas tunai)"></textarea>
                    </div>

                    <div style="border-top: 2px solid #dee2e6; margin-top: 20px; padding-top: 15px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px;">
                            <span>Subtotal:</span>
                            <span id="display-subtotal" style="font-weight: bold;">Rp 0</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; color: #dc3545;">
                            <span>Potongan Diskon:</span>
                            <span id="display-diskon" style="font-weight: bold;">Rp 0</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 12px; padding-top: 10px; border-top: 1px solid #dee2e6; font-size: 18px;">
                            <strong>Total Akhir:</strong>
                            <strong id="display-total" style="color: #0f5132;">Rp 0</strong>
                        </div>
                    </div>

                    <!-- Pembayaran & Hitung Uang Kembalian -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-md); padding: 14px; margin-top: 16px;">
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label for="uang_bayar" style="font-weight: 700; font-size: 13px; color: var(--text-main);">Uang Tunai Diterima (Rp) *</label>
                            <input type="number" id="uang_bayar" name="uang_bayar" class="form-control" 
                                   placeholder="0" min="0" required oninput="calculateKembalian()">
                        </div>

                        <!-- Tombol Cepat Nominal Uang -->
                        <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="setNominalPas()">Uang Pas</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="setNominal(50000)">50.000</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="setNominal(100000)">100.000</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="setNominal(200000)">200.000</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="setNominal(500000)">500.000</button>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: baseline; border-top: 1px dashed #cbd5e1; padding-top: 10px;">
                            <span style="font-size: 13.5px; font-weight: 700; color: var(--text-main);">Uang Kembalian:</span>
                            <strong id="display-kembali" style="font-size: 20px; font-weight: 800; color: #1d4ed8;">Rp 0</strong>
                        </div>
                        <div id="kembali-warning" style="display: none; color: #dc3545; font-size: 11.5px; margin-top: 4px; font-weight: 600;">
                            &#9888; Uang pembayaran kurang dari total belanja!
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="btn-submit-transaksi" style="width: 100%; margin-top: 20px; padding: 12px; font-size: 15px; font-weight: bold;">
                        Konfirmasi & Proses Transaksi
                    </button>
                </div>
            </div>

        </div>
    </form>

    <!-- Data Master Produk JSON untuk Dinamisme Kasir -->
    <script>
        const MASTER_PRODUK = <?= json_encode($produkList) ?>;
        let currentTotalAkhir = 0;

        function formatRupiah(num) {
            return 'Rp ' + Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function createRow(index) {
            let options = '<option value="">-- Pilih Produk --</option>';
            MASTER_PRODUK.forEach(p => {
                options += `<option value="${p.id_produk}" data-harga="${p.harga}" data-stok="${p.stok}">
                    ${p.nama_produk} (Stok: ${p.stok}) - ${formatRupiah(p.harga)}
                </option>`;
            });

            return `
            <tr class="item-row" id="row-${index}">
                <td>
                    <select name="item_id_produk[]" class="form-control select-produk" required onchange="onProductSelect(this, ${index})">
                        ${options}
                    </select>
                </td>
                <td>
                    <input type="text" class="form-control display-harga" value="Rp 0" disabled>
                    <input type="hidden" class="input-harga" value="0">
                </td>
                <td>
                    <input type="number" name="item_jumlah[]" class="form-control input-qty" min="1" max="1" value="1" required oninput="calculateTotal()">
                </td>
                <td style="text-align: right; vertical-align: middle;">
                    <strong class="display-subtotal-row">Rp 0</strong>
                </td>
                <td style="text-align: center; vertical-align: middle;">
                    <button type="button" class="btn btn-danger" style="padding: 3px 8px; font-size: 11px;" onclick="removeRow(${index})">&times;</button>
                </td>
            </tr>
            `;
        }

        let rowIndex = 0;
        const cartTbody = document.getElementById('cart-rows');

        function addRow() {
            rowIndex++;
            cartTbody.insertAdjacentHTML('beforeend', createRow(rowIndex));
        }

        function removeRow(idx) {
            const row = document.getElementById(`row-${idx}`);
            if (row) {
                row.remove();
                calculateTotal();
            }
        }

        function onProductSelect(selectElem, idx) {
            const selectedOpt = selectElem.options[selectElem.selectedIndex];
            const row = document.getElementById(`row-${idx}`);
            if (!row) return;

            const harga = parseFloat(selectedOpt.getAttribute('data-harga')) || 0;
            const stok = parseInt(selectedOpt.getAttribute('data-stok')) || 0;

            const hargaDisplay = row.querySelector('.display-harga');
            const hargaInput = row.querySelector('.input-harga');
            const qtyInput = row.querySelector('.input-qty');

            hargaDisplay.value = formatRupiah(harga);
            hargaInput.value = harga;
            qtyInput.max = stok;
            if (parseInt(qtyInput.value) > stok) {
                qtyInput.value = stok;
            }

            calculateTotal();
        }

        function calculateTotal() {
            let subtotal = 0;
            const rows = document.querySelectorAll('.item-row');

            rows.forEach(r => {
                const harga = parseFloat(r.querySelector('.input-harga').value) || 0;
                const qty = parseInt(r.querySelector('.input-qty').value) || 0;
                const rowSub = harga * qty;

                r.querySelector('.display-subtotal-row').innerText = formatRupiah(rowSub);
                subtotal += rowSub;
            });

            const diskonPersen = parseFloat(document.getElementById('diskon').value) || 0;
            const diskonRp = (subtotal * diskonPersen) / 100;
            currentTotalAkhir = Math.max(0, subtotal - diskonRp);

            document.getElementById('display-subtotal').innerText = formatRupiah(subtotal);
            document.getElementById('display-diskon').innerText = '- ' + formatRupiah(diskonRp);
            document.getElementById('display-total').innerText = formatRupiah(currentTotalAkhir);

            calculateKembalian();
        }

        function calculateKembalian() {
            const bayarInput = document.getElementById('uang_bayar');
            const bayarVal = bayarInput.value.trim();
            const displayKembali = document.getElementById('display-kembali');
            const warning = document.getElementById('kembali-warning');

            if (bayarVal === '') {
                displayKembali.innerText = 'Rp 0';
                displayKembali.style.color = '#1d4ed8';
                warning.style.display = 'none';
                return;
            }

            const bayar = parseFloat(bayarVal) || 0;
            const kembali = bayar - currentTotalAkhir;

            if (kembali < 0) {
                displayKembali.innerText = '- ' + formatRupiah(Math.abs(kembali));
                displayKembali.style.color = '#dc3545';
                warning.style.display = 'block';
            } else {
                displayKembali.innerText = formatRupiah(kembali);
                displayKembali.style.color = '#1d4ed8';
                warning.style.display = 'none';
            }
        }

        function setNominalPas() {
            document.getElementById('uang_bayar').value = currentTotalAkhir;
            calculateKembalian();
        }

        function setNominal(amount) {
            document.getElementById('uang_bayar').value = amount;
            calculateKembalian();
        }

        document.getElementById('btn-add-item').addEventListener('click', addRow);
        document.getElementById('diskon').addEventListener('input', calculateTotal);

        // Tambah 1 baris awal secara otomatis
        addRow();

        // Validasi dan Pencegahan Double Submit di Sisi Tombol
        document.getElementById('form-transaksi').addEventListener('submit', function(e) {
            const rows = document.querySelectorAll('.item-row');
            if (rows.length === 0) {
                alert('Pilih minimal satu produk untuk transaksi.');
                e.preventDefault();
                return;
            }

            const bayarVal = parseFloat(document.getElementById('uang_bayar').value) || 0;
            if (bayarVal < currentTotalAkhir) {
                alert('Uang pembayaran kurang Rp ' + Math.round(currentTotalAkhir - bayarVal).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '. Transaksi tidak dapat dilanjutkan.');
                e.preventDefault();
                return;
            }

            const btn = document.getElementById('btn-submit-transaksi');
            btn.disabled = true;
            btn.innerText = 'Memproses Transaksi...';
        });
    </script>
    <?php
    require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
    exit;
}

// ====================================================================
// 4. DAFTAR RIWAYAT TRANSAKSI (DEFAULT)
// ====================================================================
$keyword    = clean($_GET['q'] ?? '');
$tglMulai   = clean($_GET['tgl_mulai'] ?? '');
$tglSelesai = clean($_GET['tgl_selesai'] ?? '');

$transaksiList = Transaksi::getAll($keyword, $tglMulai, $tglSelesai);

// Hitung total nilai transaksi yang sedang tampil
$totalOmzet = array_reduce($transaksiList, fn($carry, $item) => $carry + (float)$item['total_belanja'], 0.00);

$pageTitle = 'Riwayat Transaksi Penjualan';
require_once dirname(dirname(__DIR__)) . '/templates/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="margin: 0;">Riwayat Transaksi Penjualan</h2>
        <p style="margin: 3px 0 0 0; color: #6c757d; font-size: 13px;">Daftar seluruh transaksi kasir lengkap dengan detail barang, pelanggan, dan bukti transaksi.</p>
    </div>
    <div>
        <a href="index.php?page=transaksi&action=create" class="btn btn-primary">+ Transaksi Baru (Kasir)</a>
    </div>
</div>

<div class="card">
    <!-- Form Filter & Pencarian -->
    <form method="GET" action="index.php" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px; align-items: flex-end;">
        <input type="hidden" name="page" value="transaksi">

        <div>
            <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px;">Cari Pelanggan / No. Trx</label>
            <input type="text" name="q" class="form-control" style="width: 220px;" 
                   placeholder="Nama pelanggan / ID..." value="<?= e($keyword) ?>">
        </div>

        <div>
            <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px;">Tanggal Mulai</label>
            <input type="date" name="tgl_mulai" class="form-control" value="<?= e($tglMulai) ?>">
        </div>

        <div>
            <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px;">Tanggal Selesai</label>
            <input type="date" name="tgl_selesai" class="form-control" value="<?= e($tglSelesai) ?>">
        </div>

        <div>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if (!empty($keyword) || !empty($tglMulai) || !empty($tglSelesai)): ?>
                <a href="index.php?page=transaksi" class="btn btn-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Ringkasan Filter -->
    <div style="background: #e9ecef; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; font-size: 13px; display: flex; justify-content: space-between;">
        <span>Ditemukan: <strong><?= count($transaksiList) ?></strong> transaksi</span>
        <span>Total Perputaran: <strong style="color: #0f5132;"><?= format_rupiah($totalOmzet) ?></strong></span>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 80px;">No. Trx</th>
                    <th style="width: 120px;">Tanggal</th>
                    <th>Nama Pelanggan</th>
                    <th>Kota</th>
                    <th style="width: 120px; text-align: center;">Item</th>
                    <th style="width: 90px; text-align: center;">Diskon</th>
                    <th style="width: 140px; text-align: right;">Total Belanja</th>
                    <th style="width: 130px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transaksiList)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            Tidak ada data transaksi yang ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transaksiList as $t): ?>
                        <tr>
                            <td><strong>#<?= e($t['id_transaksi']) ?></strong></td>
                            <td><?= format_tanggal($t['tanggal']) ?></td>
                            <td><strong><?= e($t['nama_pelanggan'] ?? 'Umum') ?></strong></td>
                            <td><?= e($t['kota'] ?? '-') ?></td>
                            <td style="text-align: center;">
                                <span class="badge badge-info">
                                    <?= (int)$t['total_qty'] ?> pcs (<?= (int)$t['total_item'] ?> item)
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <?php if ((float)$t['diskon'] > 0): ?>
                                    <span class="badge badge-warning"><?= e($t['diskon']) ?>%</span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 12px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <strong style="color: #047857;"><?= format_rupiah($t['total_belanja']) ?></strong>
                            </td>
                            <td style="text-align: center;">
                                <a href="index.php?page=transaksi&action=struk&id=<?= e($t['id_transaksi']) ?>" 
                                   class="btn btn-primary btn-sm">Struk & Detail</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/templates/footer.php';
