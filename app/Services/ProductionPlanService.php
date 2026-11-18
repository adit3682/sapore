<?php

namespace App\Services;

use App\Enums\PlanStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\ProductionPlan;
use App\Models\PurchaseRequest;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Siklus rencana produksi: Draft -> Confirmed (stok dipesan / soft allocation) -> Done.
 *
 * Confirmed memesan bahan di stocks.reserved_quantity (tidak mengubah stok fisik), supaya dua rencana
 * tidak berebut stok yang sama. Selesai/batal melepas pesanan. Stok fisik berkurang lewat
 * penjualan (POS) atau penyesuaian, bukan lewat rencana ini.
 */
class ProductionPlanService
{
    public function __construct(private readonly BomExplosionService $bom)
    {
    }

    /** @return array{rows: array, shortage_count: int, shortage_cost: string} */
    public function confirm(ProductionPlan $plan): array
    {
        return DB::transaction(function () use ($plan) {
            $plan = ProductionPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($plan->status !== PlanStatus::Draft) {
                throw new RuntimeException('Hanya rencana berstatus Draft yang bisa dikonfirmasi.');
            }
            $plan->load('items', 'reservations');
            if ($plan->items->isEmpty()) {
                throw new RuntimeException('Rencana produksi belum punya item.');
            }

            $result = $this->bom->requirementsForPlan($plan);

            foreach ($result['rows'] as $row) {
                $ingredientId = $row['ingredient']->id;

                $stock = Stock::where(['ingredient_id' => $ingredientId, 'branch_id' => $plan->branch_id])->lockForUpdate()->first()
                    ?? Stock::create(['ingredient_id' => $ingredientId, 'branch_id' => $plan->branch_id, 'quantity' => 0]);

                $stock->forceFill(['reserved_quantity' => bcadd((string) $stock->reserved_quantity, $row['required'], 4)])->save();
                $plan->reservations()->create(['ingredient_id' => $ingredientId, 'qty_base' => $row['required']]);
            }

            $plan->update(['status' => PlanStatus::Confirmed]);

            return $result;
        });
    }

    /** Batalkan konfirmasi: pesanan stok dilepas, status kembali Draft. */
    public function unconfirm(ProductionPlan $plan): void
    {
        $this->transition($plan, PlanStatus::Confirmed, PlanStatus::Draft);
    }

    public function complete(ProductionPlan $plan): void
    {
        $this->transition($plan, PlanStatus::Confirmed, PlanStatus::Done);
    }

    /**
     * Buat Purchase Request dari bahan yang kurang. Idempoten: bila rencana ini sudah punya PR
     * (selain yang ditolak), PR itu yang dikembalikan.
     */
    public function generatePurchaseRequest(ProductionPlan $plan, ?int $userId = null): PurchaseRequest
    {
        $existing = PurchaseRequest::where('production_plan_id', $plan->id)
            ->where('status', '!=', PurchaseRequestStatus::Rejected->value)->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $plan->load('items', 'reservations');
        $result = $this->bom->requirementsForPlan($plan);
        $short = array_values(array_filter($result['rows'], fn ($r) => $r['is_short']));

        if (! $short) {
            throw new RuntimeException('Semua bahan sudah cukup, tidak perlu Purchase Request.');
        }

        return DB::transaction(function () use ($plan, $short, $userId) {
            $pr = PurchaseRequest::create([
                'branch_id' => $plan->branch_id,
                'production_plan_id' => $plan->id,
                'status' => PurchaseRequestStatus::Draft,
                'created_by' => $userId,
            ]);
            $pr->update(['number' => sprintf('PR-%s-%05d', now()->format('Ymd'), $pr->id)]);

            $total = '0';
            foreach ($short as $row) {
                $qty = $this->ceil($row['shortage'], 3); // beli dibulatkan ke atas
                $est = bcmul($qty, $this->unitCost($row['ingredient']), 2);

                $pr->items()->create([
                    'ingredient_id' => $row['ingredient']->id,
                    'qty' => $qty,
                    'unit_id' => $row['ingredient']->base_unit_id,
                    'estimated_cost' => $est,
                ]);
                $total = bcadd($total, $est, 2);
            }

            $pr->update(['total_estimated' => $total]);

            return $pr;
        });
    }

    // ------------------------------------------------------------------

    private function transition(ProductionPlan $plan, PlanStatus $from, PlanStatus $to): void
    {
        DB::transaction(function () use ($plan, $from, $to) {
            $plan = ProductionPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($plan->status !== $from) {
                throw new RuntimeException("Status rencana harus {$from->value}.");
            }

            foreach ($plan->reservations()->get() as $res) {
                $stock = Stock::where(['ingredient_id' => $res->ingredient_id, 'branch_id' => $plan->branch_id])->lockForUpdate()->first();
                if ($stock) {
                    $left = bcsub((string) $stock->reserved_quantity, (string) $res->qty_base, 4);
                    $stock->forceFill(['reserved_quantity' => bccomp($left, '0', 4) > 0 ? $left : '0'])->save();
                }
            }
            $plan->reservations()->delete();
            $plan->update(['status' => $to]);
        });
    }

    private function unitCost($ingredient): string
    {
        return bccomp((string) $ingredient->avg_cost, '0', 6) > 0 ? (string) $ingredient->avg_cost : (string) $ingredient->last_cost;
    }

    /** Pembulatan ke atas pada $scale desimal. */
    private function ceil(string $value, int $scale): string
    {
        $mul = bcpow('10', (string) $scale, 0);
        $scaled = bcmul($value, $mul, 6);
        $int = bcadd($scaled, '0', 0);
        if (bccomp($scaled, $int, 6) > 0) {
            $int = bcadd($int, '1', 0);
        }

        return bcdiv($int, $mul, $scale);
    }
}
