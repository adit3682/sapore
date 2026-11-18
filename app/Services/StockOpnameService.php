<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\StockOpnameStatus;
use App\Models\Ingredient;
use App\Models\Stock;
use App\Models\StockOpname;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock Opname: hitung fisik vs stok sistem.
 *
 * start()   : snapshot stok sistem semua bahan aktif di cabang (satu opname Draft per cabang).
 * confirm() : bahan yang sudah dihitung dikoreksi lewat StockService (type=adjustment, reference=stock_opname)
 *             sebesar (fisik - snapshot). Memakai snapshot, bukan stok saat konfirmasi, agar
 *             pergerakan yang terjadi SETELAH penghitungan (penjualan, penerimaan) tidak ikut terhapus.
 *             Bahan yang belum dihitung dilewati.
 */
class StockOpnameService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    public function start(int $branchId, string $date, ?string $notes = null, ?int $userId = null): StockOpname
    {
        if (StockOpname::where('branch_id', $branchId)->where('status', StockOpnameStatus::Draft->value)->exists()) {
            throw new RuntimeException('Masih ada Stock Opname berstatus Draft di cabang ini. Konfirmasi atau batalkan dulu.');
        }

        return DB::transaction(function () use ($branchId, $date, $notes, $userId) {
            $opname = StockOpname::create([
                'branch_id' => $branchId,
                'opname_date' => $date,
                'status' => StockOpnameStatus::Draft,
                'notes' => $notes,
                'created_by' => $userId,
            ]);
            $opname->update(['number' => sprintf('SO-%s-%05d', $opname->opname_date->format('Ymd'), $opname->id)]);

            $onHand = Stock::where('branch_id', $branchId)->pluck('quantity', 'ingredient_id');

            foreach (Ingredient::where('is_active', true)->orderBy('code')->pluck('id') as $ingredientId) {
                $opname->items()->create([
                    'ingredient_id' => $ingredientId,
                    'system_qty' => $onHand[$ingredientId] ?? 0,
                ]);
            }

            return $opname;
        });
    }

    /** @return array{adjusted: int, unchanged: int, skipped: int} */
    public function confirm(StockOpname $opname, ?int $userId = null): array
    {
        return DB::transaction(function () use ($opname, $userId) {
            $opname = StockOpname::whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if ($opname->status !== StockOpnameStatus::Draft) {
                throw new RuntimeException('Hanya Stock Opname berstatus Draft yang bisa dikonfirmasi.');
            }

            $items = $opname->items()->with('ingredient')->get();
            if ($items->whereNotNull('physical_qty')->isEmpty()) {
                throw new RuntimeException('Belum ada bahan yang dihitung. Isi stok fisik minimal satu bahan.');
            }

            $adjusted = $unchanged = $skipped = 0;

            foreach ($items as $item) {
                if ($item->physical_qty === null) {
                    $skipped++;

                    continue;
                }

                $diff = bcsub((string) $item->physical_qty, (string) $item->system_qty, 4);
                $movementId = null;

                if (bccomp($diff, '0', 4) !== 0) {
                    $movement = $this->stock->record([
                        'ingredient_id' => $item->ingredient_id,
                        'branch_id' => $opname->branch_id,
                        'type' => MovementType::Adjustment,
                        'qty' => $diff,
                        'unit_id' => $item->ingredient->base_unit_id,
                        'reference_type' => 'stock_opname',
                        'reference_id' => $opname->id,
                        'notes' => 'Stock Opname '.$opname->number,
                        'created_by' => $userId,
                        'allow_negative' => true, // fisik adalah kebenaran; selisih boleh membuat saldo koreksi
                    ]);
                    $movementId = $movement->id;
                    $adjusted++;
                } else {
                    $unchanged++;
                }

                $item->update(['difference' => $diff, 'stock_movement_id' => $movementId]);
            }

            $opname->update([
                'status' => StockOpnameStatus::Confirmed,
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ]);

            return compact('adjusted', 'unchanged', 'skipped');
        });
    }

    public function cancel(StockOpname $opname): void
    {
        if ($opname->status !== StockOpnameStatus::Draft) {
            throw new RuntimeException('Hanya Stock Opname berstatus Draft yang bisa dibatalkan.');
        }
        $opname->update(['status' => StockOpnameStatus::Cancelled]);
    }
}
