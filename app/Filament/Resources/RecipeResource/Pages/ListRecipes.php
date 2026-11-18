<?php

namespace App\Filament\Resources\RecipeResource\Pages;

use App\Filament\Resources\RecipeResource;
use App\Services\RecipeCostService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListRecipes extends ListRecords
{
    protected static string $resource = RecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('recalculateAll')->label('Hitung ulang semua HPP')
                ->icon('heroicon-o-arrow-path')->color('gray')->requiresConfirmation()
                ->action(function () {
                    $n = app(RecipeCostService::class)->recalculateAll();
                    Notification::make()->success()->title("{$n} resep dihitung ulang")->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
