<?php

namespace App\Filament\Resources\ProductionPlanResource\Pages;

use App\Support\Access;
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

        $scope = Access::branchScope(auth()->user());
        abort_if($scope === -1, 403, 'Akun Anda belum terhubung ke cabang.');
        $data['branch_id'] = $scope ?? $data['branch_id']; // akun cabang tidak bisa memilih cabang lain

        return $data;
    }
}
