<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GoodsReceiptResource\Pages;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\RecipeCostService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class GoodsReceiptResource extends Resource
{
    protected static ?string $model = GoodsReceipt::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'Penerimaan Barang';

    protected static ?string $pluralModelLabel = 'Penerimaan Barang';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Penerimaan')->schema([
                Forms\Components\Select::make('branch_id')->label('Cabang')->required()
                    ->options(fn () => Branch::where('is_active', true)->pluck('name', 'id'))
                    ->default(fn () => auth()->user()?->branch_id),
                Forms\Components\Select::make('supplier_id')->label('Supplier')
                    ->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Forms\Components\DatePicker::make('receipt_date')->label('Tanggal terima')->required()
                    ->default(now())->maxDate(now()),
                Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
            ])->columns(3),

            Forms\Components\Section::make('Bahan yang diterima')->schema([
                Forms\Components\Repeater::make('items')->label('')
                    ->schema([
                        Forms\Components\Select::make('ingredient_id')->label('Bahan')->required()
                            ->options(fn () => Ingredient::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()->live()
                            ->afterStateUpdated(function ($state, Set $set) {
                                if ($state && ($ing = Ingredient::find($state))) {
                                    $set('unit_id', $ing->base_unit_id);
                                }
                            })->columnSpan(3),
                        Forms\Components\TextInput::make('qty')->label('Jumlah')->numeric()->required()
                            ->minValue(0.0001)->live(onBlur: true)->columnSpan(2),
                        Forms\Components\Select::make('unit_id')->label('Satuan')->required()
                            ->options(fn () => Unit::orderBy('symbol')->pluck('symbol', 'id'))
                            ->live()->columnSpan(2),
                        Forms\Components\TextInput::make('unit_price')->label('Harga / satuan')->numeric()->required()
                            ->minValue(0)->prefix('Rp')->live(onBlur: true)->columnSpan(3),
                        Forms\Components\Placeholder::make('subtotal')->label('Subtotal')
                            ->content(function (Get $get) {
                                $q = $get('qty');
                                $p = $get('unit_price');

                                return is_numeric($q) && is_numeric($p)
                                    ? RecipeCostService::rupiah(bcmul((string) $q, (string) $p, 2))
                                    : '-';
                            })->columnSpan(2),
                    ])
                    ->columns(12)->defaultItems(1)->minItems(1)->live()
                    ->addActionLabel('+ Tambah Bahan')->reorderable(false),
                Forms\Components\Placeholder::make('total')->label('Total pembelian')
                    ->content(function (Get $get) {
                        $total = '0';
                        foreach ($get('items') ?? [] as $i) {
                            if (is_numeric($i['qty'] ?? null) && is_numeric($i['unit_price'] ?? null)) {
                                $total = bcadd($total, bcmul((string) $i['qty'], (string) $i['unit_price'], 2), 2);
                            }
                        }

                        return new HtmlString('<strong style="font-size:20px">'.e(RecipeCostService::rupiah($total)).'</strong>');
                    }),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->schema([
                Infolists\Components\TextEntry::make('number')->label('No. Penerimaan'),
                Infolists\Components\TextEntry::make('receipt_date')->label('Tanggal')->date('d M Y'),
                Infolists\Components\TextEntry::make('branch.name')->label('Cabang'),
                Infolists\Components\TextEntry::make('supplier.name')->label('Supplier')->placeholder('-'),
                Infolists\Components\TextEntry::make('total_amount')->label('Total')
                    ->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state))->weight('bold'),
                Infolists\Components\TextEntry::make('creator.name')->label('Dicatat oleh')->placeholder('-'),
                Infolists\Components\TextEntry::make('notes')->label('Catatan')->placeholder('-')->columnSpanFull(),
            ])->columns(3),
            Infolists\Components\Section::make('Bahan diterima')->schema([
                Infolists\Components\RepeatableEntry::make('items')->label('')->schema([
                    Infolists\Components\TextEntry::make('ingredient.name')->label('Bahan'),
                    Infolists\Components\TextEntry::make('qty')->label('Jumlah')
                        ->formatStateUsing(fn ($state, $record) => rtrim(rtrim((string) $state, '0'), '.').' '.$record->unit?->symbol),
                    Infolists\Components\TextEntry::make('unit_price')->label('Harga / satuan')
                        ->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state)),
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')
                        ->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state)),
                ])->columns(4),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($q) => $q->with(['branch', 'supplier', 'creator']))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('No.')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('receipt_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Cabang'),
                Tables\Columns\TextColumn::make('supplier.name')->label('Supplier')->placeholder('-'),
                Tables\Columns\TextColumn::make('total_amount')->label('Total')
                    ->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state)),
                Tables\Columns\TextColumn::make('creator.name')->label('Oleh')->placeholder('-'),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGoodsReceipts::route('/'),
            'create' => Pages\CreateGoodsReceipt::route('/create'),
            'view' => Pages\ViewGoodsReceipt::route('/{record}'),
        ];
    }
}
