<?php

namespace App\Filament\Resources\ProductionPlanResource\Pages;

use App\Enums\PlanStatus;
use App\Filament\Resources\ProductionPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductionPlan extends CreateRecord
{
    protected static string $resource = ProductionPlanResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = PlanStatus::Draft->value;
        $data['created_by'] = auth()->id();

        return $data;
    }
}
