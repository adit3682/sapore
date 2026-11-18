<?php

namespace App\Filament\Resources\RecipeResource\Pages;

use App\Filament\Resources\RecipeResource;
use App\Services\RecipeCostService;
use Filament\Resources\Pages\CreateRecord;

class CreateRecipe extends CreateRecord
{
    protected static string $resource = RecipeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['version'] = 1;

        return $data;
    }

    protected function beforeCreate(): void
    {
        $error = RecipeResource::validateItems($this->data['items'] ?? [], $this->data['yield_qty'] ?? null, null);
        if ($error) {
            RecipeResource::notifyError($error);
            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        $service = app(RecipeCostService::class);
        $service->recalculate($this->record);
        $service->snapshot($this->record->refresh(), auth()->id());
    }
}
