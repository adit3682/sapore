<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Services\GoodsReceiptService;
use Database\Seeders\SampleMenuSeeder;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Butuh: laravel/sanctum terpasang dan trait HasApiTokens di model User. */
class PosApiTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);
        $this->seed(SampleMenuSeeder::class);
        $this->cashier = User::where('email', 'kasir@sapore.test')->firstOrFail();

        $beras = Ingredient::where('code', 'BB-050')->firstOrFail();
        app(GoodsReceiptService::class)->post(
            ['branch_id' => Branch::value('id'), 'receipt_date' => '2026-11-21'],
            [['ingredient_id' => $beras->id, 'qty' => '10', 'unit_id' => Unit::where('symbol', 'kg')->value('id'), 'unit_price' => '14000']]
        );
    }

    private function berasQty(): float
    {
        return (float) Stock::where('ingredient_id', Ingredient::where('code', 'BB-050')->value('id'))->value('quantity');
    }

    private function payload(?string $uuid = null, array $extra = []): array
    {
        return array_merge([
            'uuid' => $uuid ?? (string) Str::uuid(),
            'table_label' => '07',
            'items' => [['type' => 'menu', 'id' => Menu::where('code', 'MN-042')->value('id'), 'qty' => 10]],
        ], $extra);
    }

    public function test_login_returns_token_and_rejects_bad_password(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'kasir@sapore.test', 'password' => 'password', 'device_name' => 'tablet-1'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'role', 'branch_id']])
            ->assertJsonPath('user.role', 'cashier');

        $this->postJson('/api/v1/auth/login', ['email' => 'kasir@sapore.test', 'password' => 'salah', 'device_name' => 'tablet-1'])
            ->assertStatus(422);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/catalog')->assertUnauthorized();
        $this->postJson('/api/v1/orders', $this->payload())->assertUnauthorized();
    }

    public function test_non_sales_role_is_forbidden(): void
    {
        Sanctum::actingAs(User::where('email', 'chef@sapore.test')->firstOrFail());

        $this->getJson('/api/v1/catalog')->assertForbidden();
        $this->postJson('/api/v1/orders', $this->payload())->assertForbidden();
    }

    public function test_catalog_lists_active_items_and_supports_updated_since(): void
    {
        Sanctum::actingAs($this->cashier);

        $res = $this->getJson('/api/v1/catalog')->assertOk();
        $names = collect($res->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Nasi Goreng Special'));
        $this->assertTrue($names->contains('Paket Berdua'));
        $serverTime = $res->json('server_time');

        // tidak ada perubahan setelah server_time
        $this->travel(1)->minutes();
        $this->getJson('/api/v1/catalog?updated_since='.urlencode(now()->toIso8601String()))
            ->assertOk()->assertJsonCount(0, 'data');

        // menu dinonaktifkan: muncul di sinkron berikutnya dengan is_active=false
        Menu::where('code', 'MN-042')->update(['is_active' => false]);
        $changed = $this->getJson('/api/v1/catalog?updated_since='.urlencode($serverTime))->assertOk();
        $item = collect($changed->json('data'))->firstWhere('code', 'MN-042');
        $this->assertFalse($item['is_active']);
    }

    public function test_create_and_pay_in_one_call_is_idempotent(): void
    {
        Sanctum::actingAs($this->cashier);
        $uuid = (string) Str::uuid();
        $body = $this->payload($uuid, ['pay' => ['method' => 'cash', 'amount_paid' => 500000]]);

        $this->postJson('/api/v1/orders', $body)
            ->assertCreated()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('total', '460000.00')
            ->assertJsonPath('change_amount', '40000.00');
        $this->assertEquals(8.5, $this->berasQty());

        // koneksi putus-nyambung: kirim ulang persis sama
        $this->postJson('/api/v1/orders', $body)->assertOk()->assertJsonPath('status', 'paid');
        $this->assertEquals(8.5, $this->berasQty()); // stok tidak terpotong dua kali
    }

    public function test_failed_payment_keeps_order_open_and_can_be_paid_later(): void
    {
        Sanctum::actingAs($this->cashier);
        $uuid = (string) Str::uuid();

        $this->postJson('/api/v1/orders', $this->payload($uuid, ['pay' => ['method' => 'cash', 'amount_paid' => 1000]]))
            ->assertStatus(422)
            ->assertJsonPath('order.status', 'open');
        $this->assertEquals(10, $this->berasQty());

        $this->postJson("/api/v1/orders/{$uuid}/pay", ['method' => 'qris'])
            ->assertOk()->assertJsonPath('status', 'paid');
        $this->assertEquals(8.5, $this->berasQty());
    }

    public function test_client_cannot_set_prices_and_input_is_validated(): void
    {
        Sanctum::actingAs($this->cashier);

        $body = $this->payload();
        $body['items'][0]['price'] = 1; // diabaikan
        $res = $this->postJson('/api/v1/orders', $body)->assertCreated();
        $this->assertSame('40000.00', $res->json('items.0.unit_price'));

        $this->postJson('/api/v1/orders', ['uuid' => 'bukan-uuid', 'items' => []])->assertStatus(422);
    }

    public function test_void_and_show(): void
    {
        Sanctum::actingAs($this->cashier);
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/orders', $this->payload($uuid))->assertCreated()->assertJsonPath('status', 'open');

        $this->getJson("/api/v1/orders/{$uuid}")->assertOk()->assertJsonPath('table_label', '07');
        $this->postJson("/api/v1/orders/{$uuid}/void")->assertOk()->assertJsonPath('status', 'void');
        $this->postJson("/api/v1/orders/{$uuid}/pay", ['method' => 'qris'])->assertStatus(422);
        $this->assertEquals(10, $this->berasQty());
    }
}
