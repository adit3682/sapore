<?php

namespace App\Filament\Resources\GoodsReceiptResource\Pages;

use App\Support\Access;
use App\Filament\Resources\GoodsReceiptResource;
use App\Services\GoodsReceiptService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateGoodsReceipt extends CreateRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected static bool $canCreateAnother = false;

    /** Semua logika stok ada di service; halaman ini hanya meneruskan data form. */
    protected function handleRecordCreation(array $data): Model
    {
        $items = array_values($data['items'] ?? []);
        unset($data['items']);

        try {
            $scope = Access::branchScope(auth()->user());
            if ($scope === -1) {
                throw new \RuntimeException('Akun Anda belum terhubung ke cabang.');
            }
            $data['branch_id'] = $scope ?? $data['branch_id']; // akun cabang tidak bisa memilih cabang lain

            return app(GoodsReceiptService::class)->post($data + ['uuid' => (string) Str::uuid()], $items, auth()->id());
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Penerimaan belum bisa disimpan')->body($e->getMessage())->persistent()->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Penerimaan barang dicatat, stok bertambah';
    }
}
