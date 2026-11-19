<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status->value,
            'order_type' => $this->order_type,
            'table_label' => $this->table_label,
            'items' => $this->items->map(fn ($i) => [
                'order_item_id' => $i->id, // dipakai klien untuk retur
                'returned_qty' => $this->returnedQty($i->id),
                'type' => $i->item_type,
                'item_id' => $i->item_id, // id menu/paket pada katalog
                'name' => $i->name,
                'unit_price' => (string) $i->unit_price,
                'qty' => (string) $i->qty,
                'line_total' => (string) $i->line_total,
            ])->values(),
            'subtotal' => (string) $this->subtotal,
            'tax_rate' => (string) $this->tax_rate,
            'tax_amount' => (string) $this->tax_amount,
            'service_rate' => (string) $this->service_rate,
            'service_amount' => (string) $this->service_amount,
            'total' => (string) $this->total,
            'payment_method' => $this->payment_method,
            'amount_paid' => $this->amount_paid === null ? null : (string) $this->amount_paid,
            'change_amount' => $this->change_amount === null ? null : (string) $this->change_amount,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded_total' => (string) $this->refunded_total,
            'returns' => $this->returns->map(fn ($r) => [
                'uuid' => $r->uuid, 'number' => $r->number, 'mode' => $r->mode, 'refund_method' => $r->refund_method,
                'reason' => $r->reason, 'total_refund' => (string) $r->total_refund,
                'returned_at' => $r->returned_at?->toIso8601String(),
                'items' => $r->items->map(fn ($ri) => [
                    'order_item_id' => $ri->order_item_id, 'name' => $ri->name,
                    'qty' => (string) $ri->qty, 'line_total' => (string) $ri->line_total,
                ])->values(),
            ])->values(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function returnedQty(int $orderItemId): string
    {
        $sum = $this->returns->flatMap(fn ($r) => $r->items)->where('order_item_id', $orderItemId)->sum('qty');

        return number_format((float) $sum, 2, '.', '');
    }
}
