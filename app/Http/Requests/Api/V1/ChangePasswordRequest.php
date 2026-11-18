<?php

namespace App\Http\Requests\Api\V1;

use App\Services\PasswordService;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // setiap akun yang sudah login boleh mengganti passwordnya sendiri
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', ...app(PasswordService::class)->rules()],
        ];
    }
}
