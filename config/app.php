<?php
/**
 * Konfigurasi Aplikasi ToKo
 * Sistem Informasi Penjualan Toko Berbasis Web
 */

// Set Timezone
date_default_timezone_set('Asia/Jakarta');

// Deteksi Base URL Otomatis
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$baseUrl = rtrim($protocol . $host . $scriptDir, '/');

// Konstanta Aplikasi
if (!defined('APP_NAME')) {
    define('APP_NAME', 'ToKo');
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '2.2.0');
}
if (!defined('BASE_URL')) {
    define('BASE_URL', $baseUrl);
}
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Pengaturan Environment: 'development' atau 'production'
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'development');
}

// Penanganan Error Berdasarkan Environment
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', BASE_PATH . '/logs/error.log');
}
