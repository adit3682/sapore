<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockResource\Pages;
use App\Models\Branch;
use App\Models\Stock;
use App\Models\Unit;
use App\Services\RecipeCostService;
use App\Services\StockService;
use App\Support\StockStatus;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockResource extends Resource
{
    protected static ?string $model = Stock::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'Stok Bahan';

    protected static ?string $pluralModelLabel = 'Stok Bahan';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['ingredient.baseUnit', 'ingredient.category', 'branch']))
            ->columns([
                Tables\Columns\TextColumn::make('ingredient.code')->label('Kode')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('ingredient.name')->label('Nama Bahan')->searchable(),
                Tables\Columns\TextColumn::make('ingredient.category.name')->label('Kategori')->placeholder('-'),
                Tables\Columns\TextColumn::make('quantity')->label('Stok')->numeric(decimalPlaces: 2)->sortable()
                    ->suffix(fn (Stock $r) => ' '.$r->ingredient->baseUnit?->symbol),
                Tables\Columns\TextColumn::make('ingredient.min_stock')->label('Min')->numeric(decimalPlaces: 2)
                    ->suffix(fn (Stock $r) => ' '.$r->ingredient->baseUnit?->symbol),
                Tables\Columns\TextColumn::make('nilai')->label('Nilai')
                    ->state(fn (Stock $r) => RecipeCostService::rupiah(bcmul((string) $r->quantity, (string) $r->ingredient->avg_cost, 2))),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Stock $r) => StockStatus::for($r->quantity, $r->ingredient->min_stock))
                    ->formatStateUsing(fn (string $state) => StockStatus::label($state))
                    ->color(fn (string $state) => StockStatus::color($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')->label('Cabang')
                    ->options(fn () => Branch::where('is_active', true)->pluck('name', 'id'))
                    ->default(fn () => auth()->user()?->branch_id)
                    ->query(fn (Builder $q, array $data) => $q->when($data['value'] ?? null, fn ($q, $v) => $q->where('stocks.branch_id', $v))),
                Tables\Filters\SelectFilter::make('status')->label('Status')
                    ->options(['critical' => 'Kritis', 'low' => 'Rendah', 'ok' => 'Aman'])
                    ->query(function (Builder $q, array $data) {
                        $min = '(select min_stock from ingredients where ingredients.id = stocks.ingredient_id)';

                        return match ($data['value'] ?? null) {
                            'critical' => $q->whereRaw("{$min} > 0 and stocks.quantity < 0.5 * {$min}"),
                            'low' => $q->whereRaw("{$min} > 0 and stocks.quantity >= 0.5 * {$min} and stocks.quantity < {$min}"),
                            'ok' => $q->whereRaw("({$min} <= 0 or stocks.quantity >= {$min})"),
                            default => $q,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('waste')->label('Waste')->icon('heroicon-o-trash')->color('danger')
                    ->form(fn (Stock $record) => [
                        Forms\Components\TextInput::make('qty')->label('Jumlah terbuang')->numeric()->required()->minValue(0.0001),
                        Forms\Components\Select::make('unit_id')->label('Satuan')->required()
                            ->options(fn () => Unit::orderBy('symbol')->pluck('symbol', 'id'))
                            ->default($record->ingredient->base_unit_id),
                        Forms\Components\Textarea::make('notes')->label('Alasan')->required()->rows(2),
                    ])
                    ->action(function (Stock $record, array $data) {
                        self::run(fn () => app(StockService::class)->waste(
                            $record->ingredient_id, $record->branch_id, $data['qty'], (int) $data['unit_id'], $data['notes'], auth()->id()
                        ), 'Waste dicatat');
                    }),
                Tables\Actions\Action::make('adjust')->label('Penyesuaian')->icon('heroicon-o-adjustments-horizontal')
                    ->form(fn (Stock $record) => [
                        Forms\Components\TextInput::make('delta')->label('Perubahan stok (+ / -)')->numeric()->required()
                            ->helperText('Positif menambah, negatif mengurangi. Untuk hitung fisik massal gunakan Stock Opname.'),
                        Forms\Components\Select::make('unit_id')->label('Satuan')->required()
                            ->options(fn () => Unit::orderBy('symbol')->pluck('symbol', 'id'))
                            ->default($record->ingredient->base_unit_id),
                        Forms\Components\Textarea::make('notes')->label('Alasan')->required()->rows(2),
                    ])
                    ->action(function (Stock $record, array $data) {
                        self::run(fn () => app(StockService::class)->adjust(
                            $record->ingredient_id, $record->branch_id, $data['delta'], (int) $data['unit_id'], $data['notes'], auth()->id()
                        ), 'Penyesuaian dicatat');
                    }),
            ])
            ->defaultSort('ingredient_id');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListStocks::route('/')];
    }

    private static function run(\Closure $fn, string $successTitle): void
    {
        try {
            $fn();
            Notification::make()->success()->title($successTitle)->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Gagal')->body($e->getMessage())->send();
        }
    }
}
