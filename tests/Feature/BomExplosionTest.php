<?php

namespace Tests\Feature;

use App\Enums\PlanStatus;
use App\Enums\RecipeStatus;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\ProductionPlan;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Services\BomExplosionService;
use App\Services\GoodsReceiptService;
use App\Services\MenuCostService;
use App\Services\ProductionPlanService;
use App\Services\PurchaseRequestService;
use Database\Seeders\SampleMenuSeeder;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BomExplosionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);
        $this->seed(SampleMenuSeeder::class);
        $this->branch = Branch::firstOrFail();
    }

    private function ing(string $code): Ingredient
    {
        return Ingredient::where('code', $code)->firstOrFail();
    }

    private function unit(string $symbol): Unit
    {
        return Unit::where('symbol', $symbol)->firstOrFail();
    }

    private function menuId(): int
    {
        return Menu::where('code', 'MN-042')->value('id');
    }

    private function packageId(): int
    {
        return \App\Models\Package::where('code', 'PKT-001')->value('id');
    }

    private function stockIn(string $code, string $qty, string $unit, string $price): void
    {
        app(GoodsReceiptService::class)->post(
            ['branch_id' => $this->branch->id, 'receipt_date' => '2026-11-19'],
            [['ingredient_id' => $this->ing($code)->id, 'qty' => $qty, 'unit_id' => $this->unit($unit)->id, 'unit_price' => $price]]
        );
    }

    private function plan(array $items): ProductionPlan
    {
        $plan = ProductionPlan::create(['branch_id' => $this->branch->id, 'plan_date' => '2026-11-20', 'status' => PlanStatus::Draft]);
        foreach ($items as [$type, $id, $qty]) {
            $plan->items()->create(['item_type' => $type, 'item_id' => $id, 'qty' => $qty]);
        }

        return $plan->fresh(['items', 'reservations']);
    }

    public function test_menu_and_package_margin(): void
    {
        $svc = app(MenuCostService::class);
        $menu = Menu::where('code', 'MN-042')->firstOrFail();

        $this->assertSame('8446.0000', $svc->menuCost($menu));
        $s = $svc->summarize($svc->menuCost($menu), $menu->selling_price);
        $this->assertSame('21.1', $s['food_cost_pct']);

        // paket = 2 menu: HPP 16.892, harga 70.000
        $package = \App\Models\Package::where('code', 'PKT-001')->firstOrFail();
        $this->assertSame('16892.0000', $svc->packageCost($package));
    }

    public function test_explosion_accumulates_menu_and_package(): void
    {
        // 20 porsi menu + 3 paket (@2 porsi) = 26 porsi => skala 2,6 dari resep yield 10
        $need = app(BomExplosionService::class)->explodeItems([
            ['type' => 'menu', 'id' => $this->menuId(), 'qty' => 20],
            ['type' => 'package', 'id' => $this->packageId(), 'qty' => 3],
        ]);

        $this->assertEquals(3.9, (float) $need[$this->ing('BB-050')->id]);   // beras 1,5 kg x 2,6
        $this->assertEquals(13, (float) $need[$this->ing('BB-053')->id]);    // telur 5 x 2,6
        $this->assertEquals(0.208, (float) $need[$this->ing('BB-051')->id]); // bawang putih 80 g x 2,6 -> kg
        $this->assertEquals(0.312, (float) $need[$this->ing('BB-052')->id]); // kecap 120 ml x 2,6 -> L
    }

    public function test_explosion_through_sub_recipe(): void
    {
        $kg = $this->unit('kg');
        $sub = Recipe::create([
            'code' => 'RSP-BUMBU', 'name' => 'Bumbu Dasar', 'yield_qty' => 2, 'yield_unit_id' => $kg->id,
            'status' => RecipeStatus::Active, 'version' => 1,
        ]);
        $sub->items()->create(['ingredient_id' => $this->ing('BB-014')->id, 'qty' => 1, 'unit_id' => $kg->id]);
        $sub->items()->create(['ingredient_id' => $this->ing('BB-051')->id, 'qty' => 1, 'unit_id' => $kg->id]);

        $main = Recipe::create([
            'code' => 'RSP-X', 'name' => 'Ayam Bumbu', 'yield_qty' => 1, 'yield_unit_id' => $this->unit('porsi')->id,
            'status' => RecipeStatus::Active, 'version' => 1,
        ]);
        $main->items()->create(['sub_recipe_id' => $sub->id, 'qty' => 500, 'unit_id' => $this->unit('g')->id]);

        $menu = Menu::create(['code' => 'MN-X', 'name' => 'Ayam Bumbu', 'recipe_id' => $main->id, 'selling_price' => 30000]);

        // 4 porsi x 500 g = 2 kg bumbu = tepat 1 batch sub-resep (yield 2 kg)
        $need = app(BomExplosionService::class)->explodeItems([['type' => 'menu', 'id' => $menu->id, 'qty' => 4]]);

        $this->assertEquals(1, (float) $need[$this->ing('BB-014')->id]);
        $this->assertEquals(1, (float) $need[$this->ing('BB-051')->id]);
    }

    public function test_requirements_compare_with_stock(): void
    {
        $this->stockIn('BB-050', '2', 'kg', '14000'); // butuh 3 kg untuk 20 porsi -> kurang 1

        $result = app(BomExplosionService::class)->requirements(
            [['type' => 'menu', 'id' => $this->menuId(), 'qty' => 20]], $this->branch->id
        );
        $rows = collect($result['rows'])->keyBy(fn ($r) => $r['ingredient']->code);

        $this->assertEquals(3, (float) $rows['BB-050']['required']);
        $this->assertEquals(1, (float) $rows['BB-050']['shortage']);
        $this->assertTrue($rows['BB-050']['is_short']);
        $this->assertEquals(10, (float) $rows['BB-053']['shortage']); // telur: stok 0
        $this->assertSame(7, $result['shortage_count']);               // semua 7 bahan kurang
    }

    public function test_confirm_reserves_and_release_restores(): void
    {
        $this->stockIn('BB-050', '10', 'kg', '14000');
        $plan = $this->plan([['menu', $this->menuId(), 20]]); // beras 3 kg
        $svc = app(ProductionPlanService::class);
        $stock = fn () => Stock::where(['ingredient_id' => $this->ing('BB-050')->id, 'branch_id' => $this->branch->id])->first();

        $svc->confirm($plan);
        $this->assertEquals(3, (float) $stock()->reserved_quantity);
        $this->assertEquals(10, (float) $stock()->quantity); // stok fisik tidak berubah
        $this->assertSame(PlanStatus::Confirmed, $plan->fresh()->status);

        // rencana yang sama tidak menghitung pesanannya sendiri dua kali
        $again = app(BomExplosionService::class)->requirementsForPlan($plan->fresh(['items', 'reservations']));
        $beras = collect($again['rows'])->first(fn ($r) => $r['ingredient']->code === 'BB-050');
        $this->assertEquals(10, (float) $beras['available']);

        // rencana lain melihat stok yang tersisa setelah pesanan
        $other = app(BomExplosionService::class)->requirements([['type' => 'menu', 'id' => $this->menuId(), 'qty' => 20]], $this->branch->id);
        $otherBeras = collect($other['rows'])->first(fn ($r) => $r['ingredient']->code === 'BB-050');
        $this->assertEquals(7, (float) $otherBeras['available']);

        $svc->unconfirm($plan->fresh());
        $this->assertEquals(0, (float) $stock()->reserved_quantity);
        $this->assertSame(PlanStatus::Draft, $plan->fresh()->status);
    }

    public function test_cannot_confirm_twice(): void
    {
        $plan = $this->plan([['menu', $this->menuId(), 5]]);
        $svc = app(ProductionPlanService::class);
        $svc->confirm($plan);

        $this->expectException(\RuntimeException::class);
        $svc->confirm($plan->fresh());
    }

    public function test_generate_purchase_request_is_idempotent(): void
    {
        $plan = $this->plan([['menu', $this->menuId(), 20]]);
        $svc = app(ProductionPlanService::class);

        $pr = $svc->generatePurchaseRequest($plan, null);
        $again = $svc->generatePurchaseRequest($plan, null);

        $this->assertSame($pr->id, $again->id);
        $this->assertCount(7, $pr->items);
        $this->assertNotNull($pr->number);
        // 20 porsi = skala 2: total belanja = 2 x HPP resep (84.460)
        $this->assertEquals(168920, (float) $pr->total_estimated);
        $beras = $pr->items->first(fn ($i) => $i->ingredient_id === $this->ing('BB-050')->id);
        $this->assertEquals(3, (float) $beras->qty);
    }

    public function test_no_purchase_request_when_stock_is_enough(): void
    {
        foreach ([['BB-050', 'kg'], ['BB-003', 'kg'], ['BB-014', 'kg'], ['BB-051', 'kg'], ['BB-052', 'L'], ['BB-022', 'L'], ['BB-053', 'butir']] as [$code, $u]) {
            $this->stockIn($code, '100', $u, '1000');
        }
        $plan = $this->plan([['menu', $this->menuId(), 20]]);

        $this->expectException(\RuntimeException::class);
        app(ProductionPlanService::class)->generatePurchaseRequest($plan, null);
    }

    public function test_purchase_request_approval_requires_manager_or_owner(): void
    {
        $plan = $this->plan([['menu', $this->menuId(), 20]]);
        $pr = app(ProductionPlanService::class)->generatePurchaseRequest($plan, null);
        $svc = app(PurchaseRequestService::class);

        $svc->submit($pr);

        $cashier = User::where('email', 'kasir@sapore.test')->firstOrFail();
        try {
            $svc->approve($pr->fresh(), $cashier);
            $this->fail('Kasir seharusnya tidak boleh menyetujui');
        } catch (AuthorizationException) {
            $this->assertSame('submitted', $pr->fresh()->status->value);
        }

        $owner = User::where('email', 'owner@sapore.test')->firstOrFail();
        $svc->approve($pr->fresh(), $owner);
        $this->assertSame('approved', $pr->fresh()->status->value);
        $this->assertSame($owner->id, $pr->fresh()->approved_by);
    }
}
