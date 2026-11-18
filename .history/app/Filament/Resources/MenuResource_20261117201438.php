<?php

namespace App\Filament\Resources;

use App\Enums\RecipeStatus;
use App\Filament\Resources\MenuResource\Pages;
use App\Models\Menu;
use App\Models\Recipe;
use App\Services\MenuCostService;
use App\Services\RecipeCostService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static ?string $navigationIcon = 'heroicon-o-cake';

    protected static ?string $navigationGroup = 'Resep & Menu';

    protected static ?string $modelLabel = 'Menu';

    protected static ?string $pluralModelLabel = 'Menu';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informasi Dasar')->schema([
                Forms\Components\TextInput::make('name')->label('Nama menu')->required()->maxLength(255),
                Forms\Components\TextInput::make('code')->label('Kode')->required()->maxLength(30)
                    ->unique(ignoreRecord: true)->placeholder('MN-042'),
                Forms\Components\Select::make('category_id')->label('Kategori')
                    ->relationship('category', 'name', fn (Builder $q) => $q->where('type', 'menu'))
                    ->searchable()->preload(),
            ])->columns(3),

            Forms\Components\Section::make('Resep & HPP')->schema([
                Forms\Components\Select::make('recipe_id')->label('Pilih resep')->required()
                    ->options(fn () => Recipe::where('status', RecipeStatus::Active->value)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->live()
                    ->helperText('Satu porsi menu = satu satuan yield resep.'),
            ]),

            Forms\Components\Section::make('Harga Jual & Margin')->schema([
                Forms\Components\TextInput::make('selling_price')->label('Harga jual')->numeric()->required()
                    ->minValue(0)->prefix('Rp')->live(onBlur: true),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
                Forms\Components\Placeholder::make('summary')->label('')->columnSpanFull()
                    ->content(fn (Get $get) => self::summary($get)),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $cost = app(MenuCostService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $q) => $q->with(['recipe', 'category']))
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('category.name')->label('Kategori')->placeholder('-'),
                Tables\Columns\TextColumn::make('recipe.name')->label('Resep'),
                Tables\Columns\TextColumn::make('hpp')->label('HPP')
                    ->state(fn (Menu $r) => RecipeCostService::rupiah($cost->menuCost($r))),
                Tables\Columns\TextColumn::make('selling_price')->label('Harga jual')
                    ->formatStateUsing(fn ($state) => RecipeCostService::rupiah($state)),
                Tables\Columns\TextColumn::make('food_cost')->label('Food cost')
                    ->state(fn (Menu $r) => ($p = $cost->summarize($cost->menuCost($r), $r->selling_price)['food_cost_pct']) === null ? '-' : $p.'%'),
                Tables\Columns\TextColumn::make('margin')->label('Margin')
                    ->state(fn (Menu $r) => RecipeCostService::rupiah($cost->summarize($cost->menuCost($r), $r->selling_price)['margin'])),
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')->label('Kategori')
                    ->relationship('category', 'name', fn (Builder $q) => $q->where('type', 'menu')),
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMenus::route('/'),
            'create' => Pages\CreateMenu::route('/create'),
            'edit' => Pages\EditMenu::route('/{record}/edit'),
        ];
    }

    private static function summary(Get $get): HtmlString
    {
        $recipe = $get('recipe_id') ? Recipe::find($get('recipe_id')) : null;
        if (! $recipe) {
            return new HtmlString('<span style="color:#64707d">Pilih resep untuk melihat HPP.</span>');
        }

        $s = app(MenuCostService::class)->summarize($recipe->cost_per_portion, $get('selling_price'));
        $card = fn (string $label, string $value, string $color) => '<div style="flex:1;min-width:170px;padding:12px 16px;background:#efeae3;'
            ."border-left:4px solid {$color};border-radius:8px\"><div style=\"font-size:12px;color:#64707d\">{$label}</div>"
            .'<div style="font-size:20px;font-weight:700;color:#232d37">'.$value.'</div></div>';

        $html = $card('HPP per Porsi', e(RecipeCostService::rupiah($s['cost'])), '#78907e')
            .$card('HPP Total Resep', e(RecipeCostService::rupiah($recipe->total_cost)), '#1a365d')
            .$card('Food Cost', $s['food_cost_pct'] === null ? '-' : e($s['food_cost_pct']).'%', '#c26944');

        if ($s['margin_pct'] !== null) {
            $good = bccomp($s['margin'], '0', 2) >= 0;
            $html .= '<div style="flex:1;min-width:200px;padding:12px 16px;border-radius:8px;background:'.($good ? '#e6f2ea' : '#f8e4e1').'">'
                .'<div style="font-size:12px;color:#64707d">Estimasi Margin Kotor</div>'
                .'<div style="font-size:20px;font-weight:700;color:'.($good ? '#3f7a55' : '#b94a48').'">'
                .e(RecipeCostService::rupiah($s['margin'])).' ('.e($s['margin_pct']).'%)</div></div>';
        }

        return new HtmlString('<div style="display:flex;gap:12px;flex-wrap:wrap">'.$html.'</div>');
    }
}
