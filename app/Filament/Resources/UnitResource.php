<?php

namespace App\Filament\Resources;

use App\Filament\Support\ChecksAccess;
use App\Filament\Resources\UnitResource\Pages;
use App\Models\Unit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UnitResource extends Resource
{
    use ChecksAccess;

    protected static string $accessKey = 'master_data';

    protected static ?string $model = Unit::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $modelLabel = 'Satuan';

    protected static ?string $pluralModelLabel = 'Satuan';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(50),
            Forms\Components\TextInput::make('symbol')->label('Simbol')->required()->maxLength(10)
                ->unique(ignoreRecord: true)->helperText('Contoh: kg, g, L, ml, butir'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('symbol')->label('Simbol')->badge()->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('symbol');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageUnits::route('/')];
    }
}
