<?php

namespace App\Filament\Resources;

use App\Enums\StockOpnameStatus;
use App\Filament\Resources\StockOpnameResource\Pages;
use App\Filament\Resources\StockOpnameResource\RelationManagers\ItemsRelationManager;
use App\Models\Branch;
use App\Models\StockOpname;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StockOpnameResource extends Resource
{
    protected static ?string $model = StockOpname::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'Stock Opname';

    protected static ?string $pluralModelLabel = 'Stock Opname';

    protected static ?int $navigationSort = 25;

    private const LABELS = ['draft' => 'Draft', 'confirmed' => 'Dikonfirmasi', 'cancelled' => 'Dibatalkan'];

    public static function statusValue(mixed $state): string
    {
        return $state instanceof StockOpnameStatus ? $state->value : (string) $state;
    }

    public static function canEdit($record): bool
    {
        return $record->status === StockOpnameStatus::Draft;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Stock Opname')->schema([
                Forms\Components\Select::make('branch_id')->label('Cabang')->required()
                    ->options(fn () => Branch::where('is_active', true)->pluck('name', 'id'))
                    ->default(fn () => auth()->user()?->branch_id)
                    ->disabled(fn (string $operation) => $operation === 'edit'),
                Forms\Components\DatePicker::make('opname_date')->label('Tanggal')->required()
                    ->default(now())->disabled(fn (string $operation) => $operation === 'edit'),
                Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->schema([
                Infolists\Components\TextEntry::make('number')->label('No.'),
                Infolists\Components\TextEntry::make('status')->badge()
                    ->formatStateUsing(fn ($state) => self::LABELS[self::statusValue($state)] ?? $state)
                    ->color(fn ($state) => match (self::statusValue($state)) {
                        'confirmed' => 'success', 'cancelled' => 'gray', default => 'warning',
                    }),
                Infolists\Components\TextEntry::make('opname_date')->label('Tanggal')->date('d M Y'),
                Infolists\Components\TextEntry::make('branch.name')->label('Cabang'),
                Infolists\Components\TextEntry::make('confirmer.name')->label('Dikonfirmasi oleh')->placeholder('-'),
                Infolists\Components\TextEntry::make('confirmed_at')->label('Waktu konfirmasi')->dateTime('d M Y H:i')->placeholder('-'),
                Infolists\Components\TextEntry::make('notes')->label('Catatan')->placeholder('-')->columnSpanFull(),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('branch')->withCount([
                'items',
                'items as counted_count' => fn ($i) => $i->whereNotNull('physical_qty'),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('No.')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('opname_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Cabang'),
                Tables\Columns\TextColumn::make('progress')->label('Dihitung')
                    ->state(fn (StockOpname $r) => "{$r->counted_count} / {$r->items_count}"),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => self::LABELS[self::statusValue($state)] ?? $state)
                    ->color(fn ($state) => match (self::statusValue($state)) {
                        'confirmed' => 'success', 'cancelled' => 'gray', default => 'warning',
                    }),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(self::LABELS)])
            ->actions([
                Tables\Actions\EditAction::make()->label('Lanjutkan hitung'),
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockOpnames::route('/'),
            'create' => Pages\CreateStockOpname::route('/create'),
            'view' => Pages\ViewStockOpname::route('/{record}'),
            'edit' => Pages\EditStockOpname::route('/{record}/edit'),
        ];
    }
}
