<?php

namespace App\Support;

/**
 * Status stok terhadap stok minimum:
 *  Kritis : stok < 50% minimum, Rendah : stok < minimum, Aman : selebihnya (atau minimum = 0).
 */
class StockStatus
{
    public const CRITICAL = 'critical';
    public const LOW = 'low';
    public const OK = 'ok';

    public static function for(string|int|float $qty, string|int|float $min): string
    {
        $qty = (float) $qty;
        $min = (float) $min;

        if ($min <= 0) {
            return self::OK;
        }
        if ($qty < $min * 0.5) {
            return self::CRITICAL;
        }

        return $qty < $min ? self::LOW : self::OK;
    }

    public static function label(string $status): string
    {
        return ['critical' => 'Kritis', 'low' => 'Rendah', 'ok' => 'Aman'][$status] ?? $status;
    }

    public static function color(string $status): string
    {
        return ['critical' => 'danger', 'low' => 'warning', 'ok' => 'success'][$status] ?? 'gray';
    }
}
