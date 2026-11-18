<?php

namespace App\Http\Requests\Api\V1;

use App\Services\OrderService;
use Illuminate\Foundation\Http\FormRequest;

/** Request tanpa isi (lihat / batalkan order): hanya cek peran. */
class OrderActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(OrderService::class)->canSell($this->user());
    }

    public function rules(): array
    {
        return [];
    }
}
