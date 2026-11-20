<?php

namespace App\Filament\Resources\OrderResource\Pages;

use Illuminate\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use Filament\Forms;
use App\Services\OrderReturnService;
use App\Filament\Pages\ReceiptPage;
use App\Support\Access;
use App\Enums\OrderStatus;
use App\Filament\Pages\PosPage;
use App\Filament\Resources\OrderResource;
use App\Services\OrderService;
use Filament\Actions;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->receiptAction(),
            $this->returnAction(),
            Actions\Action::make('openInPos')->label('Buka di Kasir')->icon('heroicon-o-computer-desktop')
                ->visible(fn () => Access::can(auth()->user(), 'pos', 'create') && $this->record->status === OrderStatus::Open)
                ->url(fn () => PosPage::getUrl(['order' => $this->record->id])),

            Actions\Action::make('void')->label('Batalkan Order')->icon('heroicon-o-x-circle')->color('danger')
                ->requiresConfirmation()
                ->visible(fn () => Access::can(auth()->user(), 'orders', 'void') && $this->record->status === OrderStatus::Open)
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

    private function receiptAction(): Actions\Action
    {
        return Actions\Action::make('receipt')->label('Cetak Struk')->icon('heroicon-o-printer')->color('gray')
            ->visible(fn () => in_array($this->record->status, [OrderStatus::Paid, OrderStatus::Returned], true))
            ->url(fn () => ReceiptPage::getUrl(['type' => 'order', 'id' => $this->record->id, 'print' => 1]), shouldOpenInNewTab: true);
    }

    /** Retur penuh/sebagian. Hanya Owner dan Manager; aturan ada di OrderReturnService. */
    private function returnAction(): Actions\Action
    {
        return Actions\Action::make('return')->label('Retur')->icon('heroicon-o-arrow-uturn-left')->color('warning')
            ->visible(fn () => Access::can(auth()->user(), 'orders', 'refund')
                && in_array($this->record->status, [OrderStatus::Paid, OrderStatus::Returned], true)
                && collect(app(OrderReturnService::class)->remaining($this->record))->contains(fn ($query) => bccomp($query, '0', 2) > 0))
            ->modalHeading('Retur Order')
            ->modalDescription('Isi jumlah yang diretur per item. Pajak dan service dikembalikan proporsional.')
            ->modalSubmitActionLabel('Proses Retur')
            ->form(fn () => $this->returnForm())
            ->action(function (array $data) {
                $items = [];
                foreach ($data as $key => $value) {
                    if (str_starts_with($key, 'qty_') && is_numeric($value) && (float) $value > 0) {
                        $items[] = ['order_item_id' => (int) substr($key, 4), 'qty' => $value];
                    }
                }

                try {
                    $return = app(OrderReturnService::class)->create($this->record, [
                        'uuid' => (string) Str::uuid(),
                        'items' => $items,
                        'mode' => $data['mode'],
                        'refund_method' => $data['refund_method'],
                        'reason' => $data['reason'],
                    ], auth()->user());

                    $this->record->refresh();
                    Notification::make()->success()->title("Retur {$return->number} diproses")
                        ->body('Kembalikan dana Rp '.number_format((float) $return->total_refund, 0, ',', '.').' ke pelanggan.')
                        ->actions([
                            NotificationAction::make('print')->label('Cetak struk retur')->button()
                                ->url(ReceiptPage::getUrl(['type' => 'return', 'id' => $return->id, 'print' => 1]), shouldOpenInNewTab: true),
                        ])->persistent()->send();
                } catch (AuthorizationException|\RuntimeException|\InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Retur gagal')->body($e->getMessage())->send();
                }
            });
    }

    /** @return array<int, Forms\Components\Component> */
    private function returnForm(): array
    {
        $order = $this->record->load('items');
        $remaining = app(OrderReturnService::class)->remaining($order);

        $fields = [];
        foreach ($order->items as $item) {
            $left = $remaining[$item->id] ?? '0.00';
            if (bccomp($left, '0', 2) <= 0) {
                continue;
            }
            $fields[] = Forms\Components\TextInput::make("qty_{$item->id}")
                ->label("{$item->name} (sisa ".rtrim(rtrim($left, '0'), '.').')')
                ->numeric()->minValue(0)->maxValue((float) $left)->default(0);
        }

        $fields[] = Forms\Components\Select::make('mode')->label('Makanan yang diretur')->required()->default('waste')->native(false)
            ->options([
                'waste' => 'Dibuang / tidak bisa dijual (dicatat sebagai waste)',
                'restock' => 'Belum dimasak atau utuh (bahan kembali ke stok)',
            ])
            ->helperText('Pilih "kembali ke stok" hanya bila bahannya benar-benar bisa dipakai lagi.');
        $fields[] = Forms\Components\Select::make('refund_method')->label('Dana dikembalikan lewat')->required()->native(false)
            ->default($order->payment_method)
            ->options(['cash' => 'Tunai', 'qris' => 'QRIS', 'card' => 'Kartu', 'transfer' => 'Transfer']);
        $fields[] = Forms\Components\Textarea::make('reason')->label('Alasan retur')->required()->minLength(3)->maxLength(500)->rows(2);

        return $fields;
    }
}
