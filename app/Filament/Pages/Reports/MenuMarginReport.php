<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Filament\Support\ReportPage;
use App\Services\ReportService;

class MenuMarginReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Margin per Menu';

    protected static ?string $title = 'Margin per Menu';

    protected static ?string $slug = 'laporan-margin-menu';

    protected static ?int $navigationSort = 20;

    protected function build(ReportService $reports, array $filters): array
    {
        return $reports->menuMargin($filters['from'], $filters['to'], $filters['branch_id']);
    }
}
