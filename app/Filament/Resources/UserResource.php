<?php

namespace App\Filament\Resources;

use App\Enums\Role;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Support\ChecksAccess;
use App\Models\User;
use App\Services\UserAdminService;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    use ChecksAccess;

    protected static string $accessKey = 'users';

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $modelLabel = 'Pengguna';

    protected static ?string $pluralModelLabel = 'Pengguna';

    protected static ?int $navigationSort = 20;

    public const ROLE_LABELS = [
        'owner' => 'Owner', 'manager' => 'Manager', 'chef' => 'Chef', 'inventory' => 'Inventory', 'cashier' => 'Kasir',
    ];

    /** Owner: semua akun. Manager: hanya staf cabangnya. Lainnya: tidak ada. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = auth()->user();
        $role = Access::roleOf($actor);

        return match ($role) {
            Role::Owner => $query,
            Role::Manager => $query->where('branch_id', $actor->branch_id)->whereIn('role', UserAdminService::STAFF_ROLES),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public static function canEdit($record): bool
    {
        return app(UserAdminService::class)->canManage(auth()->user(), $record);
    }

    // Akun tidak dihapus (riwayat transaksi merujuk ke akun): nonaktifkan.
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
        $service = app(UserAdminService::class);
        $isSelf = fn (?User $record) => $record && $record->id === auth()->id();

        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(255),
            Forms\Components\TextInput::make('email')->label('Email')->email()->required()->maxLength(255)
                ->unique(ignoreRecord: true),
            Forms\Components\Select::make('role')->label('Peran')->required()->live()
                ->options(fn () => collect($service->assignableRoles(auth()->user()))
                    ->mapWithKeys(fn ($r) => [$r => self::ROLE_LABELS[$r] ?? $r]))
                ->disabled(fn (?User $record) => $isSelf($record))->dehydrated(fn (?User $record) => ! $isSelf($record)),
            Forms\Components\Select::make('branch_id')->label('Cabang')
                ->options(fn () => Access::branchOptions(auth()->user()))
                ->default(fn () => Access::branchScope(auth()->user()))
                ->visible(fn (Get $get) => $get('role') !== 'owner')
                ->required(fn (Get $get) => $get('role') !== 'owner')
                ->disabled(fn () => Access::branchScope(auth()->user()) !== null)->dehydrated()
                ->helperText('Owner adalah akun pusat dan tidak terikat cabang.'),
            Forms\Components\TextInput::make('password')->label('Password sementara')
                ->password()->revealable()->required()->minLength(8)
                ->default(fn () => Str::random(12))
                ->visibleOn('create')
                ->helperText('Sampaikan ke pengguna. Mereka wajib menggantinya saat login pertama.'),
            Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)
                ->disabled(fn (?User $record) => $isSelf($record))->dehydrated(fn (?User $record) => ! $isSelf($record)),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('branch'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('role')->label('Peran')->badge()
                    ->formatStateUsing(fn ($state) => self::ROLE_LABELS[$state instanceof Role ? $state->value : (string) $state] ?? $state),
                Tables\Columns\TextColumn::make('branch.name')->label('Cabang')->placeholder('Pusat'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
                Tables\Columns\IconColumn::make('must_change_password')->label('Wajib ganti password')->boolean()
                    ->trueColor('warning')->falseColor('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')->label('Peran')->options(self::ROLE_LABELS),
                Tables\Filters\SelectFilter::make('branch_id')->label('Cabang')
                    ->options(fn () => Access::branchOptions(auth()->user())),
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->using(function (User $record, array $data, Tables\Actions\EditAction $action) {
                        try {
                            return app(UserAdminService::class)->update(auth()->user(), $record, $data);
                        } catch (\Throwable $e) {
                            self::fail($e);
                            $action->halt();
                        }
                    }),
                Tables\Actions\Action::make('resetPassword')->label('Reset password')->icon('heroicon-o-key')->color('warning')
                    ->visible(fn (User $record) => $record->id !== auth()->id() && self::canEdit($record))
                    ->form([
                        Forms\Components\TextInput::make('password')->label('Password sementara baru')
                            ->password()->revealable()->required()->minLength(8)->default(fn () => Str::random(12)),
                    ])
                    ->modalDescription('Pengguna wajib menggantinya saat login berikutnya, dan semua sesi API-nya dicabut.')
                    ->action(function (User $record, array $data) {
                        try {
                            app(UserAdminService::class)->resetPassword(auth()->user(), $record, $data['password']);
                            Notification::make()->success()->title('Password direset')
                                ->body('Sampaikan password sementara ke pengguna.')->send();
                        } catch (\Throwable $e) {
                            self::fail($e);
                        }
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageUsers::route('/')];
    }

    public static function fail(\Throwable $e): void
    {
        Notification::make()->danger()->title('Tidak bisa diproses')->body($e->getMessage())->send();
    }
}
