<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\ReceiptService;
use App\Support\Access;
use Filament\Pages\Page;

/**
 * Struk untuk dicetak dari browser (kertas thermal 58/80 mm), untuk order maupun retur.
 *   /admin/struk/order/{id}    /admin/struk/return/{id}    tambahkan ?print=1 untuk langsung membuka dialog cetak.
 * Isi dari ReceiptService (sama dengan API untuk APK). Mengikuti penguncian cabang: struk cabang lain = 404.
 */
class ReceiptPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'struk';

    protected static string $view = 'filament.pages.receipt';

    public string $type = 'order';

    public int $recordId = 0;

    public static function getRoutePath(): string
    {
        return '/struk/{type}/{id}';
    }

    public static function canAccess(): bool
    {
        return Access::can(auth()->user(), 'orders', 'view');
    }

    public function mount(string $type, int $id): void
    {
        abort_unless(in_array($type, ['order', 'return'], true), 404);

        $this->type = $type;
        $this->recordId = $id;
        $this->receipt(); // gagal lebih awal (404) bila rekaman tidak ada / bukan cabangnya / belum dibayar
    }

    public function getTitle(): string
    {
        return $this->type === 'return' ? 'Struk Retur' : 'Struk';
    }

    /** @return array<string, mixed> */
    public function receipt(): array
    {
        $scope = Access::branchScope(auth()->user());
        $service = app(ReceiptService::class);

        try {
            if ($this->type === 'return') {
                $return = OrderReturn::when($scope !== null, fn ($q) => $q->where('branch_id', $scope))->findOrFail($this->recordId);

                return $service->buildReturn($return);
            }

            $order = Order::when($scope !== null, fn ($q) => $q->where('branch_id', $scope))->findOrFail($this->recordId);

            return $service->build($order);
        } catch (\RuntimeException) {
            abort(404); // order belum dibayar: belum ada struk
        }
    }

    public function autoPrint(): bool
    {
        return request()->boolean('print');
    }
}
