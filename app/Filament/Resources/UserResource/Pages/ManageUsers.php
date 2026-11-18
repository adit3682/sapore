<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\UserAdminService;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->using(function (array $data, Actions\CreateAction $action): User {
                    try {
                        return app(UserAdminService::class)->create(auth()->user(), $data);
                    } catch (\Throwable $e) {
                        UserResource::fail($e);
                        $action->halt();
                    }
                }),
        ];
    }
}
