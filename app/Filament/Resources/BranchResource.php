<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BranchResource\Pages;
use App\Filament\Support\ChecksAccess;
use App\Models\Branch;
use App\Services\SettingsService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BranchResource extends Resource
{
    use ChecksAccess;

    protected static string $accessKey = 'branches';

    protected static ?string $model = Branch::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $modelLabel = 'Cabang';

    protected static ?string $pluralModelLabel = 'Cabang';

    protected static ?int $navigationSort = 10;

    // Cabang dipakai di hampir semua data, jadi tidak dihapus: nonaktifkan lewat toggle Aktif.
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Nama cabang')->required()->maxLength(255),
            Forms\Components\TextInput::make('phone')->label('Telepon')->tel()->maxLength(30),
            Forms\Components\Textarea::make('address')->label('Alamat')->rows(2),
            Forms\Components\TextInput::make('tax_rate')->label('Pajak cabang')
                ->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')
                ->placeholder(fn () => 'Ikut umum ('.app(SettingsService::class)->taxRate().'%)')
                ->helperText('Kosongkan untuk mengikuti Pengaturan Usaha. Hanya berlaku untuk order baru.'),
            Forms\Components\TextInput::make('service_rate')->label('Service charge cabang')
                ->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')
                ->placeholder(fn () => 'Ikut umum ('.app(SettingsService::class)->serviceRate().'%)')
                ->helperText('Isi 0 bila cabang ini tidak memungut service charge.'),
            Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('Telepon')->placeholder('-'),
                Tables\Columns\TextColumn::make('tax')->label('Pajak')
                    ->state(fn (Branch $r) => $r->tax_rate === null ? 'Umum' : rtrim(rtrim((string) $r->tax_rate, '0'), '.').'%'),
                Tables\Columns\TextColumn::make('service')->label('Service')
                    ->state(fn (Branch $r) => $r->service_rate === null ? 'Umum' : (rtrim(rtrim((string) $r->service_rate, '0'), '.') ?: '0').'%'),
                Tables\Columns\TextColumn::make('users_count')->label('Pengguna')->counts('users'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageBranches::route('/')];
    }
}
