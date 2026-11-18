<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Filament\Support\ReportPage;
use App\Services\ReportService;

class IngredientUsageReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = 'Penggunaan Bahan';

    protected static ?string $title = 'Penggunaan Bahan';

    protected static ?string $slug = 'laporan-penggunaan-bahan';

    protected static ?int $navigationSort = 40;

    protected static array $roles = [Role::Owner, Role::Manager, Role::Inventory, Role::Chef];

    protected function build(ReportService $reports, array $filters): array
    {
        return $reports->ingredientUsage($filters['from'], $filters['to'], $filters['branch_id']);
    }
}
