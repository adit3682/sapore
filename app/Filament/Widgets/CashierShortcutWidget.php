<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Filament\Pages\PosPage;
use App\Support\Access;
use Filament\Widgets\Widget;

/** Dashboard untuk Kasir: tidak ada angka usaha, hanya jalan pintas ke Kasir. */
class CashierShortcutWidget extends Widget
{
    protected static string $view = 'filament.widgets.cashier-shortcut';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Access::hasRole(auth()->user(), [Role::Cashier]);
    }

    public function url(): string
    {
        return PosPage::getUrl();
    }

    public function name(): string
    {
        return (string) auth()->user()?->name;
    }
}
