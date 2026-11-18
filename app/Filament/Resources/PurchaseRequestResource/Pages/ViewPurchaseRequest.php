<?php

namespace App\Filament\Resources\PurchaseRequestResource\Pages;

use App\Support\Access;
use App\Enums\PurchaseRequestStatus;
use App\Filament\Resources\PurchaseRequestResource;
use App\Services\PurchaseRequestService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseRequest extends ViewRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        $svc = fn () => app(PurchaseRequestService::class);

        return [
            Actions\Action::make('submit')->label('Ajukan')->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->visible(fn () => Access::can(auth()->user(), 'purchase_requests', 'submit') && $this->record->status === PurchaseRequestStatus::Draft)
                ->action(fn () => $this->run(fn () => $svc()->submit($this->record), 'Purchase Request diajukan')),

            Actions\Action::make('approve')->label('Setujui')->icon('heroicon-o-check')->color('success')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === PurchaseRequestStatus::Submitted && $svc()->canApprove(auth()->user()))
                ->action(fn () => $this->run(fn () => $svc()->approve($this->record, auth()->user()), 'Purchase Request disetujui')),

            Actions\Action::make('reject')->label('Tolak')->icon('heroicon-o-x-mark')->color('danger')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === PurchaseRequestStatus::Submitted && $svc()->canApprove(auth()->user()))
                ->action(fn () => $this->run(fn () => $svc()->reject($this->record, auth()->user()), 'Purchase Request ditolak')),
        ];
    }

    private function run(\Closure $fn, string $title): void
    {
        try {
            $fn();
            $this->record->refresh();
            Notification::make()->success()->title($title)->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Gagal')->body($e->getMessage())->send();
        }
    }
}
