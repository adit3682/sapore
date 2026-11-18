<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\Package;

/**
 * HPP menu/paket dan margin. Menu = 1 porsi resep, jadi HPP menu = recipes.cost_per_portion
 * (angka itu dijaga oleh RecipeCostService, termasuk saat harga bahan berubah).
 */
class MenuCostService
{
    public function menuCost(Menu $menu): string
    {
        return (string) ($menu->recipe?->cost_per_portion ?? '0');
    }

    public function packageCost(Package $package): string
    {
        $package->loadMissing('items.menu.recipe');

        $total = '0';
        foreach ($package->items as $item) {
            $total = bcadd($total, bcmul($this->menuCost($item->menu), (string) $item->qty, 4), 4);
        }

        return $total;
    }

    /**
     * @return array{cost: string, price: string, margin: string, margin_pct: ?string, food_cost_pct: ?string}
     */
    public function summarize(string|int|float $cost, string|int|float|null $price): array
    {
        $cost = UnitConverter::normalize($cost);
        $price = UnitConverter::normalize($price ?? 0);
        $hasPrice = bccomp($price, '0', 2) > 0;

        $margin = bcsub($price, $cost, 2);

        return [
            'cost' => $cost,
            'price' => $price,
            'margin' => $margin,
            'margin_pct' => $hasPrice ? bcmul(bcdiv($margin, $price, 6), '100', 1) : null,
            'food_cost_pct' => $hasPrice ? bcmul(bcdiv($cost, $price, 6), '100', 1) : null,
        ];
    }
}
