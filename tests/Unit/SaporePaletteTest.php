<?php

namespace Tests\Unit;

use App\Support\SaporePalette;
use PHPUnit\Framework\TestCase;

class SaporePaletteTest extends TestCase
{
    public function test_base_color_is_shade_600(): void
    {
        $shades = SaporePalette::shades('#c26944');

        $this->assertSame('194, 105, 68', $shades[600]); // terracotta persis = warna tombol utama
        $this->assertSame([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950], array_keys($shades));
    }

    public function test_lighter_shades_are_lighter_and_darker_shades_are_darker(): void
    {
        $sum = fn (string $rgb) => array_sum(array_map('intval', explode(',', $rgb)));
        $shades = SaporePalette::shades('#1a365d');

        $order = array_map($sum, [$shades[50], $shades[200], $shades[400], $shades[600], $shades[800], $shades[950]]);
        $sorted = $order;
        rsort($sorted);

        $this->assertSame($sorted, $order); // makin gelap secara berurutan
    }

    public function test_gray_follows_visual_brief_colors(): void
    {
        $gray = SaporePalette::gray();

        $this->assertSame('250, 247, 242', $gray[50]);  // latar krem #faf7f2
        $this->assertSame('100, 112, 125', $gray[500]); // teks sekunder #64707d
        $this->assertSame('35, 45, 55', $gray[800]);    // teks utama #232d37
    }

    public function test_panel_colors_cover_all_filament_roles(): void
    {
        $colors = SaporePalette::panelColors();

        foreach (['primary', 'info', 'success', 'warning', 'danger', 'gray'] as $role) {
            $this->assertArrayHasKey($role, $colors);
            $this->assertCount(11, $colors[$role]);
        }
        $this->assertSame('194, 105, 68', $colors['primary'][600]);
    }
}
