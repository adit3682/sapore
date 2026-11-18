<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Menu;
use App\Models\Package;
use App\Support\StockStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Semua laporan. Setiap method mengembalikan bentuk yang sama sehingga satu halaman dan satu exporter
 * bisa memakainya, dan API/APK nanti bisa memanggil method yang sama:
 *
 *  title, meta[] (baris keterangan), columns[] {key,label,type}, rows[], summary[] {label,value,type}, notes[]
 *
 * type kolom: text | int | qty | money | pct | status
 * Penjualan dihitung dari order Paid pada paid_at; "penjualan neto" = subtotal sebelum pajak & service.
 * Target food cost: config('sapore.food_cost_target'), bawaan 35 (%).
 */
class ReportService
{
    public function __construct(private readonly MenuCostService $costs)
    {
    }

    public function foodCostTarget(): string
    {
        return (string) config('sapore.food_cost_target', 35);
    }

    /** Penjualan harian: omzet, pajak, service, HPP, margin. */
    public function salesDaily(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $paid = fn () => DB::table('orders')->where('orders.status', 'paid')
            ->whereBetween('orders.paid_at', [$from, $to])
            ->when($branchId, fn ($q, $b) => $q->where('orders.branch_id', $b));

        $days = $paid()->selectRaw('DATE(orders.paid_at) as day, COUNT(*) as orders_count, SUM(orders.subtotal) as subtotal, SUM(orders.tax_amount) as tax, SUM(orders.service_amount) as service, SUM(orders.total) as total')
            ->groupByRaw('DATE(orders.paid_at)')->orderBy('day')->get();

        $cost = $paid()->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->selectRaw('DATE(orders.paid_at) as day, SUM(order_items.unit_cost * order_items.qty) as cost')
            ->groupByRaw('DATE(orders.paid_at)')->pluck('cost', 'day');

        $rows = [];
        $t = ['orders' => 0, 'subtotal' => '0', 'tax' => '0', 'service' => '0', 'total' => '0', 'cost' => '0'];

        foreach ($days as $d) {
            $subtotal = $this->money($d->subtotal);
            $c = $this->money($cost[$d->day] ?? 0);
            $margin = bcsub($subtotal, $c, 2);

            $rows[] = [
                'date' => Carbon::parse($d->day)->format('d M Y'),
                'orders' => (int) $d->orders_count,
                'subtotal' => $subtotal, 'tax' => $this->money($d->tax), 'service' => $this->money($d->service),
                'total' => $this->money($d->total), 'cost' => $c, 'margin' => $margin,
                'margin_pct' => $this->pct($margin, $subtotal),
            ];

            $t['orders'] += (int) $d->orders_count;
            foreach (['subtotal', 'tax', 'service', 'total'] as $k) {
                $t[$k] = bcadd($t[$k], $this->money($d->{$k}), 2);
            }
            $t['cost'] = bcadd($t['cost'], $c, 2);
        }

        $margin = bcsub($t['subtotal'], $t['cost'], 2);

        return [
            'title' => 'Penjualan Harian',
            'meta' => $this->meta($from, $to, $branchId),
            'columns' => [
                $this->col('date', 'Tanggal'), $this->col('orders', 'Order', 'int'),
                $this->col('subtotal', 'Penjualan neto', 'money'), $this->col('tax', 'Pajak', 'money'),
                $this->col('service', 'Service', 'money'), $this->col('total', 'Total diterima', 'money'),
                $this->col('cost', 'HPP', 'money'), $this->col('margin', 'Margin kotor', 'money'),
                $this->col('margin_pct', 'Margin %', 'pct'),
            ],
            'rows' => $rows,
            'summary' => [
                $this->sum('Jumlah order', $t['orders'], 'int'),
                $this->sum('Penjualan neto', $t['subtotal'], 'money'),
                $this->sum('Total diterima', $t['total'], 'money'),
                $this->sum('HPP', $t['cost'], 'money'),
                $this->sum('Margin kotor', $margin, 'money'),
                $this->sum('Rata-rata per order', $t['orders'] ? bcdiv($t['total'], (string) $t['orders'], 2) : '0.00', 'money'),
            ],
            'notes' => ['Penjualan neto = jumlah harga item sebelum pajak dan service. HPP memakai harga pokok saat transaksi terjadi.'],
        ];
    }

    /** Margin & food cost per menu/paket yang terjual, dibandingkan dengan target. */
    public function menuMargin(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $target = $this->foodCostTarget();

        $items = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'paid')->whereBetween('orders.paid_at', [$from, $to])
            ->when($branchId, fn ($q, $b) => $q->where('orders.branch_id', $b))
            ->selectRaw('order_items.item_type, order_items.item_id, MAX(order_items.name) as name, SUM(order_items.qty) as qty, SUM(order_items.line_total) as revenue, SUM(order_items.unit_cost * order_items.qty) as cost')
            ->groupBy('order_items.item_type', 'order_items.item_id')
            ->orderByDesc('revenue')->get();

        $rows = [];
        $revenue = '0';
        $cost = '0';

        foreach ($items as $i) {
            $rev = $this->money($i->revenue);
            $c = $this->money($i->cost);
            $fc = $this->pct($c, $rev);
            $margin = bcsub($rev, $c, 2);

            $rows[] = [
                'name' => $i->name, 'kind' => $i->item_type === 'package' ? 'Paket' : 'Menu',
                'qty' => $this->qty($i->qty), 'revenue' => $rev, 'cost' => $c, 'margin' => $margin,
                'margin_pct' => $this->pct($margin, $rev), 'food_cost' => $fc,
                'status' => $this->targetStatus($fc, $target),
            ];
            $revenue = bcadd($revenue, $rev, 2);
            $cost = bcadd($cost, $c, 2);
        }

        $fcTotal = $this->pct($cost, $revenue);

        return [
            'title' => 'Margin per Menu',
            'meta' => $this->meta($from, $to, $branchId),
            'columns' => [
                $this->col('name', 'Menu / Paket'), $this->col('kind', 'Jenis'), $this->col('qty', 'Terjual', 'qty'),
                $this->col('revenue', 'Penjualan neto', 'money'), $this->col('cost', 'HPP', 'money'),
                $this->col('margin', 'Margin', 'money'), $this->col('margin_pct', 'Margin %', 'pct'),
                $this->col('food_cost', 'Food cost %', 'pct'), $this->col('status', 'Vs target', 'status'),
            ],
            'rows' => $rows,
            'summary' => [
                $this->sum('Penjualan neto', $revenue, 'money'),
                $this->sum('HPP', $cost, 'money'),
                $this->sum('Margin kotor', bcsub($revenue, $cost, 2), 'money'),
                $this->sum('Food cost', $fcTotal, 'pct'),
                $this->sum('Target food cost', $target, 'pct'),
                $this->sum('Status', $this->targetStatus($fcTotal, $target), 'status'),
            ],
            'notes' => ['Food cost % = HPP / penjualan neto. Target dapat diatur lewat SAPORE_FOOD_COST_TARGET.'],
        ];
    }

    /** HPP terkini semua menu & paket aktif (tanpa periode). */
    public function menuCost(): array
    {
        $target = $this->foodCostTarget();
        $rows = [];

        $push = function (string $code, string $name, string $kind, string $cost, $price) use (&$rows, $target) {
            $s = $this->costs->summarize($cost, $price);
            $rows[] = [
                'code' => $code, 'name' => $name, 'kind' => $kind,
                'cost' => $this->money($s['cost']), 'price' => $this->money($s['price']), 'margin' => $s['margin'],
                'margin_pct' => $s['margin_pct'], 'food_cost' => $s['food_cost_pct'],
                'status' => $s['food_cost_pct'] === null ? 'Belum ada harga' : $this->targetStatus($s['food_cost_pct'], $target),
            ];
        };

        foreach (Menu::with('recipe')->where('is_active', true)->orderBy('code')->get() as $m) {
            $push($m->code, $m->name, 'Menu', $this->costs->menuCost($m), $m->selling_price);
        }
        foreach (Package::with('items.menu.recipe')->where('is_active', true)->orderBy('code')->get() as $p) {
            $push($p->code, $p->name, 'Paket', $this->costs->packageCost($p), $p->selling_price);
        }

        $over = count(array_filter($rows, fn ($r) => $r['status'] === 'Di atas target'));

        return [
            'title' => 'HPP per Menu',
            'meta' => ['Per '.now()->format('d M Y H:i')],
            'columns' => [
                $this->col('code', 'Kode'), $this->col('name', 'Nama'), $this->col('kind', 'Jenis'),
                $this->col('cost', 'HPP', 'money'), $this->col('price', 'Harga jual', 'money'),
                $this->col('margin', 'Margin', 'money'), $this->col('margin_pct', 'Margin %', 'pct'),
                $this->col('food_cost', 'Food cost %', 'pct'), $this->col('status', 'Vs target', 'status'),
            ],
            'rows' => $rows,
            'summary' => [
                $this->sum('Jumlah menu & paket aktif', count($rows), 'int'),
                $this->sum('Di atas target food cost', $over, 'int'),
                $this->sum('Target food cost', $target, 'pct'),
            ],
            'notes' => ['HPP mengikuti harga rata-rata bahan saat ini. Menu di atas target perlu ditinjau harga jual atau resepnya.'],
        ];
    }

    /** Pemakaian bahan: terpakai (penjualan), waste, dan penyesuaian, beserta nilainya. */
    public function ingredientUsage(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $data = DB::table('stock_movements as m')
            ->join('ingredients as i', 'i.id', '=', 'm.ingredient_id')
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->whereBetween('m.created_at', [$from, $to])
            ->whereIn('m.type', ['out', 'waste', 'adjustment'])
            ->when($branchId, fn ($q, $b) => $q->where('m.branch_id', $b))
            ->selectRaw("i.code, i.name, u.symbol as unit,
                SUM(CASE WHEN m.type = 'out' THEN -m.qty_base ELSE 0 END) as used,
                SUM(CASE WHEN m.type = 'waste' THEN -m.qty_base ELSE 0 END) as waste,
                SUM(CASE WHEN m.type = 'adjustment' THEN m.qty_base ELSE 0 END) as adjustment,
                SUM(CASE WHEN m.type = 'out' THEN -m.qty_base * COALESCE(m.unit_cost, 0) ELSE 0 END) as used_cost,
                SUM(CASE WHEN m.type = 'waste' THEN -m.qty_base * COALESCE(m.unit_cost, 0) ELSE 0 END) as waste_cost")
            ->groupBy('i.id', 'i.code', 'i.name', 'u.symbol')->get();

        $rows = [];
        $usedCost = '0';
        $wasteCost = '0';

        foreach ($data as $d) {
            $used = $this->qty($d->used);
            $waste = $this->qty($d->waste);
            $uc = $this->money($d->used_cost);
            $wc = $this->money($d->waste_cost);

            $rows[] = [
                'code' => $d->code, 'name' => $d->name, 'unit' => $d->unit,
                'used' => $used, 'waste' => $waste, 'adjustment' => $this->qty($d->adjustment),
                'waste_pct' => $this->pct($waste, bcadd($used, $waste, 4)),
                'cost' => bcadd($uc, $wc, 2), 'waste_cost' => $wc,
            ];
            $usedCost = bcadd($usedCost, $uc, 2);
            $wasteCost = bcadd($wasteCost, $wc, 2);
        }

        usort($rows, fn ($a, $b) => bccomp($b['cost'], $a['cost'], 2) ?: strcmp($a['name'], $b['name']));
        $rows = array_values(array_filter($rows, fn ($r) => bccomp($r['used'], '0', 4) !== 0 || bccomp($r['waste'], '0', 4) !== 0 || bccomp($r['adjustment'], '0', 4) !== 0));

        $total = bcadd($usedCost, $wasteCost, 2);

        return [
            'title' => 'Penggunaan Bahan Baku',
            'meta' => $this->meta($from, $to, $branchId),
            'columns' => [
                $this->col('code', 'Kode'), $this->col('name', 'Bahan'), $this->col('unit', 'Satuan'),
                $this->col('used', 'Terpakai (penjualan)', 'qty'), $this->col('waste', 'Waste', 'qty'),
                $this->col('adjustment', 'Penyesuaian (net)', 'qty'), $this->col('waste_pct', 'Waste %', 'pct'),
                $this->col('cost', 'Nilai pemakaian', 'money'), $this->col('waste_cost', 'Nilai waste', 'money'),
            ],
            'rows' => $rows,
            'summary' => [
                $this->sum('Nilai bahan terpakai', $usedCost, 'money'),
                $this->sum('Nilai waste', $wasteCost, 'money'),
                $this->sum('Waste dari total pemakaian', $this->pct($wasteCost, $total), 'pct'),
            ],
            'notes' => [
                'Satuan mengikuti satuan dasar tiap bahan. Penyesuaian bertanda: + menambah stok, - mengurangi (mis. hasil Stock Opname).',
                'Nilai memakai harga rata-rata bahan saat pergerakan terjadi.',
            ],
        ];
    }

    /** Stok dan nilai persediaan saat ini. */
    public function inventoryValuation(?int $branchId): array
    {
        $branches = $branchId ? 1 : max(Branch::count(), 1);

        $data = DB::table('stocks as s')
            ->join('ingredients as i', 'i.id', '=', 's.ingredient_id')
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->when($branchId, fn ($q, $b) => $q->where('s.branch_id', $b))
            ->selectRaw('i.code, i.name, u.symbol as unit, i.min_stock, i.avg_cost, SUM(s.quantity) as qty')
            ->groupBy('i.id', 'i.code', 'i.name', 'u.symbol', 'i.min_stock', 'i.avg_cost')
            ->orderBy('i.code')->get();

        $rows = [];
        $total = '0';
        $count = ['critical' => 0, 'low' => 0];

        foreach ($data as $d) {
            $qty = $this->qty($d->qty);
            $avg = $this->money4($d->avg_cost);
            $value = bccomp($qty, '0', 4) > 0 ? bcmul($qty, $avg, 2) : '0.00';
            $status = StockStatus::for($qty, bcmul($this->qty($d->min_stock), (string) $branches, 4));

            if (isset($count[$status])) {
                $count[$status]++;
            }
            $rows[] = [
                'code' => $d->code, 'name' => $d->name, 'unit' => $d->unit, 'qty' => $qty,
                'min' => bcmul($this->qty($d->min_stock), (string) $branches, 4), 'avg_cost' => $avg, 'value' => $value,
                'status' => StockStatus::label($status),
            ];
            $total = bcadd($total, $value, 2);
        }

        return [
            'title' => 'Stok dan Nilai Persediaan',
            'meta' => ['Per '.now()->format('d M Y H:i'), 'Cabang: '.($branchId ? Branch::find($branchId)?->name : 'Semua cabang')],
            'columns' => [
                $this->col('code', 'Kode'), $this->col('name', 'Bahan'), $this->col('unit', 'Satuan'),
                $this->col('qty', 'Stok', 'qty'), $this->col('min', 'Minimum', 'qty'),
                $this->col('avg_cost', 'Harga rata-rata', 'money'), $this->col('value', 'Nilai', 'money'),
                $this->col('status', 'Status', 'status'),
            ],
            'rows' => $rows,
            'summary' => [
                $this->sum('Total nilai persediaan', $total, 'money'),
                $this->sum('Bahan Kritis', $count['critical'], 'int'),
                $this->sum('Bahan Rendah', $count['low'], 'int'),
            ],
            'notes' => ['Stok minus tidak dihitung dalam nilai. Bila semua cabang dipilih, minimum dikalikan jumlah cabang.'],
        ];
    }

    /**
     * Perkiraan kebutuhan bahan: rata-rata pemakaian harian (hanya dari penjualan) selama $lookbackDays
     * dikalikan $horizonDays, dibandingkan dengan stok sekarang.
     */
    public function forecast(?int $branchId, int $lookbackDays, int $horizonDays): array
    {
        $lookbackDays = max($lookbackDays, 1);
        $horizonDays = max($horizonDays, 1);
        $since = now()->subDays($lookbackDays)->startOfDay();

        $usage = DB::table('stock_movements as m')
            ->where('m.type', 'out')->where('m.created_at', '>=', $since)
            ->when($branchId, fn ($q, $b) => $q->where('m.branch_id', $b))
            ->selectRaw('m.ingredient_id, SUM(-m.qty_base) as used')
            ->groupBy('m.ingredient_id')->pluck('used', 'ingredient_id');

        $stock = DB::table('stocks as s')
            ->when($branchId, fn ($q, $b) => $q->where('s.branch_id', $b))
            ->selectRaw('s.ingredient_id, SUM(s.quantity) as qty')->groupBy('s.ingredient_id')->pluck('qty', 'ingredient_id');

        $ingredients = DB::table('ingredients as i')->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->whereIn('i.id', $usage->keys())->selectRaw('i.id, i.code, i.name, u.symbol as unit, i.avg_cost, i.last_cost')->get()->keyBy('id');

        $rows = [];
        $shortageCost = '0';

        foreach ($usage as $id => $used) {
            $ing = $ingredients[$id] ?? null;
            if (! $ing || bccomp($this->qty($used), '0', 4) <= 0) {
                continue;
            }

            $avgDaily = bcdiv($this->qty($used), (string) $lookbackDays, 4);
            $need = bcmul($avgDaily, (string) $horizonDays, 4);
            $onHand = $this->qty($stock[$id] ?? 0);
            $usable = bccomp($onHand, '0', 4) > 0 ? $onHand : '0';
            $short = bccomp($need, $usable, 4) > 0 ? bcsub($need, $usable, 4) : '0.0000';
            $unitCost = bccomp($this->money4($ing->avg_cost), '0', 4) > 0 ? $this->money4($ing->avg_cost) : $this->money4($ing->last_cost);
            $cost = bcmul($short, $unitCost, 2);

            $rows[] = [
                'code' => $ing->code, 'name' => $ing->name, 'unit' => $ing->unit,
                'avg_daily' => $avgDaily, 'need' => $need, 'stock' => $onHand, 'shortage' => $short,
                'cover_days' => bccomp($avgDaily, '0', 4) > 0 ? bcdiv($usable, $avgDaily, 1) : null,
                'cost' => $cost,
                'status' => bccomp($short, '0', 4) > 0 ? 'Perlu belanja' : 'Cukup',
            ];
            $shortageCost = bcadd($shortageCost, $cost, 2);
        }

        usort($rows, fn ($a, $b) => bccomp($b['shortage'], $a['shortage'], 4) ?: strcmp($a['name'], $b['name']));

        return [
            'title' => 'Perkiraan Kebutuhan Bahan',
            'meta' => [
                "Berdasarkan rata-rata penjualan {$lookbackDays} hari terakhir, untuk {$horizonDays} hari ke depan",
                'Cabang: '.($branchId ? Branch::find($branchId)?->name : 'Semua cabang'),
            ],
            'columns' => [
                $this->col('code', 'Kode'), $this->col('name', 'Bahan'), $this->col('unit', 'Satuan'),
                $this->col('avg_daily', 'Rata-rata / hari', 'qty'), $this->col('need', 'Perkiraan butuh', 'qty'),
                $this->col('stock', 'Stok sekarang', 'qty'), $this->col('shortage', 'Kurang', 'qty'),
                $this->col('cover_days', 'Cukup untuk (hari)', 'qty'), $this->col('cost', 'Est. belanja', 'money'),
                $this->col('status', 'Status', 'status'),
            ],
            'rows' => $rows,
            'summary' => [
                $this->sum('Bahan perlu belanja', count(array_filter($rows, fn ($r) => $r['status'] === 'Perlu belanja')), 'int'),
                $this->sum('Estimasi belanja', $shortageCost, 'money'),
            ],
            'notes' => ['Perkiraan sederhana: rata-rata datar, tidak memperhitungkan hari ramai/libur atau tren naik-turun. Pakai sebagai acuan awal.'],
        ];
    }

    // ------------------------------------------------------------------

    /** @return array{key: string, label: string, type: string} */
    private function col(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    /** @return array{label: string, value: mixed, type: string} */
    private function sum(string $label, mixed $value, string $type): array
    {
        return ['label' => $label, 'value' => $value, 'type' => $type];
    }

    /** @return string[] */
    private function meta(Carbon $from, Carbon $to, ?int $branchId): array
    {
        return [
            'Periode: '.$from->format('d M Y').' s/d '.$to->format('d M Y'),
            'Cabang: '.($branchId ? Branch::find($branchId)?->name : 'Semua cabang'),
        ];
    }

    private function targetStatus(?string $foodCostPct, string $target): string
    {
        if ($foodCostPct === null) {
            return '-';
        }

        return bccomp($foodCostPct, $target, 1) > 0 ? 'Di atas target' : 'Sesuai target';
    }

    private function pct(string $num, string $den): ?string
    {
        if (bccomp($den, '0', 4) <= 0) {
            return null;
        }

        return bcmul(bcdiv($num, $den, 6), '100', 1);
    }

    /** Rupiah, 2 desimal (pembulatan, bukan pemotongan: SUM di SQLite berupa float). */
    private function money(mixed $v): string
    {
        return number_format(round((float) ($v ?? 0), 2), 2, '.', '');
    }

    private function money4(mixed $v): string
    {
        return number_format(round((float) ($v ?? 0), 4), 4, '.', '');
    }

    private function qty(mixed $v): string
    {
        return number_format(round((float) ($v ?? 0), 4), 4, '.', '');
    }
}
