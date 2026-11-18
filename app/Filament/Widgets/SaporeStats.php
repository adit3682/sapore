<?php

namespace App\Filament\Widgets;

use App\Models\Menu;
use App\Models\ProductionPlan;
use App\Support\StockQueries;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SaporeStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $branchId = auth()->user()?->branch_id;

        $plansToday = ProductionPlan::whereDate('plan_date', today())
            ->when($branchId, fn ($query, $b) => $query->where('branch_id', $b))->count();

        return [
            Stat::make('Rencana Produksi', $plansToday)->description('Hari ini')->color('primary'),
            Stat::make('Menu Aktif', Menu::where('is_active', true)->count())->description('Siap jual')->color('success'),
            Stat::make('Stok Menipis', StockQueries::low($branchId)->count())->description('Perlu attention')->color('danger'),
            Stat::make('Nilai Persediaan', StockQueries::compactRupiah(StockQueries::inventoryValue($branchId)))
                ->description('Bahan baku')->color('info'),
        ];
    }
}
