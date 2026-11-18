<?php

namespace App\Services;

use App\Exceptions\RecipeCycleException;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use InvalidArgumentException;

/**
 * Perhitungan HPP resep. Semua uang/kuantitas dihitung dengan bcmath.
 *
 * Item yang dinormalisasi: ['ingredient_id'=>?int, 'sub_recipe_id'=>?int, 'qty'=>numeric, 'unit_id'=>int]
 * Harga bahan memakai avg_cost (fallback last_cost) per SATUAN DASAR bahan.
 * Biaya sub-resep dihitung langsung dari isinya (bukan dari angka tersimpan), jadi urutan hitung tidak berpengaruh.
 */
class RecipeCostService
{
    private const SCALE = 4;

    /** @var array<int, Ingredient> */
    private array $ingredients = [];

    public function __construct(private readonly UnitConverter $converter)
    {
    }

    /**
     * Hitung dari daftar item mentah (dipakai preview form maupun penyimpanan).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{total: string, per_portion: string, lines: array<int, array{cost: string}>}
     */
    public function estimate(array $items, string|int|float $yieldQty, ?int $recipeId = null): array
    {
        $path = $recipeId ? [$recipeId] : [];
        $lines = [];
        $total = '0';

        foreach (array_values($items) as $i => $item) {
            $cost = $this->lineCost($item, $path);
            $lines[$i] = ['cost' => $cost];
            $total = bcadd($total, $cost, self::SCALE);
        }

        $yield = UnitConverter::normalize($yieldQty);
        $perPortion = bccomp($yield, '0', self::SCALE) > 0
            ? bcdiv($total, $yield, self::SCALE)
            : '0.0000';

        return ['total' => $total, 'per_portion' => $perPortion, 'lines' => $lines];
    }

    /** Hitung ulang & simpan total_cost / cost_per_portion sebuah resep. */
    public function recalculate(Recipe $recipe): Recipe
    {
        $items = $recipe->items()->get()->map(fn (RecipeItem $i) => [
            'ingredient_id' => $i->ingredient_id,
            'sub_recipe_id' => $i->sub_recipe_id,
            'qty' => $i->qty,
            'unit_id' => $i->unit_id,
        ])->all();

        $result = $this->estimate($items, $recipe->yield_qty, $recipe->id);

        $recipe->forceFill([
            'total_cost' => $result['total'],
            'cost_per_portion' => $result['per_portion'],
        ])->saveQuietly();

        return $recipe;
    }

    /**
     * Panggil setelah harga sebuah bahan berubah (mis. setelah Goods Receipt).
     * Menghitung ulang semua resep yang memakai bahan itu, termasuk resep yang memakainya sebagai sub-resep.
     *
     * @return int jumlah resep yang dihitung ulang
     */
    public function recalculateForIngredient(int $ingredientId): int
    {
        unset($this->ingredients[$ingredientId]);

        $ids = RecipeItem::where('ingredient_id', $ingredientId)->pluck('recipe_id')->unique()->all();

        return $this->recalculateMany($this->withAncestors($ids));
    }

    /** Panggil setelah sebuah resep diubah: hitung ulang resep yang memakainya sebagai sub-resep (semua tingkat). */
    public function recalculateDependentsOfRecipe(int $recipeId): int
    {
        $parents = RecipeItem::where('sub_recipe_id', $recipeId)->pluck('recipe_id')->unique()->all();

        return $this->recalculateMany($this->withAncestors($parents));
    }

    /** @param int[] $ids @return int[] ids beserta semua resep yang memakainya sebagai sub-resep */
    private function withAncestors(array $ids): array
    {
        $frontier = $ids;
        while ($frontier) {
            $parents = RecipeItem::whereIn('sub_recipe_id', $frontier)->pluck('recipe_id')->unique()->all();
            $frontier = array_values(array_diff($parents, $ids));
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /** @param int[] $ids */
    private function recalculateMany(array $ids): int
    {
        $count = 0;
        foreach (Recipe::whereIn('id', $ids)->get() as $recipe) {
            $this->recalculate($recipe);
            $count++;
        }

        return $count;
    }

    public function recalculateAll(): int
    {
        $this->ingredients = [];

        $count = 0;
        Recipe::query()->each(function (Recipe $r) use (&$count) {
            $this->recalculate($r);
            $count++;
        });

        return $count;
    }

    /** Apakah menjadikan $candidateSubId sub-resep dari $recipeId akan membuat siklus? */
    public function wouldCreateCycle(int $recipeId, int $candidateSubId): bool
    {
        if ($recipeId === $candidateSubId) {
            return true;
        }

        $seen = [$candidateSubId => true];
        $stack = [$candidateSubId];

        while ($stack) {
            $current = array_pop($stack);
            $children = RecipeItem::where('recipe_id', $current)->whereNotNull('sub_recipe_id')->pluck('sub_recipe_id');
            foreach ($children as $child) {
                if ($child === $recipeId) {
                    return true;
                }
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $stack[] = $child;
                }
            }
        }

        return false;
    }

    /** Simpan snapshot versi resep saat ini (riwayat perubahan). */
    public function snapshot(Recipe $recipe, ?int $userId = null): RecipeVersion
    {
        $recipe->load(['items.ingredient', 'items.subRecipe', 'items.unit', 'yieldUnit']);

        return RecipeVersion::updateOrCreate(
            ['recipe_id' => $recipe->id, 'version' => $recipe->version],
            [
                'created_by' => $userId,
                'snapshot' => [
                    'code' => $recipe->code,
                    'name' => $recipe->name,
                    'yield_qty' => (string) $recipe->yield_qty,
                    'yield_unit' => $recipe->yieldUnit?->symbol,
                    'status' => $recipe->status?->value,
                    'total_cost' => (string) $recipe->total_cost,
                    'cost_per_portion' => (string) $recipe->cost_per_portion,
                    'items' => $recipe->items->map(fn (RecipeItem $i) => [
                        'ingredient' => $i->ingredient?->name,
                        'sub_recipe' => $i->subRecipe?->name,
                        'qty' => (string) $i->qty,
                        'unit' => $i->unit?->symbol,
                    ])->all(),
                ],
            ]
        );
    }

    public static function rupiah(string|int|float|null $value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $item @param int[] $path id resep yang sedang ditelusuri (deteksi siklus) */
    private function lineCost(array $item, array $path): string
    {
        $qty = UnitConverter::normalize($item['qty'] ?? 0);
        $unitId = (int) ($item['unit_id'] ?? 0);

        if (! empty($item['ingredient_id'])) {
            $ingredient = $this->ingredient((int) $item['ingredient_id']);
            $qtyBase = $this->converter->toBase($qty, $unitId, $ingredient);
            $unitCost = bccomp((string) $ingredient->avg_cost, '0', 6) > 0
                ? (string) $ingredient->avg_cost
                : (string) $ingredient->last_cost;

            return bcmul($qtyBase, $unitCost, self::SCALE);
        }

        if (! empty($item['sub_recipe_id'])) {
            $subId = (int) $item['sub_recipe_id'];
            if (in_array($subId, $path, true)) {
                throw new RecipeCycleException('Resep saling merujuk (siklus sub-resep) terdeteksi.');
            }

            $sub = Recipe::with('items')->findOrFail($subId);
            $subItems = $sub->items->map(fn (RecipeItem $i) => [
                'ingredient_id' => $i->ingredient_id,
                'sub_recipe_id' => $i->sub_recipe_id,
                'qty' => $i->qty,
                'unit_id' => $i->unit_id,
            ])->all();

            $subTotal = '0';
            foreach ($subItems as $subItem) {
                $subTotal = bcadd($subTotal, $this->lineCost($subItem, [...$path, $subId]), self::SCALE);
            }

            $yield = UnitConverter::normalize($sub->yield_qty);
            if (bccomp($yield, '0', self::SCALE) <= 0) {
                throw new InvalidArgumentException("Yield sub-resep {$sub->name} harus lebih dari 0.");
            }
            $costPerYieldUnit = bcdiv($subTotal, $yield, 8);
            $qtyInYieldUnit = $this->converter->convert($qty, $unitId, $sub->yield_unit_id);

            return bcmul($qtyInYieldUnit, $costPerYieldUnit, self::SCALE);
        }

        throw new InvalidArgumentException('Baris resep harus berisi bahan baku atau sub-resep.');
    }

    private function ingredient(int $id): Ingredient
    {
        return $this->ingredients[$id] ??= Ingredient::findOrFail($id);
    }
}
