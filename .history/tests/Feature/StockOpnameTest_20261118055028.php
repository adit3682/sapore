<?php

namespace Tests\Feature;

use App\Enums\StockOpnameStatus;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockOpnameItem;
use App\Models\Unit;
use App\Services\GoodsReceiptService;
use App\Services\StockOpnameService;
use App\Services\StockService;
use App\Support\StockQueries;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockOpnameTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);
        $this->branch = Branch::firstOrFail();

        $this->receive('BB-050', '10', 'kg', '14000'); // beras 10 kg
    }

    private function ing(string $code): Ingredient
    {
        return Ingredient::where('code', $code)->firstOrFail();
    }

    private function unit(string $symbol): Unit
    {
        return Unit::where('symbol', $symbol)->firstOrFail();
    }

    private function receive(string $code, string $qty, string $unit, string $price): void
    {
        app(GoodsReceiptService::class)->post(
            ['branch_id' => $this->branch->id, 'receipt_date' => '2026-11-20'],
            [['ingredient_id' => $this->ing($code)->id, 'qty' => $qty, 'unit_id' => $this->unit($unit)->id, 'unit_price' => $price]]
        );
    }

    private function qty(string $code): float
    {
        return (float) Stock::where(['ingredient_id' => $this->ing($code)->id, 'branch_id' => $this->branch->id])->value('quantity');
    }

    private function recordCount($opname, string $code, ?string $physical): void
    {
        StockOpnameItem::where(['stock_opname_id' => $opname->id, 'ingredient_id' => $this->ing($code)->id])
            ->firstOrFail()->update(['physical_qty' => $physical]);
    }

    private function start()
    {
        return app(StockOpnameService::class)->start($this->branch->id, '2026-11-20', null, null);
    }

    public function test_start_snapshots_all_active_ingredients(): void
    {
        $op = $this->start();

        $this->assertSame(Ingredient::where('is_active', true)->count(), $op->items()->count());
        $this->assertEquals(10, (float) $op->items()->where('ingredient_id', $this->ing('BB-050')->id)->value('system_qty'));
        $this->assertEquals(0, (float) $op->items()->where('ingredient_id', $this->ing('BB-053')->id)->value('system_qty'));
        $this->assertNotNull($op->number);
    }

    public function test_only_one_draft_per_branch(): void
    {
        $this->start();

        $this->expectException(\RuntimeException::class);
        $this->start();
    }

    public function test_confirm_adjusts_counted_items_only(): void
    {
        $op = $this->start();
        $this->recordCount($op, 'BB-050', '8.5');  // sistem 10 -> -1,5
        $this->recordCount($op, 'BB-053', '12');   // sistem 0 -> +12
        $this->recordCount($op, 'BB-003', '0');    // sistem 0 -> sama
        // bahan lain tidak dihitung

        $avgBefore = (float) $this->ing('BB-053')->avg_cost;
        $result = app(StockOpnameService::class)->confirm($op, null);

        $this->assertSame(['adjusted' => 2, 'unchanged' => 1, 'skipped' => count(Ingredient::all()) - 3], $result);
        $this->assertEquals(8.5, $this->qty('BB-050'));
        $this->assertEquals(12, $this->qty('BB-053'));
        $this->assertEquals($avgBefore, (float) $this->ing('BB-053')->avg_cost); // penyesuaian tidak mengubah harga rata-rata

        $item = StockOpnameItem::where(['stock_opname_id' => $op->id, 'ingredient_id' => $this->ing('BB-050')->id])->first();
        $this->assertEquals(-1.5, (float) $item->difference);
        $this->assertSame('stock_opname', $item->movement->reference_type);
        $this->assertSame(StockOpnameStatus::Confirmed, $op->fresh()->status);
        $this->assertSame(2, StockMovement::where('reference_type', 'stock_opname')->count());
    }

    public function test_difference_is_relative_to_snapshot_not_current_stock(): void
    {
        $op = $this->start();                                   // snapshot beras = 10
        app(StockService::class)->waste($this->ing('BB-050')->id, $this->branch->id, '3', $this->unit('kg')->id, 'tumpah'); // sekarang 7
        $this->recordCount($op, 'BB-050', '9');                 // fisik 9 vs snapshot 10 => -1

        app(StockOpnameService::class)->confirm($op, null);

        $this->assertEquals(6, $this->qty('BB-050'));           // 7 + (-1); waste 3 setelah hitung tidak hilang
    }

    public function test_cannot_confirm_without_counts_or_twice(): void
    {
        $svc = app(StockOpnameService::class);
        $op = $this->start();

        try {
            $svc->confirm($op, null);
            $this->fail('Seharusnya gagal: belum ada yang dihitung');
        } catch (\RuntimeException) {
            $this->assertSame(StockOpnameStatus::Draft, $op->fresh()->status);
        }

        $this->recordCount($op, 'BB-050', '10');
        $svc->confirm($op, null);

        $this->expectException(\RuntimeException::class);
        $svc->confirm($op->fresh(), null);
    }

    public function test_cancel_and_restart(): void
    {
        $svc = app(StockOpnameService::class);
        $op = $this->start();
        $svc->cancel($op);

        $this->assertSame(StockOpnameStatus::Cancelled, $op->fresh()->status);
        $this->assertNotNull($this->start()->id); // draft baru boleh setelah dibatalkan
    }

    public function test_empty_input_means_not_counted(): void
    {
        $op = $this->start();
        $this->recordCount($op, 'BB-050', '');

        $this->assertNull(StockOpnameItem::where(['stock_opname_id' => $op->id, 'ingredient_id' => $this->ing('BB-050')->id])->value('physical_qty'));
    }

    public function test_low_stock_query_and_inventory_value(): void
    {
        // beras min 10, stok 10 -> tidak rendah; 6 bahan lain min > 0 dan stok 0 -> rendah
        $this->assertSame(6, StockQueries::low($this->branch->id)->count());

        app(StockService::class)->waste($this->ing('BB-050')->id, $this->branch->id, '4', $this->unit('kg')->id, 'x');
        $this->assertSame(7, StockQueries::low($this->branch->id)->count());

        // nilai = 6 kg beras x 14.000
        $this->assertSame('84000.00', StockQueries::inventoryValue($this->branch->id));
        $this->assertSame('Rp 4,2jt', StockQueries::compactRupiah('4200000'));
    }
}