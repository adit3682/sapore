<?php

namespace App\Filament\Resources\MenuResource\Pages;

use App\Filament\Resources\MenuResource;
use Filament\Resources\Pages\EditRecord;

class EditMenu extends EditRecord
{
    // Menu dipakai paket & rencana produksi: nonaktifkan (toggle Aktif), jangan hapus.
    protected static string $resource = MenuResource::class;
}
