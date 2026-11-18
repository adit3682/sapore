<?php

namespace App\Support;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Query bersama untuk dashboard & laporan stok. Aturan status sama dengan StockStatus. */
class StockQueries
{
    private const MIN = '(select min_stock from ingredients where ingredients.id = stocks.ingredient_id)';

    /** Baris stok di bawah minimum (Rendah + Kritis). */
    public static function low(?int $branchId = null): Builder
    {
        $min = self::MIN;

        return Stock::query()
            ->when($branchId, fn ($q, $b) => $q->where('stocks.branch_id', $b))
            ->whereRaw("{$min} > 0 and stocks.quantity < {$min}");
    }

    /** Nilai persediaan = stok positif x harga rata-rata. */
    public static function inventoryValue(?int $branchId = null): string
    {
        $value = DB::table('stocks')
            ->join('ingredients', 'ingredients.id', '=', 'stocks.ingredient_id')
            ->when($branchId, fn ($q, $b) => $q->where('stocks.branch_id', $b))
            ->sum(DB::raw('CASE WHEN stocks.quantity > 0 THEN stocks.quantity * ingredients.avg_cost ELSE 0 END'));

        return bcadd((string) $value, '0', 2);
    }

    /** Ringkas: 4,2 juta -> "Rp 4,2jt". */
    public static function compactRupiah(string|float $value): string
    {
        $v = (float) $value;
        if ($v >= 1_000_000_000) {
            return 'Rp '.rtrim(rtrim(number_format($v / 1_000_000_000, 1, ',', '.'), '0'), ',').'M';
        }
        if ($v >= 1_000_000) {
            return 'Rp '.rtrim(rtrim(number_format($v / 1_000_000, 1, ',', '.'), '0'), ',').'jt';
        }

        return 'Rp '.number_format($v, 0, ',', '.');
    }
}
