<?php

namespace App\Filament\Resources;

use App\Enums\MovementType;
use App\Filament\Resources\StockMovementResource\Pages;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\StockMovement;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'Pergerakan Stok';

    protected static ?string $pluralModelLabel = 'Riwayat Pergerakan Stok';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    private const LABELS = [
        'in' => 'Masuk', 'out' => 'Keluar', 'adjustment' => 'Penyesuaian', 'waste' => 'Waste', 'transfer' => 'Transfer',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['ingredient.baseUnit', 'branch', 'creator']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('ingredient.name')->label('Bahan')->searchable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Cabang'),
                Tables\Columns\TextColumn::make('type')->label('Jenis')->badge()
                    ->formatStateUsing(fn ($state) => self::LABELS[$state instanceof MovementType ? $state->value : $state] ?? $state)
                    ->color(fn ($state) => match ($state instanceof MovementType ? $state->value : $state) {
                        'in' => 'success', 'out' => 'gray', 'waste' => 'danger', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('qty_base')->label('Perubahan')
                    ->formatStateUsing(function ($state, StockMovement $r) {
                        $n = rtrim(rtrim((string) $state, '0'), '.');

                        return (bccomp((string) $state, '0', 4) > 0 ? '+' : '').$n.' '.$r->ingredient->baseUnit?->symbol;
                    })
                    ->color(fn ($state) => bccomp((string) $state, '0', 4) >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('balance_after')->label('Saldo')->numeric(decimalPlaces: 2),
                Tables\Columns\TextColumn::make('notes')->label('Catatan')->limit(40)->placeholder('-'),
                Tables\Columns\TextColumn::make('creator.name')->label('Oleh')->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Jenis')
                    ->options(self::LABELS),
                Tables\Filters\SelectFilter::make('branch_id')->label('Cabang')
                    ->options(fn () => Branch::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('ingredient_id')->label('Bahan')->searchable()
                    ->options(fn () => Ingredient::orderBy('name')->pluck('name', 'id')),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListStockMovements::route('/')];
    }
}
