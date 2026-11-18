<?php
/**
 * Customer account provisioning and member-card lookups.
 */

require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';

class MemberAccount
{
    public static function register(string $name, string $city, string $username, string $password): array
    {
        $name = trim($name);
        $city = trim($city);
        $username = trim($username);

        if ($name === '' || $city === '' || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            return ['success' => false, 'message' => 'Lengkapi nama dan kota. Username harus 3–50 karakter (huruf, angka, titik, garis bawah, atau tanda hubung).'];
        }
        if (strlen($password) < 8) {
            return ['success' => false, 'message' => 'Kata sandi harus memiliki minimal 8 karakter.'];
        }

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $existing = $db->fetchOne(
                'SELECT id_user FROM tbl_user WHERE username = :username',
                [':username' => $username]
            );
            if ($existing) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Username sudah digunakan. Silakan pilih username lain.'];
            }

            $memberCode = 'MEM-' . strtoupper(bin2hex(random_bytes(5)));
            $db->execute(
                'INSERT INTO tbl_pelanggan (nama_pelanggan, kota, kode_member) VALUES (:nama, :kota, :kode)',
                [':nama' => $name, ':kota' => $city, ':kode' => $memberCode]
            );
            $memberId = (int)$db->lastInsertId();

            $db->execute(
                "INSERT INTO tbl_user (id_pelanggan, username, password, nama_lengkap, role)
                 VALUES (:id_pelanggan, :username, :password, :nama, 'pelanggan')",
                [
                    ':id_pelanggan' => $memberId,
                    ':username' => $username,
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':nama' => $name
                ]
            );

            Logger::log('AKUN_PELANGGAN_DAFTAR', "Pelanggan {$name} mendaftarkan akun member.", (int)$db->lastInsertId());
            $db->commit();

            return ['success' => true, 'message' => 'Akun member berhasil dibuat.', 'id_pelanggan' => $memberId];
        } catch (Throwable $error) {
            $db->rollBack();
            error_log('[Member account registration] ' . $error->getMessage());
            return ['success' => false, 'message' => 'Pendaftaran tidak dapat diselesaikan. Periksa kembali data Anda atau hubungi admin.'];
        }
    }

    public static function createForMember(int $memberId, string $username, string $password): array
    {
        $username = trim($username);
        if ($memberId <= 0 || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            return ['success' => false, 'message' => 'Data member atau username tidak valid.'];
        }
        if (strlen($password) < 8) {
            return ['success' => false, 'message' => 'Kata sandi harus memiliki minimal 8 karakter.'];
        }

        $db = Database::getInstance();
        $member = $db->fetchOne(
            'SELECT id_pelanggan, nama_pelanggan FROM tbl_pelanggan WHERE id_pelanggan = :id',
            [':id' => $memberId]
        );
        if (!$member) {
            return ['success' => false, 'message' => 'Data member tidak ditemukan.'];
        }

        try {
            $db->execute(
                "INSERT INTO tbl_user (id_pelanggan, username, password, nama_lengkap, role)
                 VALUES (:id_pelanggan, :username, :password, :nama, 'pelanggan')",
                [
                    ':id_pelanggan' => $memberId,
                    ':username' => $username,
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':nama' => $member['nama_pelanggan']
                ]
            );
            Logger::log('AKUN_PELANGGAN_DIBUAT', "Admin membuat akun member untuk {$member['nama_pelanggan']}.", Auth::id());
            return ['success' => true, 'message' => 'Akun pelanggan berhasil dibuat.'];
        } catch (Throwable $error) {
            error_log('[Member account provisioning] ' . $error->getMessage());
            return ['success' => false, 'message' => 'Akun tidak dapat dibuat. Pastikan username belum dipakai dan member belum memiliki akun.'];
        }
    }

    public static function getMemberForUser(int $userId): ?array
    {
        return Database::getInstance()->fetchOne(
            'SELECT p.*, u.username
             FROM tbl_user u
             JOIN tbl_pelanggan p ON p.id_pelanggan = u.id_pelanggan
             WHERE u.id_user = :user_id AND u.role = \'pelanggan\'
             LIMIT 1',
            [':user_id' => $userId]
        );
    }
}
