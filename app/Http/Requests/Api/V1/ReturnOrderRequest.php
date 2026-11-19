<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Access;
use Illuminate\Foundation\Http\FormRequest;

/** Retur order (Owner/Manager). Klien membuat `uuid` retur sendiri (idempotensi). */
class ReturnOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::can($this->user(), 'orders', 'refund');
    }

    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'mode' => ['nullable', 'in:waste,restock'],
            'refund_method' => ['nullable', 'in:cash,qris,card,transfer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->validated();
    }
}
