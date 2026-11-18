<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Services\GoodsReceiptService;
use App\Services\StockService;
use App\Support\StockStatus;
use Database\Seeders\SampleRecipeSeeder;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Ingredient $beras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->seed(SampleRecipeSeeder::class);

        $this->branch = Branch::firstOrFail();
        $this->beras = Ingredient::where('code', 'BB-050')->firstOrFail(); // satuan dasar kg, avg 14.000
    }

    private function unit(string $symbol): Unit
    {
        return Unit::where('symbol', $symbol)->firstOrFail();
    }

    private function receive(string $qty, string $unit, string $price, ?string $uuid = null)
    {
        return app(GoodsReceiptService::class)->post([
            'branch_id' => $this->branch->id, 'receipt_date' => '2026-11-18', 'uuid' => $uuid,
        ], [[
            'ingredient_id' => $this->beras->id, 'qty' => $qty, 'unit_id' => $this->unit($unit)->id, 'unit_price' => $price,
        ]]);
    }

    private function qty(): float
    {
        return (float) Stock::where(['ingredient_id' => $this->beras->id, 'branch_id' => $this->branch->id])->value('quantity');
    }

    public function test_receipt_adds_stock_and_uses_moving_average(): void
    {
        $this->receive('10', 'kg', '14000');
        $this->assertEquals(10, $this->qty());
        $this->assertEquals(14000, (float) $this->beras->fresh()->avg_cost); // stok awal 0: harga baru = rata-rata

        $this->receive('10', 'kg', '16000');
        $this->assertEquals(20, $this->qty());
        $this->assertEquals(15000, (float) $this->beras->fresh()->avg_cost);
        $this->assertEquals(16000, (float) $this->beras->fresh()->last_cost);
    }

    public function test_receipt_in_other_unit_is_priced_per_base_unit(): void
    {
        // 5000 g @ Rp 14 per gram = 5 kg @ Rp 14.000 per kg
        $this->receive('5000', 'g', '14');

        $this->assertEquals(5, $this->qty());
        $this->assertEquals(14000, (float) $this->beras->fresh()->avg_cost);
    }

    public function test_receipt_recalculates_recipe_cost(): void
    {
        $this->receive('10', 'kg', '14000');
        $this->receive('10', 'kg', '16000'); // avg 15.000; resep pakai 1,5 kg -> +1.500

        $this->assertEquals(85960, (float) Recipe::where('code', 'RSP-018')->value('total_cost'));
    }

    public function test_every_change_has_a_movement_and_balance(): void
    {
        $r = $this->receive('10', 'kg', '14000');
        $stock = app(StockService::class);
        $stock->waste($this->beras->id, $this->branch->id, '500', $this->unit('g')->id, 'tumpah');
        $stock->adjust($this->beras->id, $this->branch->id, '-1', $this->unit('kg')->id, 'selisih hitung');

        $this->assertEquals(8.5, $this->qty());
        $this->assertSame(3, StockMovement::where('ingredient_id', $this->beras->id)->count());
        // saldo gerakan terakhir = stok sekarang; jumlah qty_base = stok
        $this->assertEquals(8.5, (float) StockMovement::where('ingredient_id', $this->beras->id)->latest('id')->value('balance_after'));
        $this->assertEquals(8.5, (float) StockMovement::where('ingredient_id', $this->beras->id)->sum('qty_base'));
        $this->assertSame('goods_receipt', StockMovement::where('type', MovementType::In->value)->first()->reference_type);
        $this->assertNotNull($r->number);
    }

    public function test_cannot_go_negative_unless_allowed(): void
    {
        $this->receive('2', 'kg', '14000');
        $stock = app(StockService::class);

        try {
            $stock->waste($this->beras->id, $this->branch->id, '3', $this->unit('kg')->id, 'x');
            $this->fail('Seharusnya melempar InsufficientStockException');
        } catch (InsufficientStockException) {
            $this->assertEquals(2, $this->qty()); // tidak ada yang berubah
        }

        $stock->issue($this->beras->id, $this->branch->id, '3', $this->unit('kg')->id, ['allow_negative' => true]);
        $this->assertEquals(-1, $this->qty());
    }

    public function test_same_uuid_is_not_processed_twice(): void
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $stock = app(StockService::class);
        $this->receive('10', 'kg', '14000');

        $stock->record([
            'uuid' => $uuid, 'ingredient_id' => $this->beras->id, 'branch_id' => $this->branch->id,
            'type' => MovementType::Out, 'qty' => '1', 'unit_id' => $this->unit('kg')->id,
        ]);
        $stock->record([
            'uuid' => $uuid, 'ingredient_id' => $this->beras->id, 'branch_id' => $this->branch->id,
            'type' => MovementType::Out, 'qty' => '1', 'unit_id' => $this->unit('kg')->id,
        ]);

        $this->assertEquals(9, $this->qty());
    }

    public function test_same_receipt_uuid_is_idempotent(): void
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $a = $this->receive('10', 'kg', '14000', $uuid);
        $b = $this->receive('10', 'kg', '14000', $uuid);

        $this->assertSame($a->id, $b->id);
        $this->assertEquals(10, $this->qty());
    }

    public function test_stock_rows_are_created_for_new_ingredients(): void
    {
        $this->assertDatabaseHas('stocks', ['ingredient_id' => $this->beras->id, 'branch_id' => $this->branch->id]);
    }

    public function test_status_thresholds(): void
    {
        $this->assertSame(StockStatus::CRITICAL, StockStatus::for(3.2, 8));
        $this->assertSame(StockStatus::CRITICAL, StockStatus::for(4, 10));
        $this->assertSame(StockStatus::LOW, StockStatus::for(6, 10));
        $this->assertSame(StockStatus::OK, StockStatus::for(9, 6));
        $this->assertSame(StockStatus::OK, StockStatus::for(0, 0));
    }
}
