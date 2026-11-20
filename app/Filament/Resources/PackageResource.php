<?php

namespace App\Filament\Resources;

use App\Support\Access;
use App\Filament\Support\ChecksAccess;
use App\Filament\Resources\PackageResource\Pages;
use App\Models\Menu;
use App\Models\Package;
use App\Services\MenuCostService;
use App\Services\RecipeCostService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PackageResource extends Resource
{
    use ChecksAccess;

    protected static string $accessKey = 'menus';

    protected static ?string $model = Package::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Resep & Menu';

    protected static ?string $modelLabel = 'Paket';

    protected static ?string $pluralModelLabel = 'Paket / Combo';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informasi Paket')->schema([
                Forms\Components\TextInput::make('name')->label('Nama paket')->required()->maxLength(255),
                Forms\Components\TextInput::make('code')->label('Kode')->required()->maxLength(30)
                    ->unique(ignoreRecord: true)->placeholder('PKT-001'),
                Forms\Components\TextInput::make('selling_price')->label('Harga jual')->numeric()->required()
                    ->minValue(0)->prefix('Rp')->live(onBlur: true),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            ])->columns(4),

            Forms\Components\Section::make('Isi Paket')->schema([
                Forms\Components\Repeater::make('items')->relationship()->label('')
                    ->schema([
                        Forms\Components\Select::make('menu_id')->label('Menu')->required()
                            ->options(fn () => Menu::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()->live()->columnSpan(8),
                        Forms\Components\TextInput::make('qty')->label('Jumlah')->numeric()->required()
                            ->default(1)->minValue(0.01)->live(onBlur: true)->columnSpan(4),
                    ])
                    ->columns(12)->defaultItems(1)->minItems(1)->live()
                    ->addActionLabel('+ Tambah Menu')->reorderable(false),
                Forms\Components\Placeholder::make('summary')->label('')
                    ->content(fn (Get $get) => self::summary($get)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $cost = app(MenuCostService::class);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('items.menu.recipe'))
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('items_count')->label('Isi')->counts('items')->suffix(' menu'),
                Tables\Columns\TextColumn::make('hpp')->label('HPP')
                    ->state(fn (Package $r) => RecipeCostService::rupiah($cost->packageCost($r))),
                Tables\Columns\TextColumn::make('selling_price')->label('Harga jual')
                    ->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state)),
                Tables\Columns\TextColumn::make('margin')->label('Margin')
                    ->state(function (Package $r) use ($cost) {
                        $state = $cost->summarize($cost->packageCost($r), $r->selling_price);

                        return RecipeCostService::rupiah($state['margin']).($state['margin_pct'] !== null ? " ({$state['margin_pct']}%)" : '');
                    }),
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif')
                    ->disabled(fn () => ! Access::can(auth()->user(), 'menus', 'update')),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }

    private static function summary(Get $get): HtmlString
    {
        $items = collect($get('items') ?? [])->filter(fn ($i) => ! empty($i['menu_id']) && is_numeric($i['qty'] ?? null));
        if ($items->isEmpty()) {
            return new HtmlString('<span style="color:#64707d">Tambahkan menu untuk melihat HPP paket.</span>');
        }

        $svc = app(MenuCostService::class);
        $menus = Menu::with('recipe')->whereIn('id', $items->pluck('menu_id'))->get()->keyBy('id');

        $total = '0';
        foreach ($items as $i) {
            if ($m = $menus[$i['menu_id']] ?? null) {
                $total = bcadd($total, bcmul($svc->menuCost($m), (string) $i['qty'], 4), 4);
            }
        }

        $state = $svc->summarize($total, $get('selling_price'));
        $good = bccomp($state['margin'], '0', 2) >= 0;

        $html = '<div style="display:flex;gap:12px;flex-wrap:wrap">'
            .'<div style="flex:1;min-width:170px;padding:12px 16px;background:#efeae3;border-left:4px solid #1a365d;border-radius:8px">'
            .'<div style="font-size:12px;color:#64707d">HPP Paket</div><div style="font-size:20px;font-weight:700">'
            .e(RecipeCostService::rupiah($state['cost'])).'</div></div>';

        if ($state['margin_pct'] !== null) {
            $html .= '<div style="flex:1;min-width:170px;padding:12px 16px;background:#efeae3;border-left:4px solid #c26944;border-radius:8px">'
                .'<div style="font-size:12px;color:#64707d">Food Cost</div><div style="font-size:20px;font-weight:700">'.e($state['food_cost_pct']).'%</div></div>'
                .'<div style="flex:1;min-width:200px;padding:12px 16px;border-radius:8px;background:'.($good ? '#e6f2ea' : '#f8e4e1').'">'
                .'<div style="font-size:12px;color:#64707d">Estimasi Margin Kotor</div>'
                .'<div style="font-size:20px;font-weight:700;color:'.($good ? '#3f7a55' : '#b94a48').'">'
                .e(RecipeCostService::rupiah($state['margin'])).' ('.e($state['margin_pct']).'%)</div></div>';
        }

        return new HtmlString($html.'</div>');
    }
}
