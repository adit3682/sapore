<?php

namespace App\Filament\Resources\ProductionPlanResource\Pages;

use App\Support\Access;
use App\Enums\PlanStatus;
use App\Filament\Resources\ProductionPlanResource;
use App\Filament\Resources\PurchaseRequestResource;
use App\Models\Menu;
use App\Models\Package;
use App\Services\BomExplosionService;
use App\Services\ProductionPlanService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProductionPlan extends ViewRecord
{
    protected static string $resource = ProductionPlanResource::class;

    protected static string $view = 'filament.resources.production-plan-resource.pages.view-production-plan';

    /** Dihitung ulang setiap render supaya selalu sesuai stok terkini. */
    public function requirements(): array
    {
        $this->record->load('items', 'reservations', 'branch');

        try {
            return app(BomExplosionService::class)->requirementsForPlan($this->record) + ['error' => null];
        } catch (\Throwable $e) {
            return ['rows' => [], 'shortage_count' => 0, 'shortage_cost' => '0', 'error' => $e->getMessage()];
        }
    }

    /** @return array<int, array{type: string, name: string, qty: string}> */
    public function itemRows(): array
    {
        $menus = Menu::whereIn('id', $this->record->items->where('item_type', 'menu')->pluck('item_id'))->pluck('name', 'id');
        $packages = Package::whereIn('id', $this->record->items->where('item_type', 'package')->pluck('item_id'))->pluck('name', 'id');

        return $this->record->items->map(fn ($i) => [
            'type' => $i->item_type === 'package' ? 'Paket' : 'Menu',
            'name' => ($i->item_type === 'package' ? $packages : $menus)[$i->item_id] ?? '(dihapus)',
            'qty' => rtrim(rtrim((string) $i->qty, '0'), '.'),
        ])->all();
    }

    protected function getHeaderActions(): array
    {
        $status = fn () => $this->record->status;

        return [
            Actions\EditAction::make()->visible(fn () => $status() === PlanStatus::Draft),

            Actions\Action::make('generatePr')->label('Generate Purchase Request')->icon('heroicon-o-shopping-cart')
                ->color('primary')
                ->visible(fn () => Access::can(auth()->user(), 'production_plans', 'generate_pr')
                    && $status() !== PlanStatus::Done && $this->requirements()['shortage_count'] > 0)
                ->action(function () {
                    $this->run(function () {
                        $pr = app(ProductionPlanService::class)->generatePurchaseRequest($this->record, auth()->id());
                        $this->redirect(PurchaseRequestResource::getUrl('view', ['record' => $pr]));
                    }, null);
                }),

            Actions\Action::make('confirm')->label('Konfirmasi Rencana')->icon('heroicon-o-check-circle')->color('success')
                ->requiresConfirmation()
                ->modalDescription('Bahan yang dibutuhkan akan dipesan (soft allocation) agar tidak dipakai rencana lain.')
                ->visible(fn () => Access::can(auth()->user(), 'production_plans', 'update') && $status() === PlanStatus::Draft)
                ->action(fn () => $this->run(fn () => app(ProductionPlanService::class)->confirm($this->record), 'Rencana dikonfirmasi, stok dipesan')),

            Actions\Action::make('complete')->label('Tandai Selesai')->icon('heroicon-o-flag')->color('gray')
                ->requiresConfirmation()
                ->visible(fn () => Access::can(auth()->user(), 'production_plans', 'update') && $status() === PlanStatus::Confirmed)
                ->action(fn () => $this->run(fn () => app(ProductionPlanService::class)->complete($this->record), 'Rencana selesai, pesanan stok dilepas')),

            Actions\Action::make('unconfirm')->label('Batalkan Konfirmasi')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                ->requiresConfirmation()
                ->visible(fn () => Access::can(auth()->user(), 'production_plans', 'update') && $status() === PlanStatus::Confirmed)
                ->action(fn () => $this->run(fn () => app(ProductionPlanService::class)->unconfirm($this->record), 'Konfirmasi dibatalkan, kembali ke Draft')),
        ];
    }

    private function run(\Closure $fn, ?string $successTitle): void
    {
        try {
            $fn();
            $this->record->refresh();
            if ($successTitle) {
                Notification::make()->success()->title($successTitle)->send();
            }
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Gagal')->body($e->getMessage())->send();
        }
    }
}
