<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Menu;
use App\Models\Package;
use App\Models\Recipe;
use Illuminate\Database\Seeder;

/** Contoh menu & paket di atas resep RSP-018. Jalankan setelah SampleRecipeSeeder. */
class SampleMenuSeeder extends Seeder
{
    public function run(): void
    {
        $recipe = Recipe::where('code', 'RSP-018')->firstOrFail();

        $menu = Menu::updateOrCreate(['code' => 'MN-042'], [
            'name' => 'Nasi Goreng Special',
            'category_id' => Category::firstOrCreate(['name' => 'Main Course', 'type' => 'menu'])->id,
            'recipe_id' => $recipe->id,
            'selling_price' => 40000,
            'is_active' => true,
        ]);

        $package = Package::updateOrCreate(['code' => 'PKT-001'], [
            'name' => 'Paket Berdua',
            'selling_price' => 70000,
            'is_active' => true,
        ]);
        $package->items()->delete();
        $package->items()->create(['menu_id' => $menu->id, 'qty' => 2]);
    }
}
