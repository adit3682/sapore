<?php

namespace Tests\Feature;

use App\Enums\RecipeStatus;
use App\Exceptions\ConversionException;
use App\Exceptions\RecipeCycleException;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Unit;
use App\Services\RecipeCostService;
use App\Services\UnitConverter;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);
    }

    private function unit(string $symbol): Unit
    {
        return Unit::where('symbol', $symbol)->firstOrFail();
    }

    public function test_converts_both_directions(): void
    {
        $c = app(UnitConverter::class);

        $this->assertSame('0.150000', $c->convert('150', $this->unit('g')->id, $this->unit('kg')->id));
        $this->assertSame('2500.000000', $c->convert('2.5', $this->unit('kg')->id, $this->unit('g')->id));
    }

    public function test_throws_when_no_conversion_exists(): void
    {
        $this->expectException(ConversionException::class);
        app(UnitConverter::class)->convert('1', $this->unit('butir')->id, $this->unit('kg')->id);
    }

    public function test_ingredient_specific_conversion(): void
    {
        $egg = Ingredient::where('code', 'BB-053')->firstOrFail();
        $kg = $this->unit('kg');
        // 1 butir = 0.06 kg khusus telur
        \App\Models\UnitConversion::create([
            'from_unit_id' => $egg->base_unit_id, 'to_unit_id' => $kg->id,
            'multiplier' => 0.06, 'ingredient_id' => $egg->id,
        ]);

        $this->assertSame('0.300000', app(UnitConverter::class)->convert('5', $egg->base_unit_id, $kg->id, $egg->id));
    }

    public function test_nasi_goreng_matches_visual_brief(): void
    {
        $recipe = Recipe::where('code', 'RSP-018')->firstOrFail();

        $this->assertEquals(84460, (float) $recipe->total_cost);
        $this->assertEquals(8446, (float) $recipe->cost_per_portion);
        $this->assertDatabaseHas('recipe_versions', ['recipe_id' => $recipe->id, 'version' => 1]);
    }

    public function test_price_change_recalculates_recipe(): void
    {
        $beras = Ingredient::where('code', 'BB-050')->firstOrFail();
        $beras->update(['avg_cost' => 16000]); // +2000/kg x 1.5 kg = +3000

        $this->assertSame(1, app(RecipeCostService::class)->recalculateForIngredient($beras->id));
        $this->assertEquals(87460, (float) Recipe::where('code', 'RSP-018')->value('total_cost'));
    }

    public function test_sub_recipe_cost_flows_into_parent(): void
    {
        $service = app(RecipeCostService::class);
        $kg = $this->unit('kg');
        $beras = Ingredient::where('code', 'BB-050')->firstOrFail();

        // sub-resep: 2 kg nasi dari 2 kg beras -> Rp 28.000 (Rp 14.000 per kg hasil)
        $base = Recipe::create([
            'code' => 'RSP-SUB', 'name' => 'Nasi Matang', 'yield_qty' => 2,
            'yield_unit_id' => $kg->id, 'status' => RecipeStatus::Active, 'version' => 1,
        ]);
        $base->items()->create(['ingredient_id' => $beras->id, 'qty' => 2, 'unit_id' => $kg->id]);
        $service->recalculate($base);

        // induk memakai 500 g sub-resep -> 0.5 kg x 14.000 = 7.000
        $parent = Recipe::create([
            'code' => 'RSP-PAR', 'name' => 'Nasi Kotak', 'yield_qty' => 1,
            'yield_unit_id' => $this->unit('porsi')->id, 'status' => RecipeStatus::Active, 'version' => 1,
        ]);
        $parent->items()->create(['sub_recipe_id' => $base->id, 'qty' => 500, 'unit_id' => $this->unit('g')->id]);
        $service->recalculate($parent);

        $this->assertEquals(7000, (float) $parent->fresh()->total_cost);

        // harga beras naik -> induk ikut berubah lewat rantai sub-resep
        $beras->update(['avg_cost' => 20000]);
        $service->recalculateForIngredient($beras->id);
        $this->assertEquals(10000, (float) $parent->fresh()->total_cost);
    }

    public function test_cycle_detection(): void
    {
        $service = app(RecipeCostService::class);
        $kg = $this->unit('kg');

        $a = Recipe::create(['code' => 'A', 'name' => 'A', 'yield_qty' => 1, 'yield_unit_id' => $kg->id, 'version' => 1]);
        $b = Recipe::create(['code' => 'B', 'name' => 'B', 'yield_qty' => 1, 'yield_unit_id' => $kg->id, 'version' => 1]);
        $b->items()->create(['sub_recipe_id' => $a->id, 'qty' => 1, 'unit_id' => $kg->id]); // B memakai A

        $this->assertTrue($service->wouldCreateCycle($a->id, $a->id));
        $this->assertTrue($service->wouldCreateCycle($a->id, $b->id)); // A memakai B -> siklus
        $this->assertFalse($service->wouldCreateCycle($b->id, $a->id));

        $this->expectException(RecipeCycleException::class);
        $service->estimate([['sub_recipe_id' => $b->id, 'qty' => 1, 'unit_id' => $kg->id]], 1, $a->id);
    }
}
