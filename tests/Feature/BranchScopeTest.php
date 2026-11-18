<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports\SalesDailyReport;
use App\Filament\Resources\GoodsReceiptResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\ProductionPlanResource;
use App\Filament\Resources\StockOpnameResource;
use App\Filament\Resources\StockResource;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\ProductionPlan;
use App\Models\StockOpname;
use App\Models\Unit;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use Database\Seeders\SampleMenuSeeder;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cabang A = Cabang Sudirman (manager@, kasir@, chef@). Cabang B dibuat di sini.
 * Owner = akun pusat (tanpa cabang).
 */
class BranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $a;
    private Branch $b;
    private User $managerA;
    private User $managerB;
    private User $cashierA;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);
        $this->seed(SampleMenuSeeder::class);

        $this->a = Branch::firstOrFail();
        $this->b = Branch::create(['name' => 'Cabang B']);
        $this->managerA = User::where('email', 'manager@sapore.test')->firstOrFail();
        $this->cashierA = User::where('email', 'kasir@sapore.test')->firstOrFail();
        $this->owner = User::where('email', 'owner@sapore.test')->firstOrFail();

        $this->managerB = new User();
        $this->managerB->forceFill([
            'name' => 'Manager B', 'email' => 'managerb@sapore.test', 'password' => bcrypt('Rahasia123'),
            'role' => 'manager', 'branch_id' => $this->b->id, 'is_active' => true, 'must_change_password' => false,
        ])->save();
    }

    private function receipt(Branch $branch): void
    {
        app(GoodsReceiptService::class)->post(
            ['branch_id' => $branch->id, 'receipt_date' => '2026-11-22'],
            [['ingredient_id' => Ingredient::where('code', 'BB-050')->value('id'), 'qty' => '5', 'unit_id' => Unit::where('symbol', 'kg')->value('id'), 'unit_price' => '14000']]
        );
    }

    private function line(): array
    {
        return [['type' => 'menu', 'id' => Menu::where('code', 'MN-042')->value('id'), 'qty' => 1]];
    }

    public function test_goods_receipts_are_scoped(): void
    {
        $this->receipt($this->a);
        $this->receipt($this->b);

        $this->actingAs($this->managerA);
        $this->assertSame(1, GoodsReceiptResource::getEloquentQuery()->count());
        $this->actingAs($this->managerB);
        $this->assertSame(1, GoodsReceiptResource::getEloquentQuery()->count());
        $this->actingAs($this->owner);
        $this->assertSame(2, GoodsReceiptResource::getEloquentQuery()->count()); // pusat: gabungan
    }

    public function test_stock_rows_are_scoped(): void
    {
        $perBranch = Ingredient::count();

        $this->actingAs($this->managerA);
        $this->assertSame($perBranch, StockResource::getEloquentQuery()->count());
        $this->actingAs($this->owner);
        $this->assertSame($perBranch * 2, StockResource::getEloquentQuery()->count());
    }

    public function test_orders_plans_and_opnames_are_scoped(): void
    {
        $svc = app(OrderService::class);
        $svc->create([], $this->line(), $this->cashierA);
        $svc->create(['branch_id' => $this->b->id], $this->line(), $this->owner);

        ProductionPlan::create(['branch_id' => $this->a->id, 'plan_date' => '2026-11-23', 'status' => 'draft']);
        ProductionPlan::create(['branch_id' => $this->b->id, 'plan_date' => '2026-11-23', 'status' => 'draft']);
        StockOpname::create(['branch_id' => $this->b->id, 'opname_date' => '2026-11-23', 'status' => 'draft']);

        $this->actingAs($this->managerA);
        $this->assertSame(1, OrderResource::getEloquentQuery()->count());
        $this->assertSame(1, ProductionPlanResource::getEloquentQuery()->count());
        $this->assertSame(0, StockOpnameResource::getEloquentQuery()->count());   // opname cabang B tidak terlihat

        $this->actingAs($this->managerB);
        $this->assertSame(1, OrderResource::getEloquentQuery()->count());
        $this->assertSame(1, StockOpnameResource::getEloquentQuery()->count());

        $this->actingAs($this->owner);
        $this->assertSame(2, OrderResource::getEloquentQuery()->count());
        $this->assertSame(2, ProductionPlanResource::getEloquentQuery()->count());
    }

    public function test_other_branch_record_is_not_found_by_id(): void
    {
        $planB = ProductionPlan::create(['branch_id' => $this->b->id, 'plan_date' => '2026-11-23', 'status' => 'draft']);

        $this->actingAs($this->managerA);
        $this->assertNull(ProductionPlanResource::getEloquentQuery()->find($planB->id)); // URL langsung pun 404

        $this->actingAs($this->owner);
        $this->assertNotNull(ProductionPlanResource::getEloquentQuery()->find($planB->id));
    }

    public function test_account_without_branch_sees_nothing(): void
    {
        $this->receipt($this->a);

        $orphan = User::where('email', 'chef@sapore.test')->firstOrFail();
        $orphan->forceFill(['branch_id' => null])->save();

        $this->actingAs($orphan->fresh());
        $this->assertSame(0, GoodsReceiptResource::getEloquentQuery()->count());
        $this->assertSame(0, StockResource::getEloquentQuery()->count());
    }

    public function test_central_owner_picks_branch_but_cashier_cannot(): void
    {
        $svc = app(OrderService::class);

        $byOwner = $svc->create(['branch_id' => $this->b->id], $this->line(), $this->owner);
        $this->assertSame($this->b->id, $byOwner->branch_id);

        // kasir cabang A mengirim cabang B: diabaikan, tetap cabang A
        $byCashier = $svc->create(['branch_id' => $this->b->id], $this->line(), $this->cashierA);
        $this->assertSame($this->a->id, $byCashier->branch_id);
    }

    public function test_central_owner_must_choose_an_active_branch(): void
    {
        try {
            app(OrderService::class)->create([], $this->line(), $this->owner);
            $this->fail('Owner pusat harus memilih cabang');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Pilih cabang', $e->getMessage());
        }

        $this->b->update(['is_active' => false]);
        $this->expectException(\RuntimeException::class);
        app(OrderService::class)->create(['branch_id' => $this->b->id], $this->line(), $this->owner);
    }

    public function test_report_branch_filter_cannot_be_overridden_by_branch_accounts(): void
    {
        $filters = function (User $user, array $data) {
            $this->actingAs($user);
            $page = new SalesDailyReport();
            $page->data = $data;
            $m = new \ReflectionMethod($page, 'filters');
            $m->setAccessible(true);

            return $m->invoke($page);
        };

        // manager A mencoba memaksa cabang B lewat state form: tetap cabang A
        $this->assertSame($this->a->id, $filters($this->managerA, ['branch_id' => $this->b->id])['branch_id']);
        // owner pusat: bebas memilih atau semua
        $this->assertSame($this->b->id, $filters($this->owner, ['branch_id' => $this->b->id])['branch_id']);
        $this->assertNull($filters($this->owner, ['branch_id' => null])['branch_id']);
    }
}
