<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\OrderStatus;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\User;
use App\Support\Access;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Retur order yang sudah dibayar: penuh atau sebagian per item.
 *
 * Uang  : dikembalikan sebesar harga item + pajak + service proporsional (memakai tarif yang tersimpan di order).
 *         Retur yang menuntaskan seluruh order memakai sisa pajak/service persis, sehingga total semua retur
 *         selalu sama dengan total order (tanpa selisih pembulatan).
 * Stok  : bahan item yang diretur dihitung dari BOM, lalu
 *         - restock : bahan kembali ke stok (pergerakan "return"), untuk makanan belum dimasak/utuh.
 *         - waste   : bahan kembali (return) lalu langsung dibuang (waste). Stok bersih tidak berubah,
 *                     tetapi nilainya pindah dari "terpakai" ke "waste" di laporan (bawaan).
 * Laporan penjualan mengurangi retur pada tanggal retur (bukan tanggal order).
 * Hanya Owner dan Manager (Access: orders.refund); akun cabang hanya untuk order cabangnya.
 * Idempoten: uuid retur yang sama tidak diproses dua kali.
 *
 * Catatan: BOM dihitung dari resep SAAT INI; bila resep diubah setelah penjualan, bahan yang kembali
 * mengikuti resep terbaru.
 */
class OrderReturnService
{
    public const MODES = ['waste', 'restock'];
    public const METHODS = ['cash', 'qris', 'card', 'transfer'];

    public function __construct(
        private readonly BomExplosionService $bom,
        private readonly StockService $stock,
    ) {
    }

    /** @return array<int, string> order_item_id => sisa qty yang masih bisa diretur */
    public function remaining(Order $order): array
    {
        $returned = OrderReturnItem::query()
            ->join('order_returns', 'order_returns.id', '=', 'order_return_items.order_return_id')
            ->where('order_returns.order_id', $order->id)
            ->selectRaw('order_return_items.order_item_id, SUM(order_return_items.qty) as qty')
            ->groupBy('order_return_items.order_item_id')
            ->pluck('qty', 'order_item_id');

        $out = [];
        foreach ($order->items()->get() as $item) {
            $left = bcsub($this->n((string) $item->qty), $this->n($returned[$item->id] ?? 0), 2);
            $out[$item->id] = bccomp($left, '0', 2) > 0 ? $left : '0.00';
        }

        return $out;
    }

    /**
     * @param  array{uuid?: string, items: array<int, array{order_item_id: int|string, qty: int|float|string}>, mode?: string, refund_method?: string, reason: string}  $payload
     */
    public function create(Order $order, array $payload, User $actor): OrderReturn
    {
        if (! Access::can($actor, 'orders', 'refund')) {
            throw new AuthorizationException('Hanya Owner atau Manager yang boleh memproses retur.');
        }
        $scope = Access::branchScope($actor);
        if ($scope !== null && (int) $order->branch_id !== $scope) {
            throw new AuthorizationException('Order ini bukan milik cabang Anda.');
        }

        if (! empty($payload['uuid']) && ($existing = OrderReturn::where('uuid', $payload['uuid'])->first())) {
            return $existing->load('items');
        }

        $mode = $payload['mode'] ?? 'waste';
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Mode retur tidak dikenal (waste atau restock).');
        }
        $reason = trim((string) ($payload['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('Alasan retur wajib diisi.');
        }

        return DB::transaction(function () use ($order, $payload, $actor, $mode, $reason) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::Returned], true)) {
                throw new RuntimeException('Retur hanya untuk order yang sudah dibayar.');
            }

            $method = $payload['refund_method'] ?? $order->payment_method;
            if (! in_array($method, self::METHODS, true)) {
                throw new InvalidArgumentException('Metode pengembalian dana tidak dikenal.');
            }

            $items = $order->items()->get()->keyBy('id');
            $remaining = $this->remaining($order);

            // gabungkan baris yang menunjuk item yang sama, lalu validasi terhadap sisa
            $wanted = [];
            foreach ($payload['items'] ?? [] as $row) {
                $id = (int) ($row['order_item_id'] ?? 0);
                if (! isset($items[$id])) {
                    throw new InvalidArgumentException('Ada item yang bukan bagian dari order ini.');
                }
                $qty = $this->n($row['qty'] ?? 0);
                if (bccomp($qty, '0', 2) < 0) {
                    throw new InvalidArgumentException('Jumlah retur tidak boleh negatif.');
                }
                $wanted[$id] = bcadd($wanted[$id] ?? '0', $qty, 2);
            }
            $wanted = array_filter($wanted, fn ($q) => bccomp($q, '0', 2) > 0);
            if (! $wanted) {
                throw new InvalidArgumentException('Pilih minimal satu item dengan jumlah retur lebih dari 0.');
            }

            $subtotal = '0';
            $cost = '0';
            $lines = [];
            foreach ($wanted as $id => $qty) {
                /** @var OrderItem $item */
                $item = $items[$id];
                if (bccomp($qty, $remaining[$id], 2) > 0) {
                    throw new InvalidArgumentException("Jumlah retur {$item->name} melebihi sisa yang bisa diretur ({$remaining[$id]}).");
                }
                $lineTotal = bcmul((string) $item->unit_price, $qty, 2);
                $subtotal = bcadd($subtotal, $lineTotal, 2);
                $cost = bcadd($cost, bcmul((string) $item->unit_cost, $qty, 4), 4);
                $lines[] = ['item' => $item, 'qty' => $qty, 'line_total' => $lineTotal];
            }

            // apakah retur ini menuntaskan seluruh order?
            $completes = true;
            foreach ($remaining as $id => $left) {
                $after = bcsub($left, $wanted[$id] ?? '0', 2);
                if (bccomp($after, '0', 2) > 0) {
                    $completes = false;
                    break;
                }
            }

            $prior = OrderReturn::where('order_id', $order->id);
            if ($completes) {
                $tax = bcsub((string) $order->tax_amount, $this->n($prior->sum('tax_refund')), 2);
                $service = bcsub((string) $order->service_amount, $this->n((clone $prior)->sum('service_refund')), 2);
            } else {
                $tax = $this->roundRupiah(bcdiv(bcmul($subtotal, (string) $order->tax_rate, 4), '100', 4));
                $service = $this->roundRupiah(bcdiv(bcmul($subtotal, (string) $order->service_rate, 4), '100', 4));
            }
            $total = bcadd(bcadd($subtotal, $tax, 2), $service, 2);

            $return = OrderReturn::create([
                'uuid' => $payload['uuid'] ?? null,
                'order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'mode' => $mode,
                'refund_method' => $method,
                'reason' => $reason,
                'subtotal_refund' => $subtotal,
                'tax_refund' => $tax,
                'service_refund' => $service,
                'total_refund' => $total,
                'cost_refund' => $cost,
                'returned_by' => $actor->id,
                'returned_at' => now(),
            ]);
            $return->update(['number' => sprintf('RTN-%s-%05d', now()->format('Ymd'), $return->id)]);

            foreach ($lines as $l) {
                $return->items()->create([
                    'order_item_id' => $l['item']->id,
                    'item_type' => $l['item']->item_type,
                    'item_id' => $l['item']->item_id,
                    'name' => $l['item']->name,
                    'unit_price' => $l['item']->unit_price,
                    'unit_cost' => $l['item']->unit_cost,
                    'qty' => $l['qty'],
                    'line_total' => $l['line_total'],
                ]);
            }

            $this->moveStock($order, $return, $lines, $mode, $actor);

            $order->update(['refunded_total' => bcadd((string) $order->refunded_total, $total, 2)]);
            $order->update(['status' => OrderStatus::Returned]);

            return $return->load('items');
        });
    }

    /** @param array<int, array{item: OrderItem, qty: string, line_total: string}> $lines */
    private function moveStock(Order $order, OrderReturn $return, array $lines, string $mode, User $actor): void
    {
        $needs = $this->bom->explodeItems(array_map(fn ($l) => [
            'type' => $l['item']->item_type, 'id' => $l['item']->item_id, 'qty' => $l['qty'],
        ], $lines));

        foreach ($needs as $ingredientId => $qtyBase) {
            if (bccomp($qtyBase, '0', 4) === 0) {
                continue;
            }
            $ingredient = Ingredient::findOrFail($ingredientId);
            $base = [
                'ingredient_id' => $ingredientId,
                'branch_id' => $order->branch_id,
                'qty' => $qtyBase,
                'unit_id' => $ingredient->base_unit_id,
                'reference_type' => 'order_return',
                'reference_id' => $return->id,
                'notes' => $return->number.' / '.$order->number,
                'created_by' => $actor->id,
                'allow_negative' => true,
            ];

            // bahan kembali ke stok...
            $this->stock->record($base + ['uuid' => $this->movementUuid($return->uuid, 'return', $ingredientId), 'type' => MovementType::Return]);

            // ...dan bila makanannya dibuang, langsung dicatat sebagai waste
            if ($mode === 'waste') {
                $this->stock->record($base + ['uuid' => $this->movementUuid($return->uuid, 'waste', $ingredientId), 'type' => MovementType::Waste]);
            }
        }
    }

    private function movementUuid(string $returnUuid, string $leg, int $ingredientId): string
    {
        $h = md5("return:{$returnUuid}:{$leg}:{$ingredientId}");

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }

    private function roundRupiah(string $value): string
    {
        return bcadd(bcadd($value, '0.5', 4), '0', 0).'.00';
    }

    /** Angka dari SQL (bisa float di SQLite) menjadi string 2 desimal. */
    private function n(mixed $v): string
    {
        return number_format(round((float) $v, 2), 2, '.', '');
    }
}
