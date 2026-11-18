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
                'type' => $i->item_type,
                'id' => $i->item_id,
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
