<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Unit;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\SettingsService;
use Database\Seeders\SampleMenuSeeder;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Memastikan app mobile (API v1) selalu memakai tarif/struk dari Pengaturan Usaha di web. */
class ApiRatesTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);
        $this->seed(SampleMenuSeeder::class);
        config(['sapore.tax_rate' => 10, 'sapore.service_rate' => 5]);
        User::query()->update(['must_change_password' => false]);
        $this->cashier = User::where('email', 'kasir@sapore.test')->firstOrFail();

        app(GoodsReceiptService::class)->post(
            ['branch_id' => Branch::value('id'), 'receipt_date' => '2026-11-21'],
            [['ingredient_id' => Ingredient::where('code', 'BB-050')->value('id'), 'qty' => '10', 'unit_id' => Unit::where('symbol', 'kg')->value('id'), 'unit_price' => '14000']]
        );
        Sanctum::actingAs($this->cashier);
    }

    private function body(?string $uuid = null, array $extra = []): array
    {
        return array_merge([
            'uuid' => $uuid ?? (string) Str::uuid(),
            'items' => [['type' => 'menu', 'id' => Menu::where('code', 'MN-042')->value('id'), 'qty' => 10]], // subtotal 400.000
        ], $extra);
    }

    public function test_api_order_uses_general_settings_saved_in_web(): void
    {
        $res = $this->postJson('/api/v1/orders', $this->body())->assertCreated();
        $this->assertEquals(10, (float) $res->json('tax_rate'));
        $this->assertEquals(460000, (float) $res->json('total'));

        app(SettingsService::class)->setMany(['tax_rate' => '11', 'service_rate' => '0']);

        $res = $this->postJson('/api/v1/orders', $this->body())->assertCreated();
        $this->assertEquals(11, (float) $res->json('tax_rate'));
        $this->assertEquals(0, (float) $res->json('service_rate'));
        $this->assertEquals(44000, (float) $res->json('tax_amount'));
        $this->assertEquals(444000, (float) $res->json('total'));
    }

    public function test_branch_rate_beats_general_setting_for_api_orders(): void
    {
        app(SettingsService::class)->setMany(['tax_rate' => '11', 'service_rate' => '5']);
        Branch::firstOrFail()->update(['tax_rate' => 8, 'service_rate' => 0]);

        $res = $this->postJson('/api/v1/orders', $this->body())->assertCreated();
        $this->assertEquals(8, (float) $res->json('tax_rate'));
        $this->assertEquals(432000, (float) $res->json('total'));
    }

    public function test_open_order_keeps_its_rate_after_settings_change(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/orders', $this->body($uuid))->assertCreated();

        app(SettingsService::class)->setMany(['tax_rate' => '15', 'service_rate' => '9']);

        $this->getJson("/api/v1/orders/{$uuid}")->assertOk()->assertJsonPath('total', '460000.00');
        $this->postJson("/api/v1/orders/{$uuid}/pay", ['method' => 'cash', 'amount_paid' => 500000])
            ->assertOk()->assertJsonPath('total', '460000.00')->assertJsonPath('change_amount', '40000.00');
    }

    public function test_api_receipt_follows_receipt_settings(): void
    {
        app(SettingsService::class)->setMany(['receipt_name' => 'Warung Maju', 'receipt_footer' => 'Sampai jumpa lagi', 'receipt_paper' => '58']);
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/orders', $this->body($uuid, ['pay' => ['method' => 'cash', 'amount_paid' => 500000]]))->assertCreated();

        $this->getJson("/api/v1/orders/{$uuid}/receipt")->assertOk()
            ->assertJsonPath('data.business', 'Warung Maju')
            ->assertJsonPath('data.footer', 'Sampai jumpa lagi')
            ->assertJsonPath('data.paper', 58);
    }
}