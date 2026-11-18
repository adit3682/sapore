<?php

namespace App\Http\Requests\Api\V1;

use App\Services\OrderService;
use Illuminate\Foundation\Http\FormRequest;

class PayOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(OrderService::class)->canSell($this->user());
    }

    public function rules(): array
    {
        return [
            'method' => ['required', 'in:cash,qris,card,transfer'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
