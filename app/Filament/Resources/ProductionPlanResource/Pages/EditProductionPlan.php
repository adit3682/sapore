<?php

namespace App\Filament\Resources\ProductionPlanResource\Pages;

use App\Support\Access;
use App\Filament\Resources\ProductionPlanResource;
use Filament\Resources\Pages\EditRecord;

class EditProductionPlan extends EditRecord
{
    protected static string $resource = ProductionPlanResource::class;

    /** Akun cabang tidak bisa memindahkan rencana ke cabang lain. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (Access::branchScope(auth()->user()) !== null) {
            $data['branch_id'] = $this->record->branch_id;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return ProductionPlanResource::getUrl('view', ['record' => $this->record]);
    }
}
