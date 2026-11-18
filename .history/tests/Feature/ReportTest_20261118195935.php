<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports\MenuCostReport;
use App\Filament\Pages\Reports\SalesDailyReport;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Services\StockService;
use Database\Seeders\SampleMenuSeeder;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Data uji:
 *  10-Nov: 10 porsi Nasi Goreng (Rp 400.000, HPP Rp 84.460)
 *  11-Nov: 5 Paket Berdua (Rp 350.000, HPP Rp 84.460) + waste 0,5 kg beras
 *  Stok awal beras 10 kg @ Rp 14.000. "Sekarang" = 12-Nov.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);
        $this->seed(SampleMenuSeeder::class);
        config(['sapore.tax_rate' => 10, 'sapore.service_rate' => 5, 'sapore.food_cost_target' => 35]);

        $this->branch = Branch::firstOrFail();
        $cashier = User::where('email', 'kasir@sapore.test')->firstOrFail();
        $orders = app(OrderService::class);

        Carbon::setTestNow('2026-11-08 08:00:00');
        app(GoodsReceiptService::class)->post(
            ['branch_id' => $this->branch->id, 'receipt_date' => '2026-11-08'],
            [['ingredient_id' => $this->ing('BB-050')->id, 'qty' => '10', 'unit_id' => Unit::where('symbol', 'kg')->value('id'), 'unit_price' => '14000']]
        );

        Carbon::setTestNow('2026-11-10 10:00:00');
        $o1 = $orders->create([], [['type' => 'menu', 'id' => Menu::where('code', 'MN-042')->value('id'), 'qty' => 10]], $cashier);
        $orders->pay($o1, 'qris', null, $cashier->id);

        Carbon::setTestNow('2026-11-11 15:00:00');
        $o2 = $orders->create([], [['type' => 'package', 'id' => Package::where('code', 'PKT-001')->value('id'), 'qty' => 5]], $cashier);
        $orders->pay($o2, 'qris', null, $cashier->id);
        app(StockService::class)->waste($this->ing('BB-050')->id, $this->branch->id, '0.5', Unit::where('symbol', 'kg')->value('id'), 'tumpah');

        Carbon::setTestNow('2026-11-12 09:00:00');
        $this->reports = app(ReportService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ing(string $code): Ingredient
    {
        return Ingredient::where('code', $code)->firstOrFail();
    }

    private function row(array $report, string $key, string $value): array
    {
        return collect($report['rows'])->firstWhere($key, $value) ?? $this->fail("Baris {$key}={$value} tidak ada");
    }

    private function periodStart(): Carbon
    {
        return Carbon::parse('2026-11-01')->startOfDay();
    }

    private function to(): Carbon
    {
        return Carbon::parse('2026-11-30')->endOfDay();
    }

    public function test_sales_daily(): void
    {
        $r = $this->reports->salesDaily($this->periodStart(), $this->to(), null);

        $this->assertCount(2, $r['rows']);
        $d1 = $r['rows'][0];
        $this->assertSame(1, $d1['orders']);
        $this->assertEquals(400000, (float) $d1['subtotal']);
        $this->assertEquals(40000, (float) $d1['tax']);
        $this->assertEquals(20000, (float) $d1['service']);
        $this->assertEquals(460000, (float) $d1['total']);
        $this->assertEquals(84460, (float) $d1['cost']);
        $this->assertEquals(315540, (float) $d1['margin']);
        $this->assertEquals(78.8, (float) $d1['margin_pct']);

        $this->assertEquals(350000, (float) $r['rows'][1]['subtotal']);
        $this->assertSame(2, $r['summary'][0]['value']);                 // jumlah order
        $this->assertEquals(750000, (float) $r['summary'][1]['value']);  // penjualan neto
    }

    public function test_sales_daily_respects_period_and_branch(): void
    {
        $oneDay = $this->reports->salesDaily(Carbon::parse('2026-11-11')->startOfDay(), Carbon::parse('2026-11-11')->endOfDay(), null);
        $this->assertCount(1, $oneDay['rows']);
        $this->assertEquals(350000, (float) $oneDay['rows'][0]['subtotal']);

        $other = Branch::create(['name' => 'Cabang Lain']);
        $this->assertCount(0, $this->reports->salesDaily($this->periodStart(), $this->to(), $other->id)['rows']);
    }

    public function test_menu_margin_and_food_cost_target(): void
    {
        $r = $this->reports->menuMargin($this->periodStart(), $this->to(), null);

        $menu = $this->row($r, 'name', 'Nasi Goreng Special');
        $this->assertSame('Menu', $menu['kind']);
        $this->assertEquals(10, (float) $menu['qty']);
        $this->assertEquals(400000, (float) $menu['revenue']);
        $this->assertEquals(21.1, (float) $menu['food_cost']);
        $this->assertSame('Sesuai target', $menu['status']);

        $pkg = $this->row($r, 'name', 'Paket Berdua');
        $this->assertEquals(24.1, (float) $pkg['food_cost']);

        $this->assertEquals(750000, (float) $r['summary'][0]['value']);
        $this->assertEquals(168920, (float) $r['summary'][1]['value']);
        $this->assertEquals(22.5, (float) $r['summary'][3]['value']);
        $this->assertSame('Nasi Goreng Special', $r['rows'][0]['name']); // urut penjualan terbesar

        config(['sapore.food_cost_target' => 22]);
        $strict = $this->reports->menuMargin($this->periodStart(), $this->to(), null);
        $this->assertSame('Sesuai target', $this->row($strict, 'name', 'Nasi Goreng Special')['status']);
        $this->assertSame('Di atas target', $this->row($strict, 'name', 'Paket Berdua')['status']);
        $this->assertSame('Di atas target', $strict['summary'][5]['value']);
    }

    public function test_menu_cost_report(): void
    {
        $r = $this->reports->menuCost();

        $menu = $this->row($r, 'code', 'MN-042');
        $this->assertEquals(8446, (float) $menu['cost']);
        $this->assertEquals(40000, (float) $menu['price']);
        $this->assertEquals(21.1, (float) $menu['food_cost']);

        $pkg = $this->row($r, 'code', 'PKT-001');
        $this->assertEquals(16892, (float) $pkg['cost']);
        $this->assertSame(0, $r['summary'][1]['value']);

        config(['sapore.food_cost_target' => 22]);
        $this->assertSame(1, $this->reports->menuCost()['summary'][1]['value']); // paket 24,1% > 22%
    }

    public function test_ingredient_usage(): void
    {
        $r = $this->reports->ingredientUsage($this->periodStart(), $this->to(), null);

        $beras = $this->row($r, 'code', 'BB-050');
        $this->assertEquals(3, (float) $beras['used']);
        $this->assertEquals(0.5, (float) $beras['waste']);
        $this->assertEquals(0, (float) $beras['adjustment']);
        $this->assertEquals(14.2, (float) $beras['waste_pct']);
        $this->assertEquals(49000, (float) $beras['cost']);       // 3 kg + 0,5 kg waste @ 14.000
        $this->assertEquals(7000, (float) $beras['waste_cost']);

        $this->assertEquals(10, (float) $this->row($r, 'code', 'BB-053')['used']); // telur: 5 + 5 butir

        $other = Branch::create(['name' => 'Cabang Lain']);
        $this->assertCount(0, $this->reports->ingredientUsage($this->periodStart(), $this->to(), $other->id)['rows']);
    }

    public function test_inventory_valuation(): void
    {
        $r = $this->reports->inventoryValuation(null);

        $beras = $this->row($r, 'code', 'BB-050');
        $this->assertEquals(6.5, (float) $beras['qty']);
        $this->assertEquals(91000, (float) $beras['value']);
        $this->assertSame('Rendah', $beras['status']);                 // 6,5 dari minimum 10

        $telur = $this->row($r, 'code', 'BB-053');
        $this->assertEquals(-10, (float) $telur['qty']);
        $this->assertEquals(0, (float) $telur['value']);               // stok minus tidak bernilai
        $this->assertSame('Kritis', $telur['status']);

        $this->assertEquals(91000, (float) $r['summary'][0]['value']);
    }

    public function test_forecast(): void
    {
        $r = $this->reports->forecast(null, 10, 7);

        $beras = $this->row($r, 'code', 'BB-050');
        $this->assertEquals(0.3, (float) $beras['avg_daily']);
        $this->assertEquals(2.1, (float) $beras['need']);
        $this->assertEquals(0, (float) $beras['shortage']);
        $this->assertSame('Cukup', $beras['status']);
        $this->assertEquals(21.6, (float) $beras['cover_days']);

        $ayam = $this->row($r, 'code', 'BB-003');                       // stok minus -> semua kebutuhan harus dibeli
        $this->assertEquals(1.12, (float) $ayam['shortage']);
        $this->assertEquals(50400, (float) $ayam['cost']);
        $this->assertSame('Perlu belanja', $ayam['status']);

        $this->assertSame(6, $r['summary'][0]['value']);

        // jauh setelah penjualan terakhir: tidak ada riwayat dalam jendela -> tidak ada perkiraan
        Carbon::setTestNow('2026-12-30 09:00:00');
        $this->assertCount(0, $this->reports->forecast(null, 7, 7)['rows']);
    }

    public function test_export_writes_readable_xlsx_with_real_numbers(): void
    {
        $report = $this->reports->salesDaily($this->periodStart(), $this->to(), null);
        $path = app(ReportExporter::class)->toFile($report);

        $this->assertFileExists($path);

        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);
        $all = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $all[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        $flat = collect($all)->map(fn ($r) => array_values($r));
        $this->assertTrue($flat->contains(fn ($r) => ($r[0] ?? null) === 'Tanggal' && ($r[2] ?? null) === 'Penjualan neto'));
        $this->assertTrue($flat->contains(fn ($r) => ($r[0] ?? null) === 'Penjualan neto' && (float) ($r[1] ?? 0) === 750000.0));
    }

    public function test_report_access_by_role(): void
    {
        $this->actingAs(User::where('email', 'owner@sapore.test')->first());
        $this->assertTrue(SalesDailyReport::canAccess());

        $this->actingAs(User::where('email', 'chef@sapore.test')->first());
        $this->assertFalse(SalesDailyReport::canAccess());   // omzet bukan untuk chef
        $this->assertTrue(MenuCostReport::canAccess());      // HPP boleh

        $this->actingAs(User::where('email', 'kasir@sapore.test')->first());
        $this->assertFalse(SalesDailyReport::canAccess());
        $this->assertFalse(MenuCostReport::canAccess());
    }
}