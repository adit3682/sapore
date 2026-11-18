<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnitConversionResource\Pages;
use App\Models\UnitConversion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UnitConversionResource extends Resource
{
    protected static ?string $model = UnitConversion::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $modelLabel = 'Konversi Satuan';

    protected static ?string $pluralModelLabel = 'Konversi Satuan';

    protected static ?int $navigationSort = 31;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('from_unit_id')->label('Dari satuan')
                ->relationship('fromUnit', 'symbol')->required()->searchable()->preload(),
            Forms\Components\Select::make('to_unit_id')->label('Ke satuan')
                ->relationship('toUnit', 'symbol')->required()->searchable()->preload()
                ->different('from_unit_id'),
            Forms\Components\TextInput::make('multiplier')->label('Pengali')->required()->numeric()->minValue(0.000001)
                ->helperText('1 [dari satuan] = [pengali] [ke satuan]. Contoh: 1 kg = 1000 g. Arah sebaliknya dihitung otomatis.'),
            Forms\Components\Select::make('ingredient_id')->label('Khusus bahan (opsional)')
                ->relationship('ingredient', 'name')->searchable()->preload()
                ->placeholder('Berlaku untuk semua bahan')
                ->helperText('Isi bila konversi hanya untuk satu bahan, mis. 1 butir telur = 0,06 kg.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('fromUnit.symbol')->label('Dari')->badge(),
                Tables\Columns\TextColumn::make('multiplier')->label('Pengali')->numeric(decimalPlaces: 6),
                Tables\Columns\TextColumn::make('toUnit.symbol')->label('Ke')->badge(),
                Tables\Columns\TextColumn::make('ingredient.name')->label('Bahan')->placeholder('Umum'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageUnitConversions::route('/')];
    }
}
