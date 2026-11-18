<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Filament\Support\ReportPage;
use App\Services\ReportService;

class InventoryValuationReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Stok dan Nilai Persediaan';

    protected static ?string $title = 'Stok dan Nilai Persediaan';

    protected static ?string $slug = 'laporan-nilai-persediaan';

    protected static ?int $navigationSort = 50;

    protected static array $roles = [Role::Owner, Role::Manager, Role::Inventory];

    /** Posisi saat ini: hanya cabang. */
    protected function filterFields(): array
    {
        return [$this->branchField()];
    }

    protected function build(ReportService $reports, array $filters): array
    {
        return $reports->inventoryValuation($filters['branch_id']);
    }
}
