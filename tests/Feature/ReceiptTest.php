<?php

namespace Tests\Feature;

use App\Filament\Pages\ReceiptPage;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Unit;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\OrderReturnService;
use App\Services\OrderService;
use App\Services\ReceiptService;
use Database\Seeders\SampleMenuSeeder;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);
        $this->seed(SampleMenuSeeder::class);
        config(['sapore.tax_rate' => 10, 'sapore.service_rate' => 5]);
        User::query()->update(['must_change_password' => false]); // tes API: akun seeder sudah dianggap berganti password

        Branch::firstOrFail()->update(['address' => 'Jl. Jenderal Sudirman 10', 'phone' => '0281-123456']);
        $this->cashier = User::where('email', 'kasir@sapore.test')->firstOrFail();
        $this->manager = User::where('email', 'manager@sapore.test')->firstOrFail();

        app(GoodsReceiptService::class)->post(
            ['branch_id' => Branch::value('id'), 'receipt_date' => '2026-11-22'],
            [['ingredient_id' => Ingredient::where('code', 'BB-050')->value('id'), 'qty' => '10', 'unit_id' => Unit::where('symbol', 'kg')->value('id'), 'unit_price' => '14000']]
        );
    }

    private function openOrder(int $qty = 10): Order
    {
        return app(OrderService::class)->create(['uuid' => (string) Str::uuid()], [['type' => 'menu', 'id' => Menu::where('code', 'MN-042')->value('id'), 'qty' => $qty]], $this->cashier);
    }

    private function paidOrder(int $qty = 10): Order
    {
        return app(OrderService::class)->pay($this->openOrder($qty), 'cash', '500000', $this->cashier->id);
    }

    private function returnPayload(Order $order, string $qty = '4'): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'items' => [['order_item_id' => $order->items[0]->id, 'qty' => $qty]],
            'mode' => 'waste',
            'reason' => 'Pesanan salah',
        ];
    }

    public function test_order_receipt_content(): void
    {
        $r = app(ReceiptService::class)->build($this->paidOrder());

        $this->assertSame('order', $r['kind']);
        $this->assertSame('SAPORÉ', $r['business']);
        $this->assertSame('Cabang Sudirman', $r['branch']);
        $this->assertSame('Jl. Jenderal Sudirman 10', $r['address']);
        $this->assertStringStartsWith('ORD-', $r['number']);
        $this->assertSame('Kasir Rina', $r['cashier']);
        $this->assertCount(1, $r['lines']);
        $this->assertSame('Nasi Goreng Special', $r['lines'][0]['name']);
        $this->assertSame('10', $r['lines'][0]['qty']);
        $this->assertSame('400000.00', $r['subtotal']);
        $this->assertSame('10', $r['tax_rate']);
        $this->assertSame('460000.00', $r['total']);
        $this->assertSame('Tunai', $r['payment_method']);
        $this->assertSame('40000.00', $r['change']);
        $this->assertSame(80, $r['paper']);
        $this->assertSame([], $r['returns']);
        $this->assertSame('460000.00', $r['net_total']);

        config(['sapore.receipt.paper' => 58, 'sapore.receipt.footer' => 'Sampai jumpa']);
        $r = app(ReceiptService::class)->build($this->paidOrder(1));
        $this->assertSame(58, $r['paper']);
        $this->assertSame('Sampai jumpa', $r['footer']);
    }

    public function test_receipt_requires_a_paid_order(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ReceiptService::class)->build($this->openOrder());
    }

    public function test_receipt_of_returned_order_shows_returns_and_net_total(): void
    {
        $order = $this->paidOrder();
        app(OrderReturnService::class)->create($order, $this->returnPayload($order), $this->manager);

        $r = app(ReceiptService::class)->build($order->fresh());

        $this->assertCount(1, $r['returns']);
        $this->assertSame('184000.00', $r['refunded_total']);
        $this->assertSame('276000.00', $r['net_total']);
    }

    public function test_return_receipt_content(): void
    {
        $order = $this->paidOrder();
        $ret = app(OrderReturnService::class)->create($order, $this->returnPayload($order), $this->manager);

        $r = app(ReceiptService::class)->buildReturn($ret);

        $this->assertSame('return', $r['kind']);
        $this->assertSame($ret->number, $r['number']);
        $this->assertSame($order->number, $r['order_number']);
        $this->assertSame('184000.00', $r['total']);
        $this->assertSame('Dibuang', $r['mode']);
        $this->assertSame('Pesanan salah', $r['reason']);
        $this->assertSame('Tunai', $r['refund_method']);
        $this->assertSame('Manager Dewi', $r['cashier']);
    }

    public function test_receipt_page_access_and_url(): void
    {
        $this->actingAs($this->cashier);
        $this->assertTrue(ReceiptPage::canAccess());
        $this->assertStringContainsString('/struk/order/5', ReceiptPage::getUrl(['type' => 'order', 'id' => 5]));

        $this->actingAs(User::where('email', 'chef@sapore.test')->first());
        $this->assertFalse(ReceiptPage::canAccess());
    }

    public function test_api_receipt_endpoint(): void
    {
        $paid = $this->paidOrder();
        $open = $this->openOrder();
        Sanctum::actingAs($this->cashier);

        $this->getJson("/api/v1/orders/{$paid->uuid}/receipt")
            ->assertOk()
            ->assertJsonPath('data.number', $paid->number)
            ->assertJsonPath('data.total', '460000.00')
            ->assertJsonPath('data.branch', 'Cabang Sudirman');

        $this->getJson("/api/v1/orders/{$open->uuid}/receipt")->assertStatus(422);
    }

    public function test_api_return_flow(): void
    {
        $order = $this->paidOrder();
        $body = $this->returnPayload($order);

        // Kasir tidak boleh
        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/v1/orders/{$order->uuid}/returns", $body)->assertForbidden();

        // Manager boleh; kirim ulang uuid yang sama aman
        Sanctum::actingAs($this->manager);
        $this->postJson("/api/v1/orders/{$order->uuid}/returns", $body)
            ->assertCreated()
            ->assertJsonPath('refunded_total', '184000.00')
            ->assertJsonPath('items.0.returned_qty', '4.00')
            ->assertJsonPath('returns.0.mode', 'waste');
        $this->postJson("/api/v1/orders/{$order->uuid}/returns", $body)->assertOk();
        $this->assertSame(1, OrderReturn::count());

        // melebihi sisa -> 422 dengan pesan
        $over = $this->returnPayload($order, '7');
        $this->postJson("/api/v1/orders/{$order->uuid}/returns", $over)
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'melebihi'));

        // detail order memuat id item (untuk klien) dan struk memuat retur
        $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk()->assertJsonPath('items.0.order_item_id', $order->items[0]->id);
        $this->getJson("/api/v1/orders/{$order->uuid}/receipt")->assertOk()->assertJsonPath('data.net_total', '276000.00');
    }

    public function test_api_pay_endpoint_still_works(): void
    {
        // regresi: OrderController::find() sebelumnya memakai tipe parameter yang salah untuk /pay
        $open = $this->openOrder(1);
        Sanctum::actingAs($this->cashier);

        $this->postJson("/api/v1/orders/{$open->uuid}/pay", ['method' => 'qris'])->assertOk()->assertJsonPath('status', 'paid');
    }
}
