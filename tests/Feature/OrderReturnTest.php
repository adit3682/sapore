<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\OrderReturnService;
use App\Services\OrderService;
use App\Services\ReportService;
use Database\Seeders\SampleMenuSeeder;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Resep RSP-018: 1 batch (10 porsi) memakai beras 1,5 kg. HPP 8.446/porsi, harga jual 40.000, pajak 10%, service 5%.
 * Order 10 porsi: neto 400.000, total 460.000. Stok awal beras 10 kg.
 */
class OrderReturnTest extends TestCase
{
    use RefreshDatabase;

    private Branch $a;
    private User $cashier;
    private User $manager;
    private User $owner;
    private OrderReturnService $returns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);
        $this->seed(SampleMenuSeeder::class);
        config(['sapore.tax_rate' => 10, 'sapore.service_rate' => 5]);

        $this->a = Branch::firstOrFail();
        $this->cashier = User::where('email', 'kasir@sapore.test')->firstOrFail();
        $this->manager = User::where('email', 'manager@sapore.test')->firstOrFail();
        $this->owner = User::where('email', 'owner@sapore.test')->firstOrFail();
        $this->returns = app(OrderReturnService::class);

        app(GoodsReceiptService::class)->post(
            ['branch_id' => $this->a->id, 'receipt_date' => '2026-11-22'],
            [['ingredient_id' => $this->beras()->id, 'qty' => '10', 'unit_id' => Unit::where('symbol', 'kg')->value('id'), 'unit_price' => '14000']]
        );
    }

    private function beras(): Ingredient
    {
        return Ingredient::where('code', 'BB-050')->firstOrFail();
    }

    private function berasQty(): float
    {
        return (float) Stock::where(['ingredient_id' => $this->beras()->id, 'branch_id' => $this->a->id])->value('quantity');
    }

    private function paidOrder(int $qty = 10): Order
    {
        $svc = app(OrderService::class);
        $order = $svc->create([], [['type' => 'menu', 'id' => Menu::where('code', 'MN-042')->value('id'), 'qty' => $qty]], $this->cashier);

        return $svc->pay($order, 'qris', null, $this->cashier->id);
    }

    private function payload(Order $order, string $qty, array $over = []): array
    {
        return $over + [
            'uuid' => (string) Str::uuid(),
            'items' => [['order_item_id' => $order->items[0]->id, 'qty' => $qty]],
            'mode' => 'waste',
            'reason' => 'Pelanggan komplain rasa',
        ];
    }

    public function test_partial_return_in_waste_mode(): void
    {
        $order = $this->paidOrder();
        $this->assertEquals(8.5, $this->berasQty());

        $ret = $this->returns->create($order, $this->payload($order, '4'), $this->manager);

        $this->assertStringStartsWith('RTN-', $ret->number);
        $this->assertEquals(160000, (float) $ret->subtotal_refund);
        $this->assertEquals(16000, (float) $ret->tax_refund);
        $this->assertEquals(8000, (float) $ret->service_refund);
        $this->assertEquals(184000, (float) $ret->total_refund);
        $this->assertEquals(33784, (float) $ret->cost_refund);          // 4 x HPP 8.446
        $this->assertSame('qris', $ret->refund_method);                  // bawaan: metode bayar order

        $order = $order->fresh();
        $this->assertEquals(184000, (float) $order->refunded_total);
        $this->assertSame('partial', $order->returnState());

        // stok bersih tidak berubah (bahan kembali lalu dibuang) tetapi tercatat sebagai return + waste
        $this->assertEquals(8.5, $this->berasQty());
        $moves = StockMovement::where('ingredient_id', $this->beras()->id)->where('reference_type', 'order_return')->get();
        $this->assertEquals(0.6, (float) $moves->firstWhere('type.value', 'return')->qty_base);
        $this->assertEquals(-0.6, (float) $moves->firstWhere('type.value', 'waste')->qty_base);
    }

    public function test_restock_mode_returns_ingredients_to_stock(): void
    {
        $order = $this->paidOrder();

        $this->returns->create($order, $this->payload($order, '4', ['mode' => 'restock']), $this->manager);

        $this->assertEquals(9.1, $this->berasQty());                      // 8,5 + 0,6
        $this->assertSame(0, StockMovement::where('reference_type', 'order_return')->where('type', 'waste')->count());
    }

    public function test_cannot_return_more_than_remaining(): void
    {
        $order = $this->paidOrder();
        $this->returns->create($order, $this->payload($order, '4'), $this->manager);

        $this->assertSame('6.00', $this->returns->remaining($order->fresh())[$order->items[0]->id]);

        try {
            $this->returns->create($order->fresh(), $this->payload($order, '7'), $this->manager);
            $this->fail('Retur melebihi sisa harus ditolak');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('melebihi', $e->getMessage());
        }

        $this->returns->create($order->fresh(), $this->payload($order, '6'), $this->manager); // sisa persis: boleh
        $this->assertEquals(460000, (float) $order->fresh()->refunded_total);
        $this->assertSame('full', $order->fresh()->returnState());
    }

    public function test_final_return_settles_exact_totals_without_rounding_drift(): void
    {
        Menu::where('code', 'MN-042')->update(['selling_price' => 33333]);
        $order = $this->paidOrder(3);
        $this->assertEquals(114999, (float) $order->total); // 99.999 + pajak 10.000 + service 5.000

        $totals = [];
        for ($i = 0; $i < 3; $i++) {
            $totals[] = (float) $this->returns->create($order->fresh(), $this->payload($order, '1'), $this->manager)->total_refund;
        }

        $this->assertSame([38333.0, 38333.0, 38333.0], $totals);
        $this->assertEquals(114999, (float) $order->fresh()->refunded_total);   // persis sama dengan total order
        $this->assertEquals(10000, (float) OrderReturn::where('order_id', $order->id)->sum('tax_refund'));
        $this->assertEquals(5000, (float) OrderReturn::where('order_id', $order->id)->sum('service_refund'));
    }

    public function test_only_owner_and_manager_of_the_same_branch(): void
    {
        $order = $this->paidOrder();

        try {
            $this->returns->create($order, $this->payload($order, '1'), $this->cashier);
            $this->fail('Kasir tidak boleh meretur');
        } catch (AuthorizationException) {
        }

        $other = Branch::create(['name' => 'Cabang B']);
        $managerB = new User();
        $managerB->forceFill(['name' => 'M B', 'email' => 'mb@sapore.test', 'password' => bcrypt('x'), 'role' => 'manager',
            'branch_id' => $other->id, 'is_active' => true])->save();
        try {
            $this->returns->create($order, $this->payload($order, '1'), $managerB);
            $this->fail('Manager cabang lain tidak boleh meretur');
        } catch (AuthorizationException) {
        }

        $this->assertSame(0, OrderReturn::count());
        $this->returns->create($order, $this->payload($order, '1'), $this->owner); // Owner pusat: boleh
        $this->assertSame(1, OrderReturn::count());
    }

    public function test_only_paid_orders_can_be_returned(): void
    {
        $open = app(OrderService::class)->create([], [['type' => 'menu', 'id' => Menu::where('code', 'MN-042')->value('id'), 'qty' => 1]], $this->cashier);

        $this->expectException(\RuntimeException::class);
        $this->returns->create($open, ['uuid' => (string) Str::uuid(), 'items' => [['order_item_id' => $open->items[0]->id, 'qty' => 1]], 'reason' => 'tes tes'], $this->manager);
    }

    public function test_same_uuid_is_processed_once(): void
    {
        $order = $this->paidOrder();
        $payload = $this->payload($order, '4', ['mode' => 'restock']);

        $a = $this->returns->create($order, $payload, $this->manager);
        $b = $this->returns->create($order->fresh(), $payload, $this->manager);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, OrderReturn::count());
        $this->assertEquals(9.1, $this->berasQty());                       // bahan tidak kembali dua kali
        $this->assertEquals(184000, (float) $order->fresh()->refunded_total);
    }

    public function test_input_validation(): void
    {
        $order = $this->paidOrder();
        $other = $this->paidOrder(1);

        $bad = [
            'mode salah' => $this->payload($order, '1', ['mode' => 'buang']),
            'alasan kosong' => $this->payload($order, '1', ['reason' => ' ']),
            'qty nol' => $this->payload($order, '0'),
            'tanpa item' => $this->payload($order, '1', ['items' => []]),
            'item order lain' => $this->payload($order, '1', ['items' => [['order_item_id' => $other->items[0]->id, 'qty' => 1]]]),
            'metode salah' => $this->payload($order, '1', ['refund_method' => 'kripto']),
        ];

        foreach ($bad as $label => $payload) {
            try {
                $this->returns->create($order, $payload, $this->manager);
                $this->fail("Seharusnya ditolak: {$label}");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame(0, OrderReturn::count());
        $this->assertEquals(0, (float) $order->fresh()->refunded_total);
    }

    public function test_reports_subtract_returns_on_the_return_date(): void
    {
        Carbon::setTestNow('2026-11-23 10:00:00');
        $order = $this->paidOrder();
        $this->returns->create($order, $this->payload($order, '4'), $this->manager);   // retur di hari yang sama

        $reports = app(ReportService::class);
        $from = Carbon::parse('2026-11-01')->startOfDay();
        $to = Carbon::parse('2026-11-30')->endOfDay();

        $sales = $reports->salesDaily($from, $to, null);
        $day = $sales['rows'][0];
        $this->assertEquals(240000, (float) $day['subtotal']);   // 400.000 - 160.000
        $this->assertEquals(24000, (float) $day['tax']);
        $this->assertEquals(276000, (float) $day['total']);      // 460.000 - 184.000
        $this->assertEquals(50676, (float) $day['cost']);        // 84.460 - 33.784
        $this->assertEquals(189324, (float) $day['margin']);
        $this->assertEquals(160000, (float) $day['returns']);
        $this->assertEquals(1, $day['orders']);

        $margin = $reports->menuMargin($from, $to, null);
        $this->assertEquals(6, (float) $margin['rows'][0]['qty']);
        $this->assertEquals(240000, (float) $margin['rows'][0]['revenue']);
        $this->assertEquals(50676, (float) $margin['rows'][0]['cost']);

        // pemakaian bahan: terpakai neto 0,9 kg (1,5 - 0,6) dan waste 0,6 kg
        $usage = collect($reports->ingredientUsage($from, $to, null)['rows'])->firstWhere('code', 'BB-050');
        $this->assertEquals(0.9, (float) $usage['used']);
        $this->assertEquals(0.6, (float) $usage['waste']);
        $this->assertEquals(8400, (float) $usage['waste_cost']);

        Carbon::setTestNow();
    }

    public function test_return_on_a_later_day_shows_as_negative_sales_that_day(): void
    {
        Carbon::setTestNow('2026-11-23 10:00:00');
        $order = $this->paidOrder();
        Carbon::setTestNow('2026-11-24 09:00:00');
        $this->returns->create($order, $this->payload($order, '4'), $this->manager);

        $rows = app(ReportService::class)->salesDaily(Carbon::parse('2026-11-01')->startOfDay(), Carbon::parse('2026-11-30')->endOfDay(), null)['rows'];

        $this->assertCount(2, $rows);
        $this->assertEquals(400000, (float) $rows[0]['subtotal']);   // hari penjualan tidak berubah
        $this->assertSame(0, $rows[1]['orders']);
        $this->assertEquals(-160000, (float) $rows[1]['subtotal']);  // retur mengurangi hari retur

        Carbon::setTestNow();
    }
}
