<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Filament\Support\ReportPage;
use App\Services\ReportService;

class SalesDailyReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Penjualan Harian';

    protected static ?string $title = 'Penjualan Harian';

    protected static ?string $slug = 'laporan-penjualan-harian';

    protected static ?int $navigationSort = 10;

    protected function build(ReportService $reports, array $filters): array
    {
        return $reports->salesDaily($filters['from'], $filters['to'], $filters['branch_id']);
    }
}
