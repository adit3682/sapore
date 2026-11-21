<?php

namespace Tests\Feature;

use App\Filament\Pages\BusinessSettings;
use App\Models\Branch;
use App\Models\Menu;
use App\Models\User;
use App\Services\OrderReturnService;
use App\Services\OrderService;
use App\Services\ReceiptService;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\Access;
use Database\Seeders\SampleMenuSeeder;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private SettingsService $settings;
    private User $owner;
    private User $cashier;
    private Branch $a;
    private Branch $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);
        $this->seed(SampleMenuSeeder::class);
        config(['sapore.tax_rate' => 10, 'sapore.service_rate' => 5, 'sapore.food_cost_target' => 35]);

        $this->settings = app(SettingsService::class);
        $this->owner = User::where('email', 'owner@sapore.test')->firstOrFail();
        $this->cashier = User::where('email', 'kasir@sapore.test')->firstOrFail();
        $this->a = Branch::firstOrFail();
        $this->b = Branch::create(['name' => 'Cabang B']);
    }

    private function line(int $qty = 1): array
    {
        return [['type' => 'menu', 'id' => Menu::where('code', 'MN-042')->value('id'), 'qty' => $qty]];
    }

    public function test_falls_back_to_config_when_nothing_is_saved(): void
    {
        $this->assertSame('10', $this->settings->taxRate());
        $this->assertSame('5', $this->settings->serviceRate());
        $this->assertSame('35', $this->settings->foodCostTarget());
        $this->assertSame(['name' => 'SAPORÉ', 'footer' => 'Terima kasih atas kunjungan Anda', 'paper' => 80], $this->settings->receipt());
    }

    public function test_saved_settings_override_config_and_zero_is_a_valid_value(): void
    {
        $this->settings->setMany(['tax_rate' => '11', 'service_rate' => '0', 'food_cost_target' => '30.5']);

        $this->assertSame('11', $this->settings->taxRate());
        $this->assertSame('0', $this->settings->serviceRate());      // 0 = tanpa service, bukan "kosong"
        $this->assertSame('30.5', $this->settings->foodCostTarget());
    }

    public function test_branch_rate_beats_global_and_null_follows_global(): void
    {
        $this->settings->set('tax_rate', '11');
        $this->b->update(['tax_rate' => 8, 'service_rate' => 0]);

        $this->assertSame('8', $this->settings->taxRate($this->b->id));
        $this->assertSame('0', $this->settings->serviceRate($this->b->id));  // cabang B tanpa service
        $this->assertSame('11', $this->settings->taxRate($this->a->id));     // cabang A: ikut umum
        $this->assertSame('5', $this->settings->serviceRate($this->a->id));
        $this->assertSame('11', $this->settings->taxRate());
    }

    public function test_changing_a_setting_takes_effect_immediately_despite_cache(): void
    {
        $this->assertSame('10', $this->settings->taxRate());   // mengisi cache
        $this->settings->set('tax_rate', '12');

        $this->assertSame('12', $this->settings->taxRate());
    }

    public function test_orders_use_the_rates_of_their_branch(): void
    {
        $svc = app(OrderService::class);
        $this->settings->set('tax_rate', '11');
        $this->b->update(['tax_rate' => 8, 'service_rate' => 0]);

        // Kasir cabang A: 11% + 5%. 40.000 -> 4.400 + 2.000
        $orderA = $svc->create([], $this->line(), $this->cashier);
        $this->assertEquals(11, (float) $orderA->tax_rate);
        $this->assertEquals(4400, (float) $orderA->tax_amount);
        $this->assertEquals(2000, (float) $orderA->service_amount);
        $this->assertEquals(46400, (float) $orderA->total);

        // Owner pusat memilih cabang B: 8% + 0%. 40.000 -> 3.200 + 0
        $orderB = $svc->create(['branch_id' => $this->b->id], $this->line(), $this->owner);
        $this->assertEquals(8, (float) $orderB->tax_rate);
        $this->assertEquals(3200, (float) $orderB->tax_amount);
        $this->assertEquals(0, (float) $orderB->service_amount);
        $this->assertEquals(43200, (float) $orderB->total);
    }

    public function test_existing_orders_keep_their_rates_after_a_change(): void
    {
        $svc = app(OrderService::class);
        $paid = $svc->pay($svc->create([], $this->line(10), $this->cashier), 'qris', null, $this->cashier->id);   // 10% + 5%
        $this->assertEquals(460000, (float) $paid->total);

        $this->settings->setMany(['tax_rate' => '12', 'service_rate' => '7']);

        $this->assertEquals(10, (float) $paid->fresh()->tax_rate);
        $this->assertEquals(460000, (float) $paid->fresh()->total);

        // retur memakai tarif order, bukan tarif terbaru: 4 porsi -> pajak 10% (16.000), service 5% (8.000)
        $manager = User::where('email', 'manager@sapore.test')->firstOrFail();
        $ret = app(OrderReturnService::class)->create($paid->fresh(), [
            'uuid' => (string) Str::uuid(), 'reason' => 'Salah pesan',
            'items' => [['order_item_id' => $paid->items[0]->id, 'qty' => 4]],
        ], $manager);
        $this->assertEquals(184000, (float) $ret->total_refund);
    }

    public function test_food_cost_target_from_settings_drives_the_reports(): void
    {
        Carbon::setTestNow('2026-11-24 10:00:00');
        $svc = app(OrderService::class);
        $svc->pay($svc->create([], $this->line(10), $this->cashier), 'qris', null, $this->cashier->id);   // food cost 21,1%

        $from = Carbon::parse('2026-11-01')->startOfDay();
        $to = Carbon::parse('2026-11-30')->endOfDay();
        $reports = app(ReportService::class);

        $this->assertSame('Sesuai target', $reports->menuMargin($from, $to, null)['rows'][0]['status']);   // target 35%

        $this->settings->set('food_cost_target', '20');
        $this->assertSame('Di atas target', $reports->menuMargin($from, $to, null)['rows'][0]['status']);  // target 20%
        $this->assertSame('Di atas target', $reports->menuCost()['rows'][0]['status']);

        Carbon::setTestNow();
    }

    public function test_receipt_uses_saved_name_footer_and_paper(): void
    {
        $this->settings->setMany(['receipt_name' => 'Warung Maju', 'receipt_footer' => 'Sampai jumpa lagi', 'receipt_paper' => '58']);
        $svc = app(OrderService::class);
        $paid = $svc->pay($svc->create([], $this->line(), $this->cashier), 'qris', null, $this->cashier->id);

        $r = app(ReceiptService::class)->build($paid);

        $this->assertSame('Warung Maju', $r['business']);
        $this->assertSame('Sampai jumpa lagi', $r['footer']);
        $this->assertSame(58, $r['paper']);
    }

    public function test_only_the_owner_can_open_business_settings(): void
    {
        $this->assertTrue(Access::can($this->owner, 'settings', 'update'));
        $this->assertFalse(Access::can(User::where('email', 'manager@sapore.test')->first(), 'settings', 'view'));
        $this->assertFalse(Access::can($this->cashier, 'settings', 'view'));

        $this->actingAs($this->owner);
        $this->assertTrue(BusinessSettings::canAccess());
        $this->actingAs($this->cashier);
        $this->assertFalse(BusinessSettings::canAccess());
    }

    public function test_settings_page_saves_values(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(BusinessSettings::class)
            ->assertFormSet(['tax_rate' => '10', 'service_rate' => '5', 'receipt_paper' => 80])
            ->fillForm([
                'tax_rate' => 11, 'service_rate' => 0, 'food_cost_target' => 30,
                'receipt_name' => 'Warung Maju', 'receipt_footer' => 'Makasih', 'receipt_paper' => 58,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('11', $this->settings->taxRate());
        $this->assertSame('0', $this->settings->serviceRate());
        $this->assertSame('30', $this->settings->foodCostTarget());
        $this->assertSame(58, $this->settings->receipt()['paper']);
    }

    public function test_settings_page_validates_ranges(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(BusinessSettings::class)
            ->fillForm(['tax_rate' => 150, 'service_rate' => -1, 'food_cost_target' => 0])
            ->call('save')
            ->assertHasFormErrors(['tax_rate' => 'max', 'service_rate' => 'min', 'food_cost_target' => 'min']);

        $this->assertSame('10', $this->settings->taxRate());   // tidak ada yang tersimpan
    }
}
