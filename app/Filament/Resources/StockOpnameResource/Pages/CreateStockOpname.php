<?php

namespace App\Filament\Resources\StockOpnameResource\Pages;

use App\Support\Access;
use App\Filament\Resources\StockOpnameResource;
use App\Services\StockOpnameService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockOpname extends CreateRecord
{
    protected static string $resource = StockOpnameResource::class;

    protected static bool $canCreateAnother = false;

    /** Service membuat snapshot stok semua bahan aktif. */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(StockOpnameService::class)->start(
                (int) (Access::branchScope(auth()->user()) ?? $data['branch_id']), // akun cabang: selalu cabangnya sendiri
                (string) $data['opname_date'], $data['notes'] ?? null, auth()->id()
            );
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Stock Opname belum bisa dimulai')->body($e->getMessage())->persistent()->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return StockOpnameResource::getUrl('edit', ['record' => $this->record]);
    }
}
