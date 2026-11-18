<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Package;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use Database\Seeders\SampleMenuSeeder;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);
        $this->seed(SampleMenuSeeder::class);

        $this->cashier = User::where('email', 'kasir@sapore.test')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        config(['sapore.tax_rate' => 10, 'sapore.service_rate' => 5]);
    }

    private function menu(): Menu
    {
        return Menu::where('code', 'MN-042')->firstOrFail();      // Rp 40.000, resep yield 10
    }

    private function package(): Package
    {
        return Package::where('code', 'PKT-001')->firstOrFail();  // Rp 70.000 = 2 menu
    }

    private function ing(string $code): Ingredient
    {
        return Ingredient::where('code', $code)->firstOrFail();
    }

    private function qty(string $code): float
    {
        return (float) Stock::where(['ingredient_id' => $this->ing($code)->id, 'branch_id' => $this->branch->id])->value('quantity');
    }

    private function stockIn(string $code, string $qty, string $unit, string $price): void
    {
        app(GoodsReceiptService::class)->post(
            ['branch_id' => $this->branch->id, 'receipt_date' => '2026-11-21'],
            [['ingredient_id' => $this->ing($code)->id, 'qty' => $qty, 'unit_id' => Unit::where('symbol', $unit)->value('id'), 'unit_price' => $price]]
        );
    }

    private function create(array $lines, ?string $uuid = null): Order
    {
        return app(OrderService::class)->create(['uuid' => $uuid ?? (string) Str::uuid()], $lines, $this->cashier);
    }

    public function test_totals_include_tax_and_service(): void
    {
        // 2 menu (80.000) + 1 paket (70.000) = 150.000; pajak 10% = 15.000; service 5% = 7.500
        $calc = app(OrderService::class)->calculate([
            ['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 2],
            ['type' => 'package', 'id' => $this->package()->id, 'qty' => 1],
        ]);

        $this->assertSame('150000.00', $calc['subtotal']);
        $this->assertSame('15000.00', $calc['tax']);
        $this->assertSame('7500.00', $calc['service']);
        $this->assertSame('172500.00', $calc['total']);
    }

    public function test_tax_is_rounded_to_whole_rupiah(): void
    {
        $this->menu()->update(['selling_price' => 33333]);

        $calc = app(OrderService::class)->calculate([['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 1]]);

        $this->assertSame('3333.00', $calc['tax']);     // 3333,3
        $this->assertSame('1667.00', $calc['service']); // 1666,65
    }

    public function test_order_snapshots_price_and_cost_from_database(): void
    {
        $order = $this->create([['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 3]]);

        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertNotNull($order->number);
        $this->assertEquals(40000, (float) $order->items[0]->unit_price);
        $this->assertEquals(8446, (float) $order->items[0]->unit_cost);

        // harga berubah setelahnya: order lama tetap
        $this->menu()->update(['selling_price' => 99000]);
        $this->assertEquals(40000, (float) $order->fresh('items')->items[0]->unit_price);
    }

    public function test_pay_deducts_stock_through_bom(): void
    {
        $this->stockIn('BB-050', '10', 'kg', '14000'); // beras

        // 10 porsi = 1 batch resep: beras 1,5 kg, telur 5
        $order = $this->create([['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 10]]);
        $paid = app(OrderService::class)->pay($order, 'cash', '500000', $this->cashier->id);

        $this->assertSame(OrderStatus::Paid, $paid->status);
        $this->assertEquals(8.5, $this->qty('BB-050'));
        $this->assertEquals(-5, $this->qty('BB-053')); // telur tidak punya stok: kasir tetap jalan, saldo minus terlihat
        $this->assertSame('order', StockMovement::where('ingredient_id', $this->ing('BB-050')->id)->where('type', 'out')->first()->reference_type);
        $this->assertEquals(460000, (float) $paid->total);
        $this->assertEquals(40000, (float) $paid->change_amount);
    }

    public function test_package_deducts_stock_per_included_menu(): void
    {
        $this->stockIn('BB-050', '10', 'kg', '14000');

        // 5 paket x 2 menu = 10 porsi = beras 1,5 kg
        $order = $this->create([['type' => 'package', 'id' => $this->package()->id, 'qty' => 5]]);
        app(OrderService::class)->pay($order, 'qris', null, $this->cashier->id);

        $this->assertEquals(8.5, $this->qty('BB-050'));
    }

    public function test_pay_is_idempotent(): void
    {
        $this->stockIn('BB-050', '10', 'kg', '14000');
        $order = $this->create([['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 10]]);
        $svc = app(OrderService::class);

        $svc->pay($order, 'qris', null, $this->cashier->id);
        $again = $svc->pay($order->fresh(), 'qris', null, $this->cashier->id);

        $this->assertSame(OrderStatus::Paid, $again->status);
        $this->assertEquals(8.5, $this->qty('BB-050')); // hanya terpotong sekali
    }

    public function test_create_is_idempotent_by_uuid(): void
    {
        $uuid = (string) Str::uuid();
        $a = $this->create([['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 1]], $uuid);
        $b = $this->create([['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 5]], $uuid); // pengiriman ulang

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Order::count());
        $this->assertEquals(1, (float) $b->items[0]->qty);
    }

    public function test_cash_must_cover_total_and_gives_change(): void
    {
        $order = $this->create([['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 1]]); // total 46.000
        $svc = app(OrderService::class);

        try {
            $svc->pay($order, 'cash', '40000', $this->cashier->id);
            $this->fail('Seharusnya gagal: uang kurang');
        } catch (\RuntimeException) {
            $this->assertSame(OrderStatus::Open, $order->fresh()->status);
            $this->assertSame(0, StockMovement::where('reference_type', 'order')->count()); // tidak ada stok yang terpotong
        }

        $paid = $svc->pay($order->fresh(), 'cash', '50000', $this->cashier->id);
        $this->assertEquals(4000, (float) $paid->change_amount);
    }

    public function test_void_only_open_orders(): void
    {
        $svc = app(OrderService::class);

        $open = $this->create([['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 1]]);
        $svc->void($open);
        $this->assertSame(OrderStatus::Void, $open->fresh()->status);

        $paid = $this->create([['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 1]]);
        $svc->pay($paid, 'qris', null, $this->cashier->id);

        $this->expectException(\RuntimeException::class);
        $svc->void($paid->fresh());
    }

    public function test_inactive_item_cannot_be_sold(): void
    {
        $this->menu()->update(['is_active' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->create([['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 1]]);
    }

    public function test_replace_items_on_open_order(): void
    {
        $order = $this->create([['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 1]]);
        $updated = app(OrderService::class)->replaceItems($order, [['type' => 'menu', 'id' => $this->menu()->id, 'qty' => 4]]);

        $this->assertCount(1, $updated->items);
        $this->assertEquals(160000, (float) $updated->subtotal);
    }

    public function test_only_sales_roles_can_sell(): void
    {
        $svc = app(OrderService::class);

        $this->assertTrue($svc->canSell($this->cashier));
        $this->assertTrue($svc->canSell(User::where('email', 'owner@sapore.test')->first()));
        $this->assertFalse($svc->canSell(User::where('email', 'chef@sapore.test')->first()));
        $this->assertFalse($svc->canSell(User::where('email', 'inventory@sapore.test')->first()));
    }
}
