<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Enums\OrderStatus;
use App\Filament\Pages\PosPage;
use App\Filament\Resources\OrderResource;
use App\Services\OrderService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('openInPos')->label('Buka di Kasir')->icon('heroicon-o-computer-desktop')
                ->visible(fn () => $this->record->status === OrderStatus::Open)
                ->url(fn () => PosPage::getUrl(['order' => $this->record->id])),

            Actions\Action::make('void')->label('Batalkan Order')->icon('heroicon-o-x-circle')->color('danger')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === OrderStatus::Open)
                ->action(function () {
                    try {
                        app(OrderService::class)->void($this->record);
                        $this->record->refresh();
                        Notification::make()->success()->title('Order dibatalkan')->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Gagal')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
