<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Filament\Support\ReportPage;
use App\Services\ReportService;

class MenuCostReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'HPP per Menu';

    protected static ?string $title = 'HPP per Menu';

    protected static ?string $slug = 'laporan-hpp-menu';

    protected static ?int $navigationSort = 30;

    protected static array $roles = [Role::Owner, Role::Manager, Role::Chef];

    /** HPP terkini: tidak ada filter periode. */
    protected function filterFields(): array
    {
        return [];
    }

    protected function build(ReportService $reports, array $filters): array
    {
        return $reports->menuCost();
    }
}
