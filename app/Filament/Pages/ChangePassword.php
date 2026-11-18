<?php

namespace App\Filament\Pages;

use App\Services\PasswordService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

/** Ganti password sendiri. Juga halaman tujuan paksa untuk akun must_change_password. */
class ChangePassword extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'ganti-password';

    protected static ?string $title = 'Ganti Password';

    protected static string $view = 'filament.pages.change-password';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function mustChange(): bool
    {
        return (bool) auth()->user()?->must_change_password;
    }

    public function form(Form $form): Form
    {
        $rules = app(PasswordService::class)->rules();

        return $form->schema([
            Forms\Components\TextInput::make('current_password')->label('Password saat ini')
                ->password()->revealable()->required(),
            Forms\Components\TextInput::make('password')->label('Password baru')
                ->password()->revealable()->required()->rules($rules)->confirmed()
                ->helperText('Minimal 8 karakter, mengandung huruf dan angka.'),
            Forms\Components\TextInput::make('password_confirmation')->label('Ulangi password baru')
                ->password()->revealable()->required()->dehydrated(false),
        ])->statePath('data')->columns(1);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = auth()->user();

        try {
            app(PasswordService::class)->change($user, $data['current_password'], $data['password']);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Password belum bisa diganti')
                ->body(collect($e->errors())->flatten()->implode(' '))->send();

            return;
        }

        // jaga sesi ini tetap valid setelah hash password berubah
        session()->put('password_hash_'.Filament::getAuthGuard(), $user->fresh()->password);

        Notification::make()->success()->title('Password berhasil diganti')->send();
        $this->redirect(Filament::getUrl());
    }
}
