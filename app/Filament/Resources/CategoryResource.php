<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $modelLabel = 'Kategori';

    protected static ?string $pluralModelLabel = 'Kategori';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(255),
            Forms\Components\Select::make('type')->label('Jenis')->required()
                ->options(['ingredient' => 'Bahan baku', 'menu' => 'Menu']),
            Forms\Components\Select::make('parent_id')->label('Induk (opsional)')
                ->relationship('parent', 'name')->searchable()->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('type')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'menu' ? 'Menu' : 'Bahan baku'),
                Tables\Columns\TextColumn::make('parent.name')->label('Induk')->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Jenis')
                    ->options(['ingredient' => 'Bahan baku', 'menu' => 'Menu']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCategories::route('/')];
    }
}
