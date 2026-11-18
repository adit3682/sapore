<?php

namespace App\Services;

use App\Models\Branch;
use App\Support\Access;
use App\Enums\MovementType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Penjualan (POS). Seluruh aturan ada di sini; halaman Kasir web dan API /api/v1 hanya memanggilnya.
 *
 * - Harga SELALU dibaca dari database (klien hanya mengirim jenis, id, jumlah).
 * - Order Open boleh diubah; Bayar membuat stok berkurang lewat BOM Explosion + StockService.
 * - Kasir tidak boleh terhenti karena stok sistem kosong, jadi pemotongan memakai allow_negative.
 *   Selisihnya terlihat di Stok Bahan dan dikoreksi lewat Stock Opname.
 * - create() dan pay() idempoten (uuid order): pengiriman ulang dari APK yang koneksinya putus-nyambung aman.
 */
class OrderService
{
    public function __construct(
        private readonly BomExplosionService $bom,
        private readonly StockService $stock,
        private readonly MenuCostService $costs,
        private readonly LowStockNotifier $notifier,
    ) {
    }

    public function canSell(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $role = $user->role instanceof Role ? $user->role : Role::tryFrom((string) $user->role);

        return in_array($role, [Role::Cashier, Role::Manager, Role::Owner], true);
    }

    /**
     * Hitung baris, subtotal, pajak, service, dan total dari daftar [type, id, qty].
     *
     * @param  array<int, array{type: string, id: int|string, qty: int|float|string}>  $lines
     * @return array{lines: array<int, array<string, mixed>>, subtotal: string, tax_rate: string, tax: string, service_rate: string, service: string, total: string}
     */
    public function calculate(array $lines): array
    {
        $menuIds = collect($lines)->where('type', 'menu')->pluck('id')->unique()->all();
        $packageIds = collect($lines)->where('type', 'package')->pluck('id')->unique()->all();

        $menus = Menu::with('recipe')->whereIn('id', $menuIds)->get()->keyBy('id');
        $packages = Package::with('items.menu.recipe')->whereIn('id', $packageIds)->get()->keyBy('id');

        $out = [];
        $subtotal = '0';

        foreach ($lines as $line) {
            $qty = UnitConverter::normalize($line['qty'] ?? 0);
            if (bccomp($qty, '0', 2) <= 0) {
                throw new InvalidArgumentException('Jumlah item harus lebih dari 0.');
            }

            $type = $line['type'] ?? '';
            $item = match ($type) {
                'menu' => $menus[$line['id']] ?? null,
                'package' => $packages[$line['id']] ?? null,
                default => throw new InvalidArgumentException("Jenis item tidak dikenal: {$type}"),
            };
            if (! $item || ! $item->is_active) {
                throw new InvalidArgumentException('Ada item yang tidak ditemukan atau sudah nonaktif.');
            }

            $price = (string) $item->selling_price;
            $cost = $type === 'menu' ? $this->costs->menuCost($item) : $this->costs->packageCost($item);
            $lineTotal = bcmul($price, $qty, 2);

            $out[] = [
                'type' => $type, 'id' => (int) $line['id'], 'name' => $item->name,
                'unit_price' => $price, 'unit_cost' => $cost, 'qty' => $qty, 'line_total' => $lineTotal,
            ];
            $subtotal = bcadd($subtotal, $lineTotal, 2);
        }

        $taxRate = (string) config('sapore.tax_rate', 10);
        $serviceRate = (string) config('sapore.service_rate', 5);
        $tax = $this->roundRupiah(bcdiv(bcmul($subtotal, $taxRate, 4), '100', 4));
        $service = $this->roundRupiah(bcdiv(bcmul($subtotal, $serviceRate, 4), '100', 4));

        return [
            'lines' => $out, 'subtotal' => $subtotal,
            'tax_rate' => $taxRate, 'tax' => $tax,
            'service_rate' => $serviceRate, 'service' => $service,
            'total' => bcadd(bcadd($subtotal, $tax, 2), $service, 2),
        ];
    }

    /**
     * Buat order Open. $header: uuid?, order_type?, table_label?, notes?
     *
     * @param  array<string, mixed>  $header
     * @param  array<int, array{type: string, id: int|string, qty: int|float|string}>  $lines
     */
    public function create(array $header, array $lines, User $cashier): Order
    {
        if (! empty($header['uuid']) && ($existing = Order::where('uuid', $header['uuid'])->first())) {
            return $existing->load('items');
        }
        $branchId = $cashier->branch_id;
        $central = Access::isCentral($cashier);
        if (! $branchId && $central) {
            // akun pusat (Owner) memilih cabang transaksi; akun cabang selalu memakai cabangnya sendiri
            $branchId = $header['branch_id'] ?? null;
            if ($branchId && ! Branch::where('is_active', true)->whereKey($branchId)->exists()) {
                $branchId = null;
            }
        }
        if (! $branchId) {
            throw new RuntimeException($central ? 'Pilih cabang untuk transaksi ini.' : 'Akun kasir belum terhubung ke cabang.');
        }
        if (! $lines) {
            throw new InvalidArgumentException('Order harus berisi minimal satu item.');
        }

        $calc = $this->calculate($lines);

        return DB::transaction(function () use ($header, $calc, $cashier, $branchId) {
            $order = Order::create([
                'uuid' => $header['uuid'] ?? null,
                'branch_id' => $branchId,
                'cashier_id' => $cashier->id,
                'order_type' => $header['order_type'] ?? 'dine_in',
                'table_label' => $header['table_label'] ?? null,
                'notes' => $header['notes'] ?? null,
                'status' => OrderStatus::Open,
            ]);
            $order->update(['number' => sprintf('ORD-%s-%05d', now()->format('Y'), $order->id)]);

            return $this->fill($order, $calc);
        });
    }

    /** Ganti isi order yang masih Open (dipakai saat order tersimpan dilanjutkan di kasir). */
    public function replaceItems(Order $order, array $lines, array $header = []): Order
    {
        if (! $lines) {
            throw new InvalidArgumentException('Order harus berisi minimal satu item.');
        }
        $calc = $this->calculate($lines);

        return DB::transaction(function () use ($order, $calc, $header) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($order);

            $order->fill(array_filter([
                'order_type' => $header['order_type'] ?? null,
                'table_label' => $header['table_label'] ?? null,
            ], fn ($v) => $v !== null))->save();

            return $this->fill($order, $calc);
        });
    }

    /**
     * Bayar: status Paid + stok bahan berkurang sesuai BOM item yang terjual.
     * Idempoten: order yang sudah Paid dikembalikan apa adanya.
     */
    public function pay(Order $order, string $method, string|int|float|null $amountPaid = null, ?int $userId = null): Order
    {
        if (! in_array($method, ['cash', 'qris', 'card', 'transfer'], true)) {
            throw new InvalidArgumentException('Metode pembayaran tidak dikenal.');
        }

        $crossings = [];

        $paid = DB::transaction(function () use ($order, $method, $amountPaid, $userId, &$crossings) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === OrderStatus::Paid) {
                return $order;
            }
            $this->assertOpen($order);

            $total = (string) $order->total;
            if ($method === 'cash') {
                $given = UnitConverter::normalize($amountPaid ?? 0);
                if (bccomp($given, $total, 2) < 0) {
                    throw new RuntimeException('Uang yang diterima kurang dari total pembayaran.');
                }
                $change = bcsub($given, $total, 2);
            } else {
                $given = $total;
                $change = '0.00';
            }

            $needs = $this->bom->explodeItems($order->items->map(fn ($i) => [
                'type' => $i->item_type, 'id' => $i->item_id, 'qty' => $i->qty,
            ])->all());

            foreach ($needs as $ingredientId => $qtyBase) {
                if (bccomp($qtyBase, '0', 4) === 0) {
                    continue;
                }
                $ingredient = Ingredient::with('baseUnit')->findOrFail($ingredientId);

                $movement = $this->stock->record([
                    'uuid' => $this->movementUuid($order->uuid, $ingredientId),
                    'ingredient_id' => $ingredientId,
                    'branch_id' => $order->branch_id,
                    'type' => MovementType::Out,
                    'qty' => $qtyBase,
                    'unit_id' => $ingredient->base_unit_id,
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'notes' => $order->number,
                    'created_by' => $userId,
                    'allow_negative' => true,
                ]);

                $before = bcadd((string) $movement->balance_after, $qtyBase, 4);
                $min = (string) $ingredient->min_stock;
                if (bccomp($min, '0', 4) > 0 && bccomp((string) $movement->balance_after, $min, 4) < 0 && bccomp($before, $min, 4) >= 0) {
                    $crossings[] = ['ingredient' => $ingredient, 'balance' => (string) $movement->balance_after, 'branch_id' => $order->branch_id];
                }
            }

            $order->update([
                'status' => OrderStatus::Paid,
                'payment_method' => $method,
                'amount_paid' => $given,
                'change_amount' => $change,
                'paid_at' => now(),
            ]);

            return $order;
        });

        $this->notifier->notify($crossings); // setelah transaksi selesai; tidak pernah menggagalkan pembayaran

        return $paid->load('items');
    }

    /** Batalkan order yang belum dibayar. Order Paid tidak bisa dibatalkan di sini (butuh alur retur). */
    public function void(Order $order): Order
    {
        $this->assertOpen($order);
        $order->update(['status' => OrderStatus::Void]);

        return $order;
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $calc */
    private function fill(Order $order, array $calc): Order
    {
        $order->items()->delete();
        foreach ($calc['lines'] as $l) {
            $order->items()->create([
                'item_type' => $l['type'], 'item_id' => $l['id'], 'name' => $l['name'],
                'unit_price' => $l['unit_price'], 'unit_cost' => $l['unit_cost'],
                'qty' => $l['qty'], 'line_total' => $l['line_total'],
            ]);
        }
        $order->update([
            'subtotal' => $calc['subtotal'],
            'tax_rate' => $calc['tax_rate'], 'tax_amount' => $calc['tax'],
            'service_rate' => $calc['service_rate'], 'service_amount' => $calc['service'],
            'total' => $calc['total'],
        ]);

        return $order->load('items');
    }

    private function assertOpen(Order $order): void
    {
        if ($order->status !== OrderStatus::Open) {
            throw new RuntimeException('Order ini sudah '.($order->status === OrderStatus::Paid ? 'dibayar' : 'dibatalkan').' dan tidak bisa diubah.');
        }
    }

    /** uuid tetap per (order, bahan): pemotongan stok yang sama tidak mungkin tercatat dua kali. */
    private function movementUuid(string $orderUuid, int $ingredientId): string
    {
        $h = md5("order:{$orderUuid}:ingredient:{$ingredientId}");

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }

    /** Pembulatan rupiah ke bilangan bulat terdekat (0,5 ke atas). */
    private function roundRupiah(string $value): string
    {
        return bcadd(bcadd($value, '0.5', 4), '0', 0).'.00';
    }
}
