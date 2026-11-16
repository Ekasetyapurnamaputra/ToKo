<?php
/**
 * Core Authentication & Authorization
 * Mengelola login, verifikasi password BCRYPT, session user, dan pembatasan hak akses role
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Logger.php';

class Auth
{
    /**
     * Memproses login pengguna
     * 
     * @param string $username
     * @param string $password
     * @return array ['success' => bool, 'message' => string]
     */
    public static function login(string $username, string $password): array
    {
        Session::start();
        $db = Database::getInstance();

        $sql = "SELECT id_user, username, password, nama_lengkap, role FROM tbl_user WHERE username = :username LIMIT 1";
        $user = $db->fetchOne($sql, [':username' => $username]);

        if (!$user) {
            Logger::log('LOGIN_GAGAL', "Percobaan login gagal dengan username tidak terdaftar: '{$username}'");
            return ['success' => false, 'message' => 'Username atau password tidak valid.'];
        }

        if (!password_verify($password, $user['password'])) {
            Logger::log('LOGIN_GAGAL', "Percobaan login gagal (password salah) untuk user: '{$username}'", (int)$user['id_user']);
            return ['success' => false, 'message' => 'Username atau password tidak valid.'];
        }

        // Mencegah Session Fixation
        if (!headers_sent()) {
            session_regenerate_id(true);
        }

        // Simpan data user ke session (tanpa password hash)
        unset($user['password']);
        Session::set('user', $user);
        Session::set('logged_in_time', time());

        // Catat ke audit log
        Logger::log('LOGIN', "Pengguna {$user['nama_lengkap']} ({$user['username']}) berhasil login sebagai {$user['role']}", (int)$user['id_user']);

        return ['success' => true, 'message' => 'Login berhasil.'];
    }

    /**
     * Memproses logout pengguna
     */
    public static function logout(): void
    {
        Session::start();
        $user = Session::get('user');

        if ($user) {
            Logger::log('LOGOUT', "Pengguna {$user['nama_lengkap']} ({$user['username']}) telah keluar dari sistem", (int)$user['id_user']);
        }

        Session::destroy();
    }

    /**
     * Memeriksa apakah pengguna sedang dalam status login
     */
    public static function check(): bool
    {
        Session::start();
        return Session::has('user') && !empty(Session::get('user')['id_user']);
    }

    /**
     * Mengambil seluruh data user yang sedang login
     */
    public static function user(): ?array
    {
        Session::start();
        return Session::get('user');
    }

    /**
     * Mengambil ID user yang sedang login
     */
    public static function id(): ?int
    {
        $user = self::user();
        return isset($user['id_user']) ? (int)$user['id_user'] : null;
    }

    /**
     * Mengambil Role user yang sedang login ('admin' atau 'kasir')
     */
    public static function role(): ?string
    {
        $user = self::user();
        return $user['role'] ?? null;
    }

    /**
     * Memeriksa apakah user memiliki role tertentu
     */
    public static function hasRole(string $role): bool
    {
        return self::role() === strtolower($role);
    }

    /**
     * Memeriksa apakah user memiliki salah satu dari daftar role yang diizinkan
     */
    public static function hasAnyRole(array $roles): bool
    {
        $userRole = self::role();
        return in_array($userRole, array_map('strtolower', $roles), true);
    }

    /**
     * Proteksi halaman: Wajib login
     */
    public static function requireLogin(string $redirectUrl = 'index.php?page=login'): void
    {
        if (!self::check()) {
            Session::start();
            Session::setFlash('danger', 'Silakan login terlebih dahulu untuk mengakses sistem.');
            header("Location: {$redirectUrl}");
            exit;
        }
    }

    /**
     * Proteksi hak akses berdasarkan Role
     */
    public static function requireRole($allowedRoles, string $redirectUrl = 'index.php?page=dashboard'): void
    {
        self::requireLogin();

        $roles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
        if (!self::hasAnyRole($roles)) {
            Session::setFlash('danger', 'Akses ditolak: Anda tidak memiliki izin untuk membuka halaman tersebut.');
            header("Location: {$redirectUrl}");
            exit;
        }
    }
}
