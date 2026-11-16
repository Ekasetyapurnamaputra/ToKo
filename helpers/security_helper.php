<?php
/**
 * Security Helper
 * Penanganan Proteksi CSRF, Anti-XSS, dan Sanitasi Input
 */

require_once dirname(__DIR__) . '/core/Session.php';

/**
 * Menghasilkan atau mengambil token CSRF dari session
 */
function csrf_token(): string
{
    Session::start();
    if (!Session::has('__csrf_token')) {
        Session::set('__csrf_token', bin2hex(random_bytes(32)));
    }
    return Session::get('__csrf_token');
}

/**
 * Menghasilkan tag HTML input hidden untuk token CSRF
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Memvalidasi token CSRF dari kiriman form (POST)
 */
function verify_csrf_token(?string $token): bool
{
    Session::start();
    $sessionToken = Session::get('__csrf_token');
    if (empty($sessionToken) || empty($token)) {
        return false;
    }
    return hash_equals($sessionToken, $token);
}

/**
 * Escape string untuk mencegah serangan XSS (Cross-Site Scripting)
 */
function e($value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Membersihkan input teks (trim & strip null bytes)
 */
function clean($data)
{
    if (is_array($data)) {
        return array_map('clean', $data);
    }
    if (is_string($data)) {
        return trim(str_replace(chr(0), '', $data));
    }
    return $data;
}

/**
 * Memeriksa apakah request bertipe POST
 */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

/**
 * Memeriksa apakah request bertipe GET
 */
function is_get(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET';
}
