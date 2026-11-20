<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderReturn;
use RuntimeException;

/**
 * Data struk (kertas thermal 58/80 mm). Satu sumber untuk halaman cetak di web dan API (APK Flutter yang
 * mencetak lewat Bluetooth/ESC-POS cukup memformat data ini). Semua angka berupa string desimal.
 *
 * Pengaturan (opsional, di config/sapore.php): receipt.name, receipt.footer, receipt.paper (58 atau 80).
 */
class ReceiptService
{
    private const PAYMENT = ['cash' => 'Tunai', 'qris' => 'QRIS', 'card' => 'Kartu', 'transfer' => 'Transfer'];

    private const ORDER_TYPE = ['dine_in' => 'Dine In', 'take_away' => 'Take Away'];

    private const MODE = ['waste' => 'Dibuang', 'restock' => 'Kembali ke stok'];

    /** @return array<string, mixed> */
    public function build(Order $order): array
    {
        if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::Returned], true)) {
            throw new RuntimeException('Struk hanya untuk order yang sudah dibayar.');
        }
        $order->loadMissing(['items', 'branch', 'cashier', 'returns']);

        return $this->header($order->branch) + [
            'kind' => 'order',
            'number' => $order->number,
            'date' => ($order->paid_at ?? $order->created_at)->format('d/m/Y H:i'),
            'cashier' => $order->cashier?->name,
            'table' => $order->table_label,
            'order_type' => self::ORDER_TYPE[$order->order_type] ?? $order->order_type,
            'lines' => $order->items->map(fn ($i) => [
                'name' => $i->name, 'qty' => $this->qty((string) $i->qty),
                'unit_price' => (string) $i->unit_price, 'line_total' => (string) $i->line_total,
            ])->values()->all(),
            'subtotal' => (string) $order->subtotal,
            'tax_rate' => (string) (float) $order->tax_rate,
            'tax' => (string) $order->tax_amount,
            'service_rate' => (string) (float) $order->service_rate,
            'service' => (string) $order->service_amount,
            'total' => (string) $order->total,
            'payment_method' => self::PAYMENT[$order->payment_method] ?? $order->payment_method,
            'amount_paid' => (string) $order->amount_paid,
            'change' => (string) $order->change_amount,
            'returns' => $order->returns->map(fn ($r) => [
                'number' => $r->number, 'total' => (string) $r->total_refund,
                'date' => $r->returned_at->format('d/m/Y H:i'),
            ])->values()->all(),
            'refunded_total' => (string) $order->refunded_total,
            'net_total' => bcsub((string) $order->total, (string) $order->refunded_total, 2),
        ];
    }

    /** @return array<string, mixed> */
    public function buildReturn(OrderReturn $return): array
    {
        $return->loadMissing(['items', 'order', 'branch', 'returnedBy']);

        return $this->header($return->branch) + [
            'kind' => 'return',
            'number' => $return->number,
            'order_number' => $return->order?->number,
            'date' => $return->returned_at->format('d/m/Y H:i'),
            'cashier' => $return->returnedBy?->name,
            'lines' => $return->items->map(fn ($i) => [
                'name' => $i->name, 'qty' => $this->qty((string) $i->qty),
                'unit_price' => (string) $i->unit_price, 'line_total' => (string) $i->line_total,
            ])->values()->all(),
            'subtotal' => (string) $return->subtotal_refund,
            'tax' => (string) $return->tax_refund,
            'service' => (string) $return->service_refund,
            'total' => (string) $return->total_refund,
            'refund_method' => self::PAYMENT[$return->refund_method] ?? $return->refund_method,
            'mode' => self::MODE[$return->mode] ?? $return->mode,
            'reason' => $return->reason,
        ];
    }

    /** @return array<string, mixed> */
    private function header(?\App\Models\Branch $branch): array
    {
        $settings = app(SettingsService::class)->receipt();

        return [
            'business' => $settings['name'],
            'branch' => $branch?->name,
            'address' => $branch?->address,
            'phone' => $branch?->phone,
            'footer' => $settings['footer'],
            'paper' => $settings['paper'],
        ];
    }

    private function qty(string $v): string
    {
        return rtrim(rtrim($v, '0'), '.') ?: '0';
    }
}
