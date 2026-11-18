<?php

namespace App\Providers\Filament;

use Filament\Navigation\MenuItem;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Filament\Pages\ChangePassword;
use App\Support\SaporePalette;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel SAPORÉ: gabungan semua pengaturan dari langkah sebelumnya (grup navigasi, notifikasi database,
 * widget dashboard) ditambah tema visual. Tema tinggal di public/css/sapore.css (tanpa build Vite).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()

            // Merek & tema
            ->brandName('SAPORÉ')
            ->brandLogo(fn () => view('filament.brand.logo'))
            ->brandLogoHeight('2.5rem')
            ->favicon(fn () => asset('images/sapore/favicon.svg'))
            ->colors(SaporePalette::panelColors())
            ->font('Inter')
            ->darkMode(false) // palet Visual Brief dirancang terang (krem + navy)
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('filament.hooks.sapore-head')->render())
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('filament.hooks.user-context')->render())

            // Navigasi
            ->navigationGroups(['Penjualan', 'Produksi', 'Inventory', 'Resep & Menu', 'Laporan', 'Master Data', 'Pengaturan'])
            ->userMenuItems([
                MenuItem::make()->label('Ganti Password')->icon('heroicon-o-key')
                    ->url(fn (): string => ChangePassword::getUrl()),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')

            // Halaman, resource, widget (Dashboard SAPORÉ ditemukan otomatis di app/Filament/Pages)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePasswordChanged::class, // akun must_change_password dipaksa ke Ganti Password
            ]);
    }
}
