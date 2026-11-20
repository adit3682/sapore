<?php

namespace App\Support;

/**
 * Palet Visual Brief dalam format warna Filament (string "R, G, B" per tingkat 50..950).
 * Warna dasar dipakai sebagai tingkat 600 (warna tombol/aksen utama di Filament); tingkat lebih terang
 * dicampur ke putih dan tingkat lebih gelap dicampur ke hitam.
 */
class SaporePalette
{
    public const NAVY_DARK = '#0f2341';
    public const NAVY = '#1a365d';
    public const TERRACOTTA = '#c26944';
    public const SAGE = '#78907e';
    public const CREAM = '#faf7f2';
    public const CARD = '#fffdfa';
    public const TEXT = '#232d37';
    public const MUTED = '#64707d';

    /** @return array<string, array<int, string>> */
    public static function panelColors(): array
    {
        return [
            'primary' => self::shades(self::TERRACOTTA),
            'info' => self::shades(self::NAVY),
            'success' => self::shades(self::SAGE),
            'warning' => self::shades('#c98a3c'),
            'danger' => self::shades('#b94a48'),
            'gray' => self::gray(),
        ];
    }

    /** @return array<int, string> */
    public static function shades(string $hex600): array
    {
        [$r, $g, $b] = self::rgb($hex600);
        $out = [];

        foreach ([50 => 0.92, 100 => 0.84, 200 => 0.68, 300 => 0.50, 400 => 0.28, 500 => 0.12] as $shade => $t) {
            $out[$shade] = self::fmt(self::mix($r, 255, $t), self::mix($g, 255, $t), self::mix($b, 255, $t));
        }
        $out[600] = self::fmt($r, $g, $b);
        foreach ([700 => 0.18, 800 => 0.34, 900 => 0.50, 950 => 0.64] as $shade => $t) {
            $out[$shade] = self::fmt(self::mix($r, 0, $t), self::mix($g, 0, $t), self::mix($b, 0, $t));
        }

        return $out;
    }

    /**
     * Abu-abu hangat: latar krem di ujung terang, teks Navy-slate di ujung gelap.
     * 50 = latar halaman, 500 = teks sekunder (#64707d), 800 = teks utama (#232d37).
     *
     * @return array<int, string>
     */
    public static function gray(): array
    {
        $hex = [
            50 => self::CREAM, 100 => '#f2ede4', 200 => '#e5ded1', 300 => '#d3cbbd', 400 => '#9aa2ab',
            500 => self::MUTED, 600 => '#4a5560', 700 => '#38424d', 800 => self::TEXT, 900 => '#18212a', 950 => '#0f161d',
        ];

        return array_map(fn (string $h) => self::fmt(...self::rgb($h)), $hex);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function mix(int $from, int $to, float $t): int
    {
        return (int) round($from + ($to - $from) * $t);
    }

    private static function fmt(int $r, int $g, int $b): string
    {
        return "{$r}, {$g}, {$b}";
    }
}
