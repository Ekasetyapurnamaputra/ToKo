<?php
/**
 * Member wallet, top-up verification, and immutable balance history.
 */

require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';
require_once dirname(__DIR__) . '/core/Duitku.php';

class Wallet
{
    public static function getRequests(?int $memberId = null, ?string $status = null): array
    {
        $conditions = [];
        $params = [];

        if ($memberId !== null) {
            $conditions[] = 'r.id_pelanggan = :member_id';
            $params[':member_id'] = $memberId;
        }
        if ($status !== null) {
            $conditions[] = 'r.status = :status';
            $params[':status'] = $status;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return Database::getInstance()->fetchAll(
            "SELECT r.*, p.nama_pelanggan, p.kode_member, u.username AS verifikator
             FROM tbl_topup_request r
             JOIN tbl_pelanggan p ON p.id_pelanggan = r.id_pelanggan
             LEFT JOIN tbl_user u ON u.id_user = r.id_verifikator
             {$where}
             ORDER BY r.dibuat_pada DESC, r.id_topup DESC",
            $params
        );
    }

    public static function getLedger(int $memberId, int $limit = 20): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM tbl_mutasi_saldo
             WHERE id_pelanggan = :member_id
             ORDER BY id_mutasi DESC
             LIMIT ' . max(1, min(100, $limit)),
            [':member_id' => $memberId]
        );
    }

    public static function requestTopup(int $memberId, float $amount, string $reference, string $note = '', string $method = 'dana'): array
    {
        $reference = trim($reference);
        $note = trim($note);
        $validMethods = ['dana', 'gopay', 'bri', 'bca'];
        $method = in_array(strtolower($method), $validMethods, true) ? strtolower($method) : 'dana';

        if ($memberId <= 0 || $amount < 1000 || $amount > 9999999999.99 || !is_finite($amount)) {
            return ['success' => false, 'message' => 'Nominal top-up minimal Rp 1.000 dan tidak boleh melebihi batas saldo.'];
        }
        if ($reference === '' || strlen($reference) > 120 || strlen($note) > 500) {
            return ['success' => false, 'message' => 'Isi referensi pembayaran yang dapat dicocokkan admin.'];
        }

        try {
            Database::getInstance()->execute(
                "INSERT INTO tbl_topup_request (id_pelanggan, nominal, metode_pembayaran, referensi_pembayaran, catatan)
                 VALUES (:member_id, :nominal, :method, :reference, :note)",
                [
                    ':member_id' => $memberId,
                    ':nominal' => number_format($amount, 2, '.', ''),
                    ':method' => $method,
                    ':reference' => $reference,
                    ':note' => $note !== '' ? $note : null
                ]
            );
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                return ['success' => false, 'message' => 'Referensi pembayaran ini sudah pernah digunakan. Periksa kembali datanya.'];
            }
            throw $error;
        }

        $methodLabel = strtoupper($method);
        Logger::log('SALDO_TOPUP_DIAJUKAN', "Pelanggan #{$memberId} mengajukan top-up " . number_format($amount, 0, ',', '.') . " via {$methodLabel}.");
        return ['success' => true, 'message' => "Pengajuan top-up via {$methodLabel} berhasil dikirim dan menunggu verifikasi admin. Saldo akan otomatis bertambah setelah diverifikasi."];
    }

    public static function approveTopup(int $topupId, int $adminId): array
    {
        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $request = $db->fetchOne(
                'SELECT * FROM tbl_topup_request WHERE id_topup = :id FOR UPDATE',
                [':id' => $topupId]
            );
            if (!$request || $request['status'] !== 'menunggu') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Pengajuan tidak ditemukan atau sudah diproses.'];
            }

            $member = $db->fetchOne(
                'SELECT saldo FROM tbl_pelanggan WHERE id_pelanggan = :id FOR UPDATE',
                [':id' => $request['id_pelanggan']]
            );
            if (!$member) {
                throw new RuntimeException('Data member untuk pengajuan ini tidak ditemukan.');
            }

            $balanceBefore = (float)$member['saldo'];
            $amount = (float)$request['nominal'];
            $balanceAfter = round($balanceBefore + $amount, 2);

            $db->execute(
                'UPDATE tbl_pelanggan SET saldo = :saldo WHERE id_pelanggan = :id',
                [':saldo' => number_format($balanceAfter, 2, '.', ''), ':id' => $request['id_pelanggan']]
            );
            $db->execute(
                "UPDATE tbl_topup_request
                 SET status = 'disetujui', id_verifikator = :admin, diverifikasi_pada = NOW()
                 WHERE id_topup = :id",
                [':admin' => $adminId, ':id' => $topupId]
            );
            $db->execute(
                "INSERT INTO tbl_mutasi_saldo
                 (id_pelanggan, id_topup, jenis, nominal, saldo_sebelum, saldo_sesudah, id_user, keterangan)
                 VALUES (:member_id, :topup_id, 'topup', :nominal, :before, :after, :admin, :description)",
                [
                    ':member_id' => $request['id_pelanggan'],
                    ':topup_id' => $topupId,
                    ':nominal' => number_format($amount, 2, '.', ''),
                    ':before' => number_format($balanceBefore, 2, '.', ''),
                    ':after' => number_format($balanceAfter, 2, '.', ''),
                    ':admin' => $adminId,
                    ':description' => 'Top-up terverifikasi, referensi ' . $request['referensi_pembayaran']
                ]
            );
            Logger::log('SALDO_TOPUP_DIVERIFIKASI', "Top-up #{$topupId} diverifikasi dan saldo member #{$request['id_pelanggan']} dikreditkan.", $adminId);
            $db->commit();

            return ['success' => true, 'message' => 'Pembayaran diverifikasi dan saldo member telah ditambahkan.'];
        } catch (Throwable $error) {
            $db->rollBack();
            error_log('[Wallet top-up approval] ' . $error->getMessage());
            return ['success' => false, 'message' => 'Verifikasi top-up gagal. Periksa log server sebelum mencoba kembali.'];
        }
    }

    public static function rejectTopup(int $topupId, int $adminId): array
    {
        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $request = $db->fetchOne(
                'SELECT id_topup, status FROM tbl_topup_request WHERE id_topup = :id FOR UPDATE',
                [':id' => $topupId]
            );
            if (!$request || $request['status'] !== 'menunggu') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Pengajuan tidak ditemukan atau sudah diproses.'];
            }

            $db->execute(
                "UPDATE tbl_topup_request
                 SET status = 'ditolak', id_verifikator = :admin, diverifikasi_pada = NOW()
                 WHERE id_topup = :id",
                [':admin' => $adminId, ':id' => $topupId]
            );
            Logger::log('SALDO_TOPUP_DITOLAK', "Pengajuan top-up #{$topupId} ditolak oleh admin.", $adminId);
            $db->commit();

            return ['success' => true, 'message' => 'Pengajuan top-up ditolak; saldo tidak berubah.'];
        } catch (Throwable $error) {
            $db->rollBack();
            error_log('[Wallet top-up rejection] ' . $error->getMessage());
            return ['success' => false, 'message' => 'Pengajuan tidak dapat ditolak. Periksa log server.'];
        }
    }

    /**
     * Membuat pengajuan top-up otomatis via Payment Gateway DUITKU
     */
    public static function requestDuitkuTopup(int $memberId, float $amount, string $channelCode, string $customerName, string $email = '', string $phone = ''): array
    {
        if ($memberId <= 0 || $amount < 1000 || $amount > 9999999999.99 || !is_finite($amount)) {
            return ['success' => false, 'message' => 'Nominal top-up minimal Rp 1.000.'];
        }

        $orderId = 'TOPUP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $channels = Duitku::getChannels();
        $channelName = $channels[$channelCode]['name'] ?? $channelCode;

        $invoiceRes = Duitku::createInvoice([
            'merchantOrderId' => $orderId,
            'paymentAmount'   => (int)$amount,
            'paymentMethod'   => $channelCode,
            'productDetails'  => "Top-up Saldo Member ToKo ({$channelName})",
            'customerVaName'  => $customerName,
            'email'           => !empty($email) ? $email : 'member@tokokita.local',
            'phoneNumber'     => !empty($phone) ? $phone : '081234567890',
            'additionalParam' => (string)$memberId
        ]);

        if (!$invoiceRes['success']) {
            return ['success' => false, 'message' => 'Gagal membuat transaksi Duitku: ' . ($invoiceRes['message'] ?? '')];
        }

        $db = Database::getInstance();
        $db->execute(
            "INSERT INTO tbl_topup_request 
             (id_pelanggan, nominal, metode_pembayaran, referensi_pembayaran, catatan, status, tipe_pengajuan, duitku_reference, channel_code, payment_url, va_number, qr_string)
             VALUES 
             (:member_id, :nominal, :method, :ref, :catatan, 'menunggu', 'duitku', :duitku_ref, :channel_code, :payment_url, :va_number, :qr_string)",
            [
                ':member_id'     => $memberId,
                ':nominal'       => number_format($amount, 2, '.', ''),
                ':method'        => strtolower($channelCode),
                ':ref'           => $orderId,
                ':catatan'       => "Pembayaran online via Duitku ({$channelName})",
                ':duitku_ref'    => $invoiceRes['reference'],
                ':channel_code'  => $channelCode,
                ':payment_url'   => $invoiceRes['payment_url'],
                ':va_number'     => !empty($invoiceRes['va_number']) ? $invoiceRes['va_number'] : null,
                ':qr_string'     => !empty($invoiceRes['qr_string']) ? $invoiceRes['qr_string'] : null
            ]
        );

        $idTopup = (int)$db->lastInsertId();

        Logger::log('DUITKU_TOPUP_INITIATED', "Pengajuan top-up Duitku #{$idTopup} ({$orderId}) sebesar Rp " . number_format($amount, 0, ',', '.') . " dibuat via {$channelName}.");

        return [
            'success'          => true,
            'id_topup'         => $idTopup,
            'order_id'         => $orderId,
            'duitku_reference' => $invoiceRes['reference'],
            'payment_url'      => $invoiceRes['payment_url'],
            'va_number'        => $invoiceRes['va_number'],
            'qr_string'        => $invoiceRes['qr_string'],
            'channel_name'     => $channelName,
            'amount'           => (int)$amount,
            'message'          => "Transaksi pembayaran online Duitku berhasil dibuat."
        ];
    }

    /**
     * Memproses Webhook Callback Otomatis dari Server Duitku
     * Memverifikasi Signature dan Mengkreditkan Saldo Member secara Real-Time
     */
    public static function processDuitkuCallback(array $callbackData): array
    {
        $verify = Duitku::verifyCallback($callbackData);
        if (!$verify['valid']) {
            return ['success' => false, 'code' => 400, 'message' => $verify['message']];
        }

        $orderId = $verify['merchant_order_id'];
        $db = Database::getInstance();
        $request = $db->fetchOne(
            'SELECT * FROM tbl_topup_request WHERE referensi_pembayaran = :ref LIMIT 1',
            [':ref' => $orderId]
        );

        if (!$request) {
            return ['success' => false, 'code' => 404, 'message' => "Order #{$orderId} tidak ditemukan di sistem."];
        }

        // Idempotensi: jika sudah disetujui, return sukses tanpa proses ganda
        if ($request['status'] === 'disetujui') {
            return ['success' => true, 'code' => 200, 'message' => 'Transaksi sudah pernah disetujui sebelumnya.'];
        }

        if ($verify['is_paid']) {
            $systemAdmin = $db->fetchOne("SELECT id_user FROM tbl_user WHERE role = 'admin' ORDER BY id_user ASC LIMIT 1");
            $adminId = (int)($systemAdmin['id_user'] ?? 1);

            $approve = self::approveTopup((int)$request['id_topup'], $adminId);
            if ($approve['success']) {
                $db->execute(
                    "UPDATE tbl_topup_request 
                     SET waktu_bayar = NOW(), 
                         catatan = CONCAT(COALESCE(catatan, ''), ' [Duitku Lunas: Ref ', :ref, ']') 
                     WHERE id_topup = :id",
                    [':ref' => $verify['reference'], ':id' => $request['id_topup']]
                );
                Logger::log('DUITKU_CALLBACK_PROCESSED', "Callback Duitku sukses: Saldo Rp " . number_format($request['nominal'], 0, ',', '.') . " telah dikreditkan ke Member #{$request['id_pelanggan']} (Order: {$orderId})");
                return ['success' => true, 'code' => 200, 'message' => 'Pembayaran berhasil dikonfirmasi dan saldo telah ditambahkan.'];
            }
            return ['success' => false, 'code' => 500, 'message' => $approve['message']];
        } else {
            // Status pembayaran ditolak atau kedaluwarsa
            $db->execute("UPDATE tbl_topup_request SET status = 'ditolak' WHERE id_topup = :id", [':id' => $request['id_topup']]);
            Logger::log('DUITKU_PAYMENT_FAILED', "Pembayaran Duitku tidak diselesaikan untuk Order #{$orderId}.");
            return ['success' => true, 'code' => 200, 'message' => 'Status pembayaran diperbarui (tidak lunas).'];
        }
    }
}
