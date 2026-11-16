<?php
/**
 * Core Session Manager
 * Mengelola Session secara aman (HttpOnly, SameSite, Session Fixation Protection)
 * Serta menyediakan mekanisme Flash Messages
 */

class Session
{
    /**
     * Memulai session dengan konfigurasi keamanan
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                $cookieParams = [
                    'lifetime' => 0, // Bertahan sampai browser ditutup
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => true, // Mencegah akses cookie via JavaScript (Anti XSS Cookie Theft)
                    'samesite' => 'Lax' // Mitigasi serangan CSRF antar-origin
                ];
                session_set_cookie_params($cookieParams);
                session_start();
            } else {
                @session_start();
            }

            // Mencegah session fixation dengan regenerate berkala
            if (!isset($_SESSION['__last_regenerate'])) {
                $_SESSION['__last_regenerate'] = time();
            } elseif (time() - $_SESSION['__last_regenerate'] > 1800) { // Regenerate tiap 30 menit
                if (!headers_sent()) {
                    session_regenerate_id(true);
                }
                $_SESSION['__last_regenerate'] = time();
            }
        }
    }

    /**
     * Menyimpan nilai ke session
     */
    public static function set(string $key, $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Mengambil nilai dari session
     */
    public static function get(string $key, $default = null)
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Memeriksa keberadaan key di session
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    /**
     * Menghapus key dari session
     */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /**
     * Menghapus seluruh session (Logout)
     */
    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            $_SESSION = [];
            if (!headers_sent() && ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }
            session_destroy();
        }
    }

    /**
     * Menyimpan notifikasi Flash Message (hanya tampil 1x)
     */
    public static function setFlash(string $type, string $message): void
    {
        self::start();
        $_SESSION['__flash'][$type] = $message;
    }

    /**
     * Mengambil dan menghapus Flash Message
     */
    public static function getFlash(string $type): ?string
    {
        self::start();
        if (isset($_SESSION['__flash'][$type])) {
            $msg = $_SESSION['__flash'][$type];
            unset($_SESSION['__flash'][$type]);
            return $msg;
        }
        return null;
    }

    /**
     * Memeriksa apakah ada Flash Message untuk tipe tertentu
     */
    public static function hasFlash(string $type): bool
    {
        self::start();
        return isset($_SESSION['__flash'][$type]);
    }
}
