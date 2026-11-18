<?php
/**
 * Member wallet, top-up verification, and immutable balance history.
 */

require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';

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
}
