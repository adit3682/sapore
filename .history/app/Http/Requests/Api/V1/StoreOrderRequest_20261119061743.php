<?php

namespace App\Http\Requests\Api\V1;

use App\Services\OrderService;
use Illuminate\Foundation\Http\FormRequest;

/** Membuat order; bila `pay` diisi, langsung dibayar. Klien membuat `uuid` sendiri (idempotensi). */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(OrderService::class)->canSell($this->user());
    }

    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'order_type' => ['nullable', 'in:dine_in,take_away'],
            'table_label' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'], // hanya dipakai akun pusat (Owner)
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.type' => ['required', 'in:menu,package'],
            'items.*.id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'pay' => ['nullable', 'array'],
            'pay.method' => ['required_with:pay', 'in:cash,qris,card,transfer'],
            'pay.amount_paid' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, mixed> */
    public function orderHeader(): array
    {
        return $this->safe()->only(['uuid', 'order_type', 'table_label', 'notes', 'branch_id']);
    }
}