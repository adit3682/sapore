<?php

namespace App\Filament\Resources;

use App\Filament\Support\ScopesToBranch;
use App\Filament\Support\ChecksAccess;
use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Services\RecipeCostService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    use ChecksAccess, ScopesToBranch;

    protected static string $accessKey = 'orders';

    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $modelLabel = 'Order';

    protected static ?string $pluralModelLabel = 'Riwayat Order';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false; // order dibuat dari Kasir
    }

    private const STATUS = ['open' => 'Tersimpan', 'paid' => 'Dibayar', 'void' => 'Dibatalkan'];

    private const METHOD = ['cash' => 'Tunai', 'qris' => 'QRIS', 'card' => 'Kartu', 'transfer' => 'Transfer'];

    public static function statusValue(mixed $state): string
    {
        return $state instanceof OrderStatus ? $state->value : (string) $state;
    }

    private static function statusColor(mixed $state): string
    {
        return match (self::statusValue($state)) {
            'paid' => 'success', 'void' => 'gray', default => 'warning',
        };
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $rp = fn ($v) => RecipeCostService::rupiah($v);

        return $infolist->schema([
            Infolists\Components\Section::make()->schema([
                Infolists\Components\TextEntry::make('number')->label('No. Order'),
                Infolists\Components\TextEntry::make('status')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUS[self::statusValue($state)] ?? $state)
                    ->color(fn ($state) => self::statusColor($state)),
                Infolists\Components\TextEntry::make('created_at')->label('Dibuat')->dateTime('d M Y H:i'),
                Infolists\Components\TextEntry::make('table_label')->label('Meja')->placeholder('-'),
                Infolists\Components\TextEntry::make('cashier.name')->label('Kasir')->placeholder('-'),
                Infolists\Components\TextEntry::make('payment_method')->label('Pembayaran')
                    ->formatStateUsing(fn ($state) => self::METHOD[$state] ?? $state)->placeholder('-'),
            ])->columns(3),
            Infolists\Components\Section::make('Item')->schema([
                Infolists\Components\RepeatableEntry::make('items')->label('')->schema([
                    Infolists\Components\TextEntry::make('name')->label('Menu'),
                    Infolists\Components\TextEntry::make('qty')->label('Jumlah')
                        ->formatStateUsing(fn ($state) => rtrim(rtrim((string) $state, '0'), '.')),
                    Infolists\Components\TextEntry::make('unit_price')->label('Harga')->formatStateUsing($rp),
                    Infolists\Components\TextEntry::make('line_total')->label('Subtotal')->formatStateUsing($rp),
                ])->columns(4),
            ]),
            Infolists\Components\Section::make('Pembayaran')->schema([
                Infolists\Components\TextEntry::make('subtotal')->formatStateUsing($rp),
                Infolists\Components\TextEntry::make('tax_amount')->label('Pajak')->formatStateUsing($rp),
                Infolists\Components\TextEntry::make('service_amount')->label('Service')->formatStateUsing($rp),
                Infolists\Components\TextEntry::make('total')->formatStateUsing($rp)->weight('bold'),
                Infolists\Components\TextEntry::make('amount_paid')->label('Diterima')->formatStateUsing($rp)->placeholder('-'),
                Infolists\Components\TextEntry::make('change_amount')->label('Kembalian')->formatStateUsing($rp)->placeholder('-'),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['cashier', 'branch']))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('No.')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('table_label')->label('Meja')->placeholder('-'),
                Tables\Columns\TextColumn::make('cashier.name')->label('Kasir')->placeholder('-'),
                Tables\Columns\TextColumn::make('total')->label('Total')->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state)),
                Tables\Columns\TextColumn::make('payment_method')->label('Bayar')
                    ->formatStateUsing(fn ($state) => self::METHOD[$state] ?? $state)->placeholder('-'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUS[self::statusValue($state)] ?? $state)
                    ->color(fn ($state) => self::statusColor($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::STATUS),
                Tables\Filters\Filter::make('today')->label('Hari ini')
                    ->query(fn ($query) => $query->whereDate('created_at', today())),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
