<?php

namespace App\Services;

use App\Exceptions\RecipeCycleException;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Package;
use App\Models\ProductionPlan;
use App\Models\Recipe;
use App\Models\Stock;
use InvalidArgumentException;

/**
 * BOM Explosion: Paket -> Menu -> Resep -> (sub-resep) -> Bahan Baku.
 *
 * Aturan skala: 1 porsi menu = 1 satuan yield resep. Resep dengan yield 10 porsi dan
 * menu terjual 25 porsi => skala 2,5. Sub-resep diskalakan dari jumlah yang dipakai
 * (dikonversi ke satuan yield sub-resep) dibagi yield sub-resep.
 * Hasil selalu dalam SATUAN DASAR bahan, diakumulasi per bahan (bahan yang sama dari
 * banyak resep dijumlahkan). Pembagian/perkalian memakai bcmath.
 *
 * Dipakai juga oleh POS (Fase 2) lewat explodeItems() untuk memotong stok dari penjualan.
 */
class BomExplosionService
{
    private const S = 10; // presisi internal

    /** @var array<int, Ingredient> */
    private array $ingredients = [];

    /** @var array<int, Recipe> */
    private array $recipes = [];

    public function __construct(private readonly UnitConverter $converter)
    {
    }

    /**
     * @param  iterable<int, array{type: string, id: int|string, qty: int|float|string}>  $items  type: menu|package
     * @return array<int, string> ingredient_id => kebutuhan (satuan dasar)
     */
    public function explodeItems(iterable $items): array
    {
        $totals = [];

        foreach ($items as $item) {
            $qty = UnitConverter::normalize($item['qty']);
            if (bccomp($qty, '0', 6) <= 0) {
                continue;
            }

            match ($item['type']) {
                'menu' => $this->addMenu((int) $item['id'], $qty, $totals),
                'package' => $this->addPackage((int) $item['id'], $qty, $totals),
                default => throw new InvalidArgumentException("Jenis item tidak dikenal: {$item['type']}"),
            };
        }

        return array_map(fn (string $v) => bcadd($v, '0', 4), $totals);
    }

    /**
     * Bandingkan kebutuhan dengan stok cabang.
     *
     * @param  array<int, array{type: string, id: int|string, qty: int|float|string}>  $items
     * @param  array<int, string>  $ownReserved  ingredient_id => qty yang sudah dipesan rencana ini (agar tidak dihitung dua kali)
     * @return array{rows: array<int, array<string, mixed>>, shortage_count: int, shortage_cost: string}
     */
    public function requirements(array $items, int $branchId, array $ownReserved = []): array
    {
        $needs = $this->explodeItems($items);
        $ids = array_keys($needs);

        $ingredients = Ingredient::with('baseUnit')->whereIn('id', $ids)->get()->keyBy('id');
        $stocks = Stock::where('branch_id', $branchId)->whereIn('ingredient_id', $ids)->get()->keyBy('ingredient_id');

        $rows = [];
        $shortageCount = 0;
        $shortageCost = '0';

        foreach ($needs as $id => $required) {
            $ingredient = $ingredients[$id];
            $stock = $stocks[$id] ?? null;

            $onHand = (string) ($stock?->quantity ?? '0');
            $reserved = bcsub((string) ($stock?->reserved_quantity ?? '0'), $ownReserved[$id] ?? '0', 4);
            $available = bcsub($onHand, $reserved, 4);

            $usable = bccomp($available, '0', 4) > 0 ? $available : '0';
            $shortage = bccomp($required, $usable, 4) > 0 ? bcsub($required, $usable, 4) : '0.0000';
            $isShort = bccomp($shortage, '0', 4) > 0;

            $unitCost = bccomp((string) $ingredient->avg_cost, '0', 6) > 0 ? (string) $ingredient->avg_cost : (string) $ingredient->last_cost;
            $estCost = bcmul($shortage, $unitCost, 2);

            if ($isShort) {
                $shortageCount++;
                $shortageCost = bcadd($shortageCost, $estCost, 2);
            }

            $rows[] = [
                'ingredient' => $ingredient,
                'unit' => $ingredient->baseUnit?->symbol,
                'required' => $required,
                'on_hand' => $onHand,
                'available' => $available,
                'shortage' => $shortage,
                'is_short' => $isShort,
                'est_cost' => $estCost,
            ];
        }

        // yang kurang di atas, lalu urut nama
        usort($rows, fn ($a, $b) => [$b['is_short'], $a['ingredient']->name] <=> [$a['is_short'], $b['ingredient']->name]);

        return ['rows' => $rows, 'shortage_count' => $shortageCount, 'shortage_cost' => $shortageCost];
    }

    /** Kebutuhan sebuah rencana produksi (memperhitungkan pesanannya sendiri bila sudah Confirmed). */
    public function requirementsForPlan(ProductionPlan $plan): array
    {
        $items = $plan->items->map(fn ($i) => [
            'type' => $i->item_type, 'id' => $i->item_id, 'qty' => $i->qty,
        ])->all();

        $own = $plan->reservations->mapWithKeys(fn ($r) => [$r->ingredient_id => (string) $r->qty_base])->all();

        return $this->requirements($items, $plan->branch_id, $own);
    }

    // ------------------------------------------------------------------

    /** @param array<int, string> $totals */
    private function addPackage(int $packageId, string $qty, array &$totals): void
    {
        $package = Package::with('items')->findOrFail($packageId);

        foreach ($package->items as $item) {
            $this->addMenu($item->menu_id, bcmul($qty, UnitConverter::normalize($item->qty), 6), $totals);
        }
    }

    /** @param array<int, string> $totals */
    private function addMenu(int $menuId, string $portions, array &$totals): void
    {
        $menu = Menu::findOrFail($menuId);
        $recipe = $this->recipe($menu->recipe_id);

        $yield = UnitConverter::normalize($recipe->yield_qty);
        if (bccomp($yield, '0', 6) <= 0) {
            throw new InvalidArgumentException("Yield resep {$recipe->name} harus lebih dari 0.");
        }

        $this->addRecipe($recipe, bcdiv($portions, $yield, self::S), [$recipe->id], $totals);
    }

    /**
     * @param  int[]  $path
     * @param  array<int, string>  $totals
     */
    private function addRecipe(Recipe $recipe, string $scale, array $path, array &$totals): void
    {
        foreach ($recipe->items as $item) {
            $qty = bcmul(UnitConverter::normalize($item->qty), $scale, self::S);

            if ($item->ingredient_id) {
                $ingredient = $this->ingredient($item->ingredient_id);
                $base = $this->converter->toBase($qty, $item->unit_id, $ingredient);
                $totals[$ingredient->id] = bcadd($totals[$ingredient->id] ?? '0', $base, 6);

                continue;
            }

            $subId = (int) $item->sub_recipe_id;
            if (in_array($subId, $path, true)) {
                throw new RecipeCycleException('Resep saling merujuk (siklus sub-resep) terdeteksi.');
            }

            $sub = $this->recipe($subId);
            $subYield = UnitConverter::normalize($sub->yield_qty);
            if (bccomp($subYield, '0', 6) <= 0) {
                throw new InvalidArgumentException("Yield sub-resep {$sub->name} harus lebih dari 0.");
            }

            $inYieldUnit = $this->converter->convert($qty, $item->unit_id, $sub->yield_unit_id);
            $this->addRecipe($sub, bcdiv($inYieldUnit, $subYield, self::S), [...$path, $subId], $totals);
        }
    }

    private function recipe(int $id): Recipe
    {
        return $this->recipes[$id] ??= Recipe::with('items')->findOrFail($id);
    }

    private function ingredient(int $id): Ingredient
    {
        return $this->ingredients[$id] ??= Ingredient::findOrFail($id);
    }
}
