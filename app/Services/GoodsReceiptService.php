<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Memposting penerimaan barang: stok bertambah, avg_cost diperbarui (moving average),
 * pergerakan tercatat, lalu HPP resep yang memakai bahan itu dihitung ulang.
 *
 * $header: branch_id, supplier_id?, receipt_date, notes?, uuid? (idempotency)
 * $items : [['ingredient_id', 'qty', 'unit_id', 'unit_price' (per satuan yang diinput)], ...]
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly UnitConverter $converter,
        private readonly RecipeCostService $costs,
    ) {
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $items
     */
    public function post(array $header, array $items, ?int $userId = null): GoodsReceipt
    {
        if (! empty($header['uuid']) && ($existing = GoodsReceipt::where('uuid', $header['uuid'])->first())) {
            return $existing->load('items');
        }
        if (! $items) {
            throw new InvalidArgumentException('Penerimaan barang harus berisi minimal satu bahan.');
        }

        $touched = [];

        $receipt = DB::transaction(function () use ($header, $items, $userId, &$touched) {
            $receipt = GoodsReceipt::create([
                'uuid' => $header['uuid'] ?? (string) Str::uuid(),
                'branch_id' => $header['branch_id'],
                'supplier_id' => $header['supplier_id'] ?? null,
                'receipt_date' => $header['receipt_date'] ?? now()->toDateString(),
                'notes' => $header['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $receipt->update(['number' => sprintf('GR-%s-%05d', $receipt->receipt_date->format('Ymd'), $receipt->id)]);

            $total = '0';
            foreach ($items as $item) {
                $ingredient = Ingredient::findOrFail($item['ingredient_id']);
                $qty = UnitConverter::normalize($item['qty']);
                $price = UnitConverter::normalize($item['unit_price']);
                $unitId = (int) $item['unit_id'];

                // harga per satuan dasar = harga per satuan input / (satuan dasar per 1 satuan input)
                $factor = $this->converter->factor($unitId, $ingredient->base_unit_id, $ingredient->id);
                $costBase = bcdiv($price, $factor, 6);
                $subtotal = bcmul($qty, $price, 2);

                $receipt->items()->create([
                    'ingredient_id' => $ingredient->id,
                    'qty' => $qty,
                    'unit_id' => $unitId,
                    'unit_price' => $price,
                    'subtotal' => $subtotal,
                ]);

                $this->stock->record([
                    'ingredient_id' => $ingredient->id,
                    'branch_id' => $receipt->branch_id,
                    'type' => MovementType::In,
                    'qty' => $qty,
                    'unit_id' => $unitId,
                    'unit_cost' => $costBase,
                    'reference_type' => 'goods_receipt',
                    'reference_id' => $receipt->id,
                    'notes' => $receipt->number,
                    'created_by' => $userId,
                ]);

                $touched[$ingredient->id] = true;
                $total = bcadd($total, $subtotal, 2);
            }

            $receipt->update(['total_amount' => $total]);

            return $receipt;
        });

        // setelah commit: harga rata-rata sudah final, HPP resep boleh dihitung ulang
        foreach (array_keys($touched) as $ingredientId) {
            $this->costs->recalculateForIngredient($ingredientId);
        }

        return $receipt->load('items');
    }
}
