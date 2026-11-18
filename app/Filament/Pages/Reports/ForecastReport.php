<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Filament\Support\ReportPage;
use App\Services\ReportService;
use Filament\Forms;

class ForecastReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationLabel = 'Perkiraan Kebutuhan Bahan';

    protected static ?string $title = 'Perkiraan Kebutuhan Bahan';

    protected static ?string $slug = 'laporan-perkiraan-bahan';

    protected static ?int $navigationSort = 60;

    protected static array $roles = [Role::Owner, Role::Manager, Role::Inventory, Role::Chef];

    protected function filterFields(): array
    {
        return [
            $this->branchField(),
            Forms\Components\Select::make('lookback')->label('Dasar penjualan')->native(false)->selectablePlaceholder(false)->live()
                ->options([7 => '7 hari terakhir', 14 => '14 hari terakhir', 30 => '30 hari terakhir']),
            Forms\Components\Select::make('horizon')->label('Untuk')->native(false)->selectablePlaceholder(false)->live()
                ->options([3 => '3 hari ke depan', 7 => '7 hari ke depan', 14 => '14 hari ke depan']),
        ];
    }

    protected function build(ReportService $reports, array $filters): array
    {
        return $reports->forecast($filters['branch_id'], $filters['lookback'], $filters['horizon']);
    }
}
