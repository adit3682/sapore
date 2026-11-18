<?php

namespace App\Filament\Resources\UnitConversionResource\Pages;

use App\Filament\Resources\UnitConversionResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageUnitConversions extends ManageRecords
{
    protected static string $resource = UnitConversionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
