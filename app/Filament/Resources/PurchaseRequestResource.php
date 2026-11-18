<?php

namespace App\Filament\Resources;

use App\Filament\Support\ScopesToBranch;
use App\Filament\Support\ChecksAccess;
use App\Enums\PurchaseRequestStatus;
use App\Filament\Resources\PurchaseRequestResource\Pages;
use App\Models\PurchaseRequest;
use App\Services\RecipeCostService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PurchaseRequestResource extends Resource
{
    use ChecksAccess, ScopesToBranch;

    protected static string $accessKey = 'purchase_requests';

    protected static ?string $model = PurchaseRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Produksi';

    protected static ?string $modelLabel = 'Purchase Request';

    protected static ?string $pluralModelLabel = 'Purchase Request';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false; // dibuat dari Rencana Produksi
    }

    private const LABELS = [
        'draft' => 'Draft', 'submitted' => 'Diajukan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'ordered' => 'Dipesan',
    ];

    public static function statusValue(mixed $state): string
    {
        return $state instanceof PurchaseRequestStatus ? $state->value : (string) $state;
    }

    public static function statusColor(mixed $state): string
    {
        return match (self::statusValue($state)) {
            'approved', 'ordered' => 'success', 'rejected' => 'danger', 'submitted' => 'info', default => 'warning',
        };
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->schema([
                Infolists\Components\TextEntry::make('number')->label('No. PR'),
                Infolists\Components\TextEntry::make('status')->badge()
                    ->formatStateUsing(fn ($state) => self::LABELS[self::statusValue($state)] ?? $state)
                    ->color(fn ($state) => self::statusColor($state)),
                Infolists\Components\TextEntry::make('branch.name')->label('Cabang'),
                Infolists\Components\TextEntry::make('plan.plan_date')->label('Rencana produksi')->date('d M Y')->placeholder('-'),
                Infolists\Components\TextEntry::make('total_estimated')->label('Total estimasi')
                    ->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state))->weight('bold'),
                Infolists\Components\TextEntry::make('creator.name')->label('Dibuat oleh')->placeholder('-'),
                Infolists\Components\TextEntry::make('approver.name')->label('Diputuskan oleh')->placeholder('-'),
            ])->columns(3),
            Infolists\Components\Section::make('Daftar Belanja')->schema([
                Infolists\Components\RepeatableEntry::make('items')->label('')->schema([
                    Infolists\Components\TextEntry::make('ingredient.name')->label('Bahan'),
                    Infolists\Components\TextEntry::make('qty')->label('Jumlah')
                        ->formatStateUsing(fn ($state, $record) => rtrim(rtrim((string) $state, '0'), '.').' '.$record->unit?->symbol),
                    Infolists\Components\TextEntry::make('estimated_cost')->label('Estimasi')
                        ->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state)),
                ])->columns(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['branch', 'plan']))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('No.')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Dibuat')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Cabang'),
                Tables\Columns\TextColumn::make('plan.plan_date')->label('Rencana produksi')->date('d M Y')->placeholder('-'),
                Tables\Columns\TextColumn::make('total_estimated')->label('Estimasi')
                    ->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state)),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => self::LABELS[self::statusValue($state)] ?? $state)
                    ->color(fn ($state) => self::statusColor($state)),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(self::LABELS)])
            ->actions([Tables\Actions\ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseRequests::route('/'),
            'view' => Pages\ViewPurchaseRequest::route('/{record}'),
        ];
    }
}
