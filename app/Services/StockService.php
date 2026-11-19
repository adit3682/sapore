<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Ingredient;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SATU-SATUNYA pintu perubahan stok. Setiap perubahan = satu baris append-only di stock_movements,
 * dan stocks.quantity hanyalah cache yang diperbarui dalam transaksi yang sama.
 *
 * Input `record()`:
 *   ingredient_id, branch_id, type (MovementType|string), qty, unit_id        (wajib)
 *   unit_cost      biaya per SATUAN DASAR (wajib untuk type=in)
 *   uuid           kunci idempotensi; kirim ulang uuid yang sama = tidak diproses dua kali
 *   reference_type, reference_id, notes, created_by, allow_negative
 *
 * Tanda kuantitas: in/return = +, out/waste = -, adjustment = sesuai tanda qty yang dikirim.
 *
 * Catatan: setelah `in` yang mengubah avg_cost, pemanggil wajib menghitung ulang HPP
 * (RecipeCostService::recalculateForIngredient) SESUDAH transaksi terluarnya commit.
 */
class StockService
{
    public function __construct(private readonly UnitConverter $converter)
    {
    }

    /** @param array<string, mixed> $in */
    public function record(array $in): StockMovement
    {
        $type = $in['type'] instanceof MovementType ? $in['type'] : MovementType::from($in['type']);
        $uuid = $in['uuid'] ?? (string) Str::uuid();

        if ($existing = StockMovement::where('uuid', $uuid)->first()) {
            return $existing; // idempotent
        }

        return DB::transaction(function () use ($in, $type, $uuid) {
            // kunci baris bahan: menyerialkan semua perubahan stok bahan yang sama (aman untuk avg_cost)
            $ingredient = Ingredient::whereKey($in['ingredient_id'])->lockForUpdate()->firstOrFail();

            $stock = Stock::where(['ingredient_id' => $ingredient->id, 'branch_id' => $in['branch_id']])
                ->lockForUpdate()->first()
                ?? Stock::create(['ingredient_id' => $ingredient->id, 'branch_id' => $in['branch_id'], 'quantity' => 0]);

            $qtyInput = UnitConverter::normalize($in['qty']);
            $absBase = $this->converter->toBase(ltrim($qtyInput, '-'), (int) $in['unit_id'], $ingredient);
            $negative = match ($type) {
                MovementType::In, MovementType::Return => false,
                MovementType::Out, MovementType::Waste => true,
                default => str_starts_with($qtyInput, '-'),
            };
            $qtyBase = $negative ? bcmul($absBase, '-1', 4) : bcadd($absBase, '0', 4);

            if (bccomp($qtyBase, '0', 4) === 0) {
                throw new \InvalidArgumentException('Jumlah stok yang diubah tidak boleh nol.');
            }

            $newQty = bcadd((string) $stock->quantity, $qtyBase, 4);
            if (bccomp($newQty, '0', 4) < 0 && empty($in['allow_negative'])) {
                throw new InsufficientStockException(sprintf(
                    'Stok %s tidak cukup: tersedia %s, dibutuhkan %s.',
                    $ingredient->name,
                    rtrim(rtrim((string) $stock->quantity, '0'), '.') ?: '0',
                    rtrim(rtrim(ltrim($qtyBase, '-'), '0'), '.')
                ));
            }

            $unitCost = null;
            if ($type === MovementType::In) {
                if (! isset($in['unit_cost'])) {
                    throw new \InvalidArgumentException('Pergerakan masuk wajib punya unit_cost (per satuan dasar).');
                }
                $unitCost = UnitConverter::normalize($in['unit_cost']);
                $this->applyMovingAverage($ingredient, $qtyBase, $unitCost);
            } else {
                $unitCost = (string) $ingredient->avg_cost;
            }

            $stock->forceFill(['quantity' => $newQty])->save();

            return StockMovement::create([
                'uuid' => $uuid,
                'ingredient_id' => $ingredient->id,
                'branch_id' => $in['branch_id'],
                'type' => $type,
                'qty' => $qtyInput,
                'unit_id' => $in['unit_id'],
                'qty_base' => $qtyBase,
                'unit_cost' => $unitCost,
                'balance_after' => $newQty,
                'reference_type' => $in['reference_type'] ?? null,
                'reference_id' => $in['reference_id'] ?? null,
                'notes' => $in['notes'] ?? null,
                'created_by' => $in['created_by'] ?? null,
            ]);
        });
    }

    /** Stok keluar (penjualan, pemakaian produksi). */
    public function issue(int $ingredientId, int $branchId, string|int|float $qty, int $unitId, array $extra = []): StockMovement
    {
        return $this->record($extra + [
            'ingredient_id' => $ingredientId, 'branch_id' => $branchId, 'type' => MovementType::Out,
            'qty' => $qty, 'unit_id' => $unitId,
        ]);
    }

    /** Bahan terbuang/rusak. */
    public function waste(int $ingredientId, int $branchId, string|int|float $qty, int $unitId, ?string $notes, ?int $userId = null): StockMovement
    {
        return $this->record([
            'ingredient_id' => $ingredientId, 'branch_id' => $branchId, 'type' => MovementType::Waste,
            'qty' => $qty, 'unit_id' => $unitId, 'notes' => $notes, 'created_by' => $userId,
        ]);
    }

    /** Koreksi stok. $delta bertanda: positif menambah, negatif mengurangi. */
    public function adjust(int $ingredientId, int $branchId, string|int|float $delta, int $unitId, ?string $notes, ?int $userId = null): StockMovement
    {
        return $this->record([
            'ingredient_id' => $ingredientId, 'branch_id' => $branchId, 'type' => MovementType::Adjustment,
            'qty' => $delta, 'unit_id' => $unitId, 'notes' => $notes, 'created_by' => $userId,
        ]);
    }

    /**
     * Moving average dihitung dari stok total semua cabang (avg_cost ada di level bahan).
     * Stok total <= 0 berarti harga baru langsung menjadi rata-rata.
     */
    private function applyMovingAverage(Ingredient $ingredient, string $qtyBaseIn, string $unitCost): void
    {
        $totalBefore = (string) Stock::where('ingredient_id', $ingredient->id)->sum('quantity');
        $totalBefore = bccomp($totalBefore, '0', 4) > 0 ? bcadd($totalBefore, '0', 4) : '0';

        if (bccomp($totalBefore, '0', 4) === 0) {
            $avg = $unitCost;
        } else {
            $value = bcadd(bcmul($totalBefore, (string) $ingredient->avg_cost, 8), bcmul($qtyBaseIn, $unitCost, 8), 8);
            $avg = bcdiv($value, bcadd($totalBefore, $qtyBaseIn, 4), 6);
        }

        $ingredient->forceFill(['avg_cost' => $avg, 'last_cost' => $unitCost])->save();
    }
}
