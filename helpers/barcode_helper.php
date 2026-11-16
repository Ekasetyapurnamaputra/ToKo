<?php
/**
 * Helper Barcode Generator (Code 39 Pure SVG)
 * Standalone, 100% Offline, Zero Dependencies, Standard Industrial Code 39
 */

function generate_barcode_svg(string $text, int $barHeight = 44, int $narrowWidth = 2, int $wideWidth = 5): string
{
    $table = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnnwwn', 'H' => 'wnnnnnwwn', 'I' => 'nnwnnnwwn', 'J' => 'nnnnwnwwn',
        'K' => 'wnnnnnnnw', 'L' => 'nnwnnnnnw', 'M' => 'wnwnnnnnn', 'N' => 'nnnnwnnnw',
        'O' => 'wnnnwnnnn', 'P' => 'nnwnwnnnn', 'Q' => 'nnnnnnwnw', 'R' => 'wnnnnnwnn',
        'S' => 'nnwnnnwnn', 'T' => 'nnnnwnwnn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn',
        '$' => 'nwnwnwnnn', '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn'
    ];

    $clean = strtoupper(trim($text));
    // Filter hanya karakter yang didukung Code 39
    $clean = preg_replace('/[^0-9A-Z\-\. \$\/\+\%]/', '', $clean);
    if (empty($clean)) {
        $clean = '0';
    }

    $formatted = '*' . $clean . '*';
    $rects = [];
    $x = 10; // Margin kiri

    for ($i = 0; $i < strlen($formatted); $i++) {
        $char = $formatted[$i];
        if (!isset($table[$char])) continue;
        $pattern = $table[$char];

        for ($p = 0; $p < 9; $p++) {
            $isBar = ($p % 2 === 0);
            $isWide = ($pattern[$p] === 'w');
            $width = $isWide ? $wideWidth : $narrowWidth;

            if ($isBar) {
                $rects[] = "<rect x=\"{$x}\" y=\"0\" width=\"{$width}\" height=\"{$barHeight}\" fill=\"#111827\" />";
            }
            $x += $width;
        }

        // Inter-character space
        $x += $narrowWidth;
    }

    $x += 8; // Margin kanan
    $totalWidth = $x;
    $totalHeight = $barHeight + 18;
    $textY = $barHeight + 14;

    $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$totalWidth}\" height=\"{$totalHeight}\" viewBox=\"0 0 {$totalWidth} {$totalHeight}\" style=\"max-width: 100%; height: auto; display: block; margin: 0 auto;\">";
    $svg .= implode('', $rects);
    $svg .= "<text x=\"" . ($totalWidth / 2) . "\" y=\"{$textY}\" text-anchor=\"middle\" font-family=\"'Courier New', Courier, monospace\" font-size=\"12\" font-weight=\"bold\" fill=\"#111827\" letter-spacing=\"2\">*{$clean}*</text>";
    $svg .= "</svg>";

    return $svg;
}
