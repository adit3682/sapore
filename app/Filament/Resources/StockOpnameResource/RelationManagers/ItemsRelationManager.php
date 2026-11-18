<?php

namespace App\Filament\Resources\StockOpnameResource\RelationManagers;

use App\Enums\StockOpnameStatus;
use App\Models\StockOpnameItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Hitung Fisik';

    // kolom stok fisik bisa diisi langsung di tabel (dikunci otomatis setelah opname tidak Draft)
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $unit = fn (StockOpnameItem $r) => ' '.$r->ingredient->baseUnit?->symbol;

        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['ingredient.baseUnit', 'opname']))
            ->columns([
                Tables\Columns\TextColumn::make('ingredient.code')->label('Kode')->searchable(),
                Tables\Columns\TextColumn::make('ingredient.name')->label('Nama Bahan')->searchable(),
                Tables\Columns\TextColumn::make('system_qty')->label('Stok sistem')->numeric(decimalPlaces: 2)->suffix($unit),
                Tables\Columns\TextInputColumn::make('physical_qty')->label('Stok fisik')
                    ->type('number')->extraInputAttributes(['step' => '0.0001', 'min' => '0'])
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->disabled(fn (StockOpnameItem $r) => $r->opname->status !== StockOpnameStatus::Draft),
                Tables\Columns\TextColumn::make('selisih')->label('Selisih')
                    ->state(function (StockOpnameItem $r) {
                        if ($r->physical_qty === null) {
                            return null;
                        }
                        $d = bcsub((string) $r->physical_qty, (string) $r->system_qty, 4);

                        return (bccomp($d, '0', 4) > 0 ? '+' : '').rtrim(rtrim($d, '0'), '.') ?: '0';
                    })
                    ->placeholder('belum dihitung')
                    ->color(function (StockOpnameItem $r) {
                        if ($r->physical_qty === null) {
                            return 'gray';
                        }
                        $c = bccomp((string) $r->physical_qty, (string) $r->system_qty, 4);

                        return $c === 0 ? 'success' : ($c > 0 ? 'info' : 'danger');
                    }),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('counted')->label('Status hitung')
                    ->placeholder('Semua')->trueLabel('Sudah dihitung')->falseLabel('Belum dihitung')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('physical_qty'),
                        false: fn (Builder $query) => $query->whereNull('physical_qty'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->defaultSort('id');
    }
}
