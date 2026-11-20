<?php

namespace App\Filament\Resources;

use App\Support\Access;
use App\Filament\Support\ScopesToBranch;
use App\Filament\Support\ChecksAccess;
use App\Enums\PlanStatus;
use App\Filament\Resources\ProductionPlanResource\Pages;
use App\Models\Branch;
use App\Models\Menu;
use App\Models\Package;
use App\Models\ProductionPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductionPlanResource extends Resource
{
    use ChecksAccess, ScopesToBranch;

    protected static string $accessKey = 'production_plans';

    protected static ?string $model = ProductionPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Produksi';

    protected static ?string $modelLabel = 'Rencana Produksi';

    protected static ?string $pluralModelLabel = 'Rencana Produksi';

    protected static ?int $navigationSort = 10;

    /** Hanya rencana Draft yang boleh diubah. */
    public static function canEdit($record): bool
    {
        return Access::can(auth()->user(), 'production_plans', 'update') && $record->status === PlanStatus::Draft;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Rencana')->schema([
                Forms\Components\DatePicker::make('plan_date')->label('Tanggal produksi')->required()->default(now()),
                Forms\Components\Select::make('branch_id')->label('Cabang')->required()
                    ->options(fn () => Access::branchOptions(auth()->user()))
                    ->default(fn () => Access::branchScope(auth()->user()))
                    ->disabled(fn () => Access::branchScope(auth()->user()) !== null)->dehydrated(),
                Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('Item yang akan diproduksi')
                ->description('Pilih menu atau paket beserta jumlah porsi / paket. Hitung kebutuhan bahan dari halaman rencana setelah disimpan.')
                ->schema([
                    Forms\Components\Repeater::make('items')->relationship()->label('')
                        ->schema([
                            Forms\Components\Select::make('item_type')->label('Jenis')->required()
                                ->options(['menu' => 'Menu', 'package' => 'Paket'])->default('menu')
                                ->live()->afterStateUpdated(fn (Set $set) => $set('item_id', null))
                                ->native(false)->selectablePlaceholder(false)->columnSpan(3),
                            Forms\Components\Select::make('item_id')->label('Nama')->required()->searchable()
                                ->options(fn (Get $get) => $get('item_type') === 'package'
                                    ? Package::where('is_active', true)->orderBy('name')->pluck('name', 'id')
                                    : Menu::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                ->columnSpan(5),
                            Forms\Components\TextInput::make('qty')->label('Jumlah (porsi / paket)')->numeric()->required()
                                ->minValue(1)->columnSpan(3),
                        ])
                        ->columns(11)->defaultItems(1)->minItems(1)
                        ->addActionLabel('+ Tambah Item')->reorderable(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['branch', 'creator'])->withCount('items'))
            ->columns([
                Tables\Columns\TextColumn::make('plan_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Cabang'),
                Tables\Columns\TextColumn::make('items_count')->label('Item')->suffix(' item'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => self::statusLabel($state))
                    ->color(fn ($state) => self::statusColor($state)),
                Tables\Columns\TextColumn::make('creator.name')->label('Dibuat oleh')->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'confirmed' => 'Confirmed', 'done' => 'Done']),
                Tables\Filters\SelectFilter::make('branch_id')->label('Cabang')
                    ->options(fn () => Access::branchOptions(auth()->user())),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Buka'),
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('plan_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductionPlans::route('/'),
            'create' => Pages\CreateProductionPlan::route('/create'),
            'view' => Pages\ViewProductionPlan::route('/{record}'),
            'edit' => Pages\EditProductionPlan::route('/{record}/edit'),
        ];
    }

    public static function statusValue(mixed $state): string
    {
        return $state instanceof PlanStatus ? $state->value : (string) $state;
    }

    public static function statusLabel(mixed $state): string
    {
        return ucfirst(self::statusValue($state));
    }

    public static function statusColor(mixed $state): string
    {
        return match (self::statusValue($state)) {
            'confirmed' => 'success',
            'done' => 'info',
            default => 'gray',
        };
    }
}
