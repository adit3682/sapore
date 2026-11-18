<?php

namespace App\Filament\Resources\RecipeResource\Pages;

use App\Filament\Resources\RecipeResource;
use App\Services\RecipeCostService;
use Filament\Resources\Pages\EditRecord;

class EditRecipe extends EditRecord
{
    protected static string $resource = RecipeResource::class;

    // Resep dipakai menu/sub-resep, jadi tidak dihapus: ubah status menjadi Archived.

    /** Setiap penyimpanan menaikkan nomor versi dan menyimpan snapshot riwayat. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['version'] = $this->record->version + 1;

        return $data;
    }

    protected function beforeSave(): void
    {
        $error = RecipeResource::validateItems(
            $this->data['items'] ?? [],
            $this->data['yield_qty'] ?? null,
            $this->record->id
        );
        if ($error) {
            RecipeResource::notifyError($error);
            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        $service = app(RecipeCostService::class);
        $service->recalculate($this->record);
        $service->snapshot($this->record->refresh(), auth()->id());

        // resep ini mungkin dipakai sebagai sub-resep di resep lain
        $service->recalculateDependentsOfRecipe($this->record->id);
    }
}
