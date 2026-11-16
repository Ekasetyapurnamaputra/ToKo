<?php
/**
 * Format Helper
 * Bantuan pemformatan mata uang, tanggal Indonesia, dan angka
 */

/**
 * Format nominal angka ke format Rupiah (Rp xx.xxx)
 */
function format_rupiah($nominal, bool $withPrefix = true): string
{
    $formatted = number_format((float)$nominal, 0, ',', '.');
    return $withPrefix ? 'Rp ' . $formatted : $formatted;
}

/**
 * Format tanggal ke format bahasa Indonesia
 * Contoh: 16 November 2026 atau 16 Nov 2026 10:15
 */
function format_tanggal(?string $datetime, bool $withTime = false): string
{
    if (empty($datetime) || $datetime === '0000-00-00' || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return $datetime;
    }

    $bulanIndo = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    $hari = date('d', $timestamp);
    $bulan = $bulanIndo[(int)date('m', $timestamp)] ?? date('m', $timestamp);
    $tahun = date('Y', $timestamp);

    $hasil = "{$hari} {$bulan} {$tahun}";
    if ($withTime) {
        $hasil .= ' ' . date('H:i', $timestamp);
    }

    return $hasil;
}

/**
 * Format angka desimal / integer biasa
 */
function format_angka($number, int $decimals = 0): string
{
    return number_format((float)$number, $decimals, ',', '.');
}
