<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Support\Access;
use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Package;
use App\Services\OrderService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * Halaman Kasir (web). Hanya antarmuka: seluruh perhitungan dan aturan stok ada di OrderService,
 * yang juga dipakai API /api/v1 untuk APK.
 */
class PosPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $navigationLabel = 'Kasir';

    protected static ?string $title = 'Kasir';

    protected static ?string $slug = 'kasir';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.pos';

    public string $category = 'all';

    public string $search = '';

    /** @var array<string, array{type: string, id: int, qty: int}> kunci "menu:12" */
    public array $cart = [];

    public string $tableLabel = '';

    public string $orderType = 'dine_in';

    public string $paymentMethod = 'cash';

    public ?string $amountPaid = null;

    public ?int $orderId = null;

    /** Cabang transaksi. Akun cabang: terkunci ke cabangnya; akun pusat (Owner): memilih. */
    public ?int $branchId = null;

    public static function canAccess(): bool
    {
        return app(OrderService::class)->canSell(auth()->user());
    }

    /** Lanjutkan order tersimpan: /admin/kasir?order=ID */
    public function mount(): void
    {
        $scope = Access::branchScope(auth()->user());
        $this->branchId = $scope;

        if ($id = request()->query('order')) {
            $order = Order::with('items')->where('status', OrderStatus::Open->value)
                ->when($scope !== null, fn ($q) => $q->where('branch_id', $scope))->find($id);
            if ($order) {
                $this->orderId = $order->id;
                $this->branchId = $order->branch_id;
                $this->tableLabel = (string) $order->table_label;
                $this->orderType = $order->order_type;
                foreach ($order->items as $i) {
                    $this->cart["{$i->item_type}:{$i->item_id}"] = ['type' => $i->item_type, 'id' => $i->item_id, 'qty' => (int) $i->qty];
                }
            }
        }
    }

    /** Pilihan cabang untuk akun pusat; kosong untuk akun cabang (terkunci). */
    public function branchChoices(): array
    {
        return Access::isCentral(auth()->user()) ? Branch::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [];
    }

    /** @return array<int, array{key: string, label: string}> */
    public function categories(): array
    {
        $tabs = [['key' => 'all', 'label' => 'Semua']];
        foreach (Category::where('type', 'menu')->where('name', '!=', 'Paket')->orderBy('name')->get() as $c) {
            $tabs[] = ['key' => (string) $c->id, 'label' => $c->name];
        }
        $tabs[] = ['key' => 'package', 'label' => 'Paket'];

        return $tabs;
    }

    /** @return array<int, array{type: string, id: int, name: string, price: string, tag: string}> */
    public function catalog(): array
    {
        $items = [];
        $needle = trim($this->search);

        if ($this->category !== 'package') {
            $menus = Menu::with('category')->where('is_active', true)
                ->when(ctype_digit($this->category), fn ($q) => $q->where('category_id', (int) $this->category))
                ->when($needle !== '', fn ($q) => $q->where('name', 'like', "%{$needle}%"))
                ->orderBy('name')->get();
            foreach ($menus as $m) {
                $items[] = ['type' => 'menu', 'id' => $m->id, 'name' => $m->name, 'price' => (string) $m->selling_price, 'tag' => $m->category?->name ?? 'Menu'];
            }
        }

        if ($this->category === 'all' || $this->category === 'package') {
            $packages = Package::where('is_active', true)
                ->when($needle !== '', fn ($q) => $q->where('name', 'like', "%{$needle}%"))
                ->orderBy('name')->get();
            foreach ($packages as $p) {
                $items[] = ['type' => 'package', 'id' => $p->id, 'name' => $p->name, 'price' => (string) $p->selling_price, 'tag' => 'Paket'];
            }
        }

        return $items;
    }

    /** Ringkasan keranjang dihitung oleh service (harga dari database). */
    public function summary(): array
    {
        $lines = array_values($this->cart);
        if (! $lines) {
            return ['lines' => [], 'subtotal' => '0', 'tax_rate' => (string) config('sapore.tax_rate'), 'tax' => '0',
                'service_rate' => (string) config('sapore.service_rate'), 'service' => '0', 'total' => '0'];
        }

        try {
            return app(OrderService::class)->calculate($lines);
        } catch (\Throwable $e) {
            // item nonaktif / terhapus sejak ditambahkan: kosongkan yang bermasalah
            Notification::make()->warning()->title('Keranjang disesuaikan')->body($e->getMessage())->send();
            $this->cart = [];

            return $this->summary();
        }
    }

    public function change(): string
    {
        if ($this->paymentMethod !== 'cash' || ! is_numeric($this->amountPaid)) {
            return '0';
        }
        $diff = bcsub((string) $this->amountPaid, $this->summary()['total'], 2);

        return bccomp($diff, '0', 2) > 0 ? $diff : '0';
    }

    public function setCategory(string $key): void
    {
        $this->category = $key;
    }

    public function add(string $type, int $id): void
    {
        $key = "{$type}:{$id}";
        $this->cart[$key] = ['type' => $type, 'id' => $id, 'qty' => ($this->cart[$key]['qty'] ?? 0) + 1];
    }

    public function increment(string $key): void
    {
        if (isset($this->cart[$key])) {
            $this->cart[$key]['qty']++;
        }
    }

    public function decrement(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }
        $this->cart[$key]['qty']--;
        if ($this->cart[$key]['qty'] <= 0) {
            unset($this->cart[$key]);
        }
    }

    public function remove(string $key): void
    {
        unset($this->cart[$key]);
    }

    public function clearCart(): void
    {
        $this->reset('cart', 'tableLabel', 'orderId', 'amountPaid');
        $this->orderType = 'dine_in';
        $this->paymentMethod = 'cash';
    }

    /** Simpan Order (tetap Open, stok belum berubah). */
    public function saveOrder(): void
    {
        $this->run(function () {
            $order = $this->persist();
            Notification::make()->success()->title("Order {$order->number} disimpan")->send();
            $this->clearCart();
        });
    }

    /** Bayar Sekarang: order dibayar dan stok bahan berkurang. */
    public function pay(): void
    {
        $this->run(function () {
            $service = app(OrderService::class);
            $order = $this->persist();
            $order = $service->pay($order, $this->paymentMethod, $this->amountPaid, auth()->id());

            $change = (float) $order->change_amount;
            Notification::make()->success()->title("Order {$order->number} dibayar")
                ->body($change > 0 ? 'Kembalian Rp '.number_format($change, 0, ',', '.') : null)->send();
            $this->clearCart();
        });
    }

    private function persist(): Order
    {
        $service = app(OrderService::class);
        $lines = array_values($this->cart);
        $scope = Access::branchScope(auth()->user());
        $header = [
            'order_type' => $this->orderType,
            'table_label' => $this->tableLabel !== '' ? $this->tableLabel : null,
            'branch_id' => $this->branchId,
        ];

        if ($this->orderId && ($existing = Order::when($scope !== null, fn ($q) => $q->where('branch_id', $scope))->find($this->orderId))) {
            return $service->replaceItems($existing, $lines, $header);
        }

        $order = $service->create($header + ['uuid' => (string) Str::uuid()], $lines, auth()->user());
        $this->orderId = $order->id; // bila pembayaran gagal, percobaan berikutnya memakai order yang sama

        return $order;
    }

    private function run(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            Notification::make()->danger()->title('Tidak bisa diproses')->body($e->getMessage())->send();
        }
    }
}
