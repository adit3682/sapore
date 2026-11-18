<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IngredientResource\Pages;
use App\Models\Ingredient;
use App\Services\RecipeCostService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientResource extends Resource
{
    protected static ?string $model = Ingredient::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $modelLabel = 'Bahan Baku';

    protected static ?string $pluralModelLabel = 'Bahan Baku';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')->label('Kode')->required()->maxLength(30)
                ->unique(ignoreRecord: true)->placeholder('BB-001'),
            Forms\Components\TextInput::make('name')->label('Nama bahan')->required()->maxLength(255),
            Forms\Components\Select::make('category_id')->label('Kategori')
                ->relationship('category', 'name', fn (Builder $query) => $query->where('type', 'ingredient'))
                ->searchable()->preload(),
            Forms\Components\Select::make('base_unit_id')->label('Satuan dasar')
                ->relationship('baseUnit', 'symbol')->required()->searchable()->preload()
                ->disabled(fn (?Ingredient $record) => (bool) $record?->exists)
                ->helperText('Tidak bisa diubah setelah dibuat. Stok & harga selalu dalam satuan ini.'),
            Forms\Components\TextInput::make('min_stock')->label('Stok minimum')->numeric()->default(0)->minValue(0),
            Forms\Components\TextInput::make('max_stock')->label('Stok maksimum')->numeric()->minValue(0),
            Forms\Components\TextInput::make('avg_cost')->label('Harga rata-rata / satuan dasar')
                ->numeric()->default(0)->minValue(0)->prefix('Rp')
                ->helperText('Nanti diperbarui otomatis oleh penerimaan barang. Isi manual untuk saldo awal.'),
            Forms\Components\TextInput::make('last_cost')->label('Harga beli terakhir / satuan dasar')
                ->numeric()->default(0)->minValue(0)->prefix('Rp'),
            Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('baseUnit'))
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('category.name')->label('Kategori')->placeholder('-'),
                Tables\Columns\TextColumn::make('stocks_sum_quantity')->label('Stok total')
                    ->sum('stocks', 'quantity')->default(0)->numeric(decimalPlaces: 2)
                    ->suffix(fn (Ingredient $r) => ' '.$r->baseUnit?->symbol),
                Tables\Columns\TextColumn::make('min_stock')->label('Min')->numeric(decimalPlaces: 2)
                    ->suffix(fn (Ingredient $r) => ' '.$r->baseUnit?->symbol),
                Tables\Columns\TextColumn::make('avg_cost')->label('Harga rata-rata')
                    ->formatStateUsing(fn ($state, Ingredient $r) => RecipeCostService::rupiah($state).' / '.$r->baseUnit?->symbol),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')->label('Kategori')
                    ->relationship('category', 'name', fn (Builder $query) => $query->where('type', 'ingredient')),
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    // harga berubah -> HPP resep yang memakai bahan ini ikut diperbarui
                    ->after(fn (Ingredient $record) => app(RecipeCostService::class)->recalculateForIngredient($record->id)),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageIngredients::route('/')];
    }
}
