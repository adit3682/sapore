<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Support\Access;
use App\Models\Stock;
use App\Support\StockQueries;
use App\Support\StockStatus;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LowStockWidget extends TableWidget
{
    protected static ?string $heading = 'Stok Menipis';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return Access::hasRole(auth()->user(), [Role::Owner, Role::Manager, Role::Chef, Role::Inventory]);
    }

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => StockQueries::low(Access::branchScope(auth()->user()))
                ->with('ingredient.baseUnit')
                ->orderByRaw('stocks.quantity / (select min_stock from ingredients where ingredients.id = stocks.ingredient_id)')
                ->limit(8))
            ->paginated(false)
            ->emptyStateHeading('Semua stok di atas minimum')
            ->columns([
                Tables\Columns\TextColumn::make('ingredient.name')->label('Bahan'),
                Tables\Columns\TextColumn::make('quantity')->label('Stok')->numeric(decimalPlaces: 2)
                    ->suffix(fn (Stock $r) => ' '.$r->ingredient->baseUnit?->symbol)
                    ->badge()
                    ->color(fn (Stock $r) => StockStatus::color(StockStatus::for($r->quantity, $r->ingredient->min_stock))),
                Tables\Columns\TextColumn::make('ingredient.min_stock')->label('Min')->numeric(decimalPlaces: 2)
                    ->suffix(fn (Stock $r) => ' '.$r->ingredient->baseUnit?->symbol)->color('gray'),
            ]);
    }
}
