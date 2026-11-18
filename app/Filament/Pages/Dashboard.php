<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/** Dashboard dengan judul dan subjudul seperti mockup Visual Brief. */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    public function getHeading(): string
    {
        return 'Dashboard';
    }

    public function getSubheading(): ?string
    {
        $branch = auth()->user()?->branch?->name;

        return 'Ringkasan operasional hari ini • '.now()->format('d M Y').($branch ? " • {$branch}" : '');
    }
}
