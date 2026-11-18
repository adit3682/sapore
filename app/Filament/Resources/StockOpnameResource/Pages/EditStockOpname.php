<?php

namespace App\Filament\Resources\StockOpnameResource\Pages;

use App\Support\Access;
use App\Filament\Resources\StockOpnameResource;
use App\Services\StockOpnameService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditStockOpname extends EditRecord
{
    protected static string $resource = StockOpnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('confirm')->label('Konfirmasi Hasil')
                ->visible(fn () => Access::can(auth()->user(), 'stock_opnames', 'confirm'))->icon('heroicon-o-check-circle')->color('success')
                ->requiresConfirmation()
                ->modalDescription(function () {
                    $left = $this->record->items()->whereNull('physical_qty')->count();

                    return 'Selisih bahan yang sudah dihitung akan dicatat sebagai penyesuaian stok.'
                        .($left ? " {$left} bahan belum dihitung dan akan dilewati." : '');
                })
                ->action(function () {
                    try {
                        $r = app(StockOpnameService::class)->confirm($this->record, auth()->id());
                        Notification::make()->success()->title('Stock Opname dikonfirmasi')
                            ->body("{$r['adjusted']} bahan disesuaikan, {$r['unchanged']} sama, {$r['skipped']} dilewati.")->send();
                        $this->redirect(StockOpnameResource::getUrl('view', ['record' => $this->record]));
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Gagal')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('cancel')->label('Batalkan Opname')
                ->visible(fn () => Access::can(auth()->user(), 'stock_opnames', 'update'))->icon('heroicon-o-x-circle')->color('gray')
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        app(StockOpnameService::class)->cancel($this->record);
                        $this->redirect(StockOpnameResource::getUrl('index'));
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Gagal')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
