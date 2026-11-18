<?php

namespace Database\Seeders;

use App\Enums\RecipeStatus;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Unit;
use App\Services\RecipeCostService;
use Illuminate\Database\Seeder;

/**
 * Contoh data dari Visual Brief: resep "Nasi Goreng Special" (RSP-018).
 * Hasil yang diharapkan: Total HPP Rp 84.460, HPP per porsi Rp 8.446.
 * Jalankan setelah SaporeSeeder (butuh satuan & konversi).
 */
class SampleRecipeSeeder extends Seeder
{
    public function run(): void
    {
        $unit = fn (string $symbol) => Unit::where('symbol', $symbol)->firstOrFail();
        $cat = fn (string $name) => Category::firstOrCreate(['name' => $name, 'type' => 'ingredient']);

        // [kode, nama, kategori, satuan dasar, harga per satuan dasar, stok min]
        $rows = [
            ['BB-050', 'Beras Premium', 'Karbohidrat', 'kg', 14000, 10],
            ['BB-003', 'Ayam Fillet', 'Protein', 'kg', 45000, 6],
            ['BB-014', 'Bawang Merah', 'Sayuran', 'kg', 32000, 3],
            ['BB-051', 'Bawang Putih', 'Sayuran', 'kg', 40000, 2],
            ['BB-052', 'Kecap Manis', 'Bumbu', 'L', 28000, 2],
            ['BB-022', 'Minyak Goreng', 'Minyak', 'L', 18000, 10],
            ['BB-053', 'Telur', 'Protein', 'butir', 2500, 30],
        ];

        $ing = [];
        foreach ($rows as [$code, $name, $category, $symbol, $cost, $min]) {
            $ing[$code] = Ingredient::updateOrCreate(['code' => $code], [
                'name' => $name,
                'category_id' => $cat($category)->id,
                'base_unit_id' => $unit($symbol)->id,
                'avg_cost' => $cost,
                'last_cost' => $cost,
                'min_stock' => $min,
            ]);
        }

        $recipe = Recipe::updateOrCreate(['code' => 'RSP-018'], [
            'name' => 'Nasi Goreng Special',
            'yield_qty' => 10,
            'yield_unit_id' => $unit('porsi')->id,
            'status' => RecipeStatus::Active,
            'version' => 1,
        ]);

        // sengaja memakai g dan ml agar konversi satuan ikut teruji
        $recipe->items()->delete();
        foreach ([
            ['BB-050', 1.5, 'kg'],
            ['BB-003', 0.8, 'kg'],
            ['BB-014', 150, 'g'],
            ['BB-051', 80, 'g'],
            ['BB-052', 120, 'ml'],
            ['BB-022', 200, 'ml'],
            ['BB-053', 5, 'butir'],
        ] as [$code, $qty, $symbol]) {
            $recipe->items()->create([
                'ingredient_id' => $ing[$code]->id,
                'qty' => $qty,
                'unit_id' => $unit($symbol)->id,
            ]);
        }

        $service = app(RecipeCostService::class);
        $service->recalculate($recipe);
        $service->snapshot($recipe->refresh());
    }
}
