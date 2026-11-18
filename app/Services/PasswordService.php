<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Ganti password sendiri. Kebijakan password ada di satu tempat (rules()) dan dipakai web + API. */
class PasswordService
{
    /** @return array<int, Password> */
    public function rules(): array
    {
        return [Password::min(8)->letters()->numbers()];
    }

    public function change(User $user, string $current, string $new, ?int $keepTokenId = null): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Password saat ini salah.']);
        }

        Validator::make(['password' => $new], ['password' => $this->rules()], [], ['password' => 'Password baru'])->validate();

        if (Hash::check($new, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Password baru harus berbeda dari password saat ini.']);
        }

        $user->forceFill([
            'password' => Hash::make($new),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // token API lain dicabut; perangkat yang sedang dipakai tetap masuk
        if (method_exists($user, 'tokens')) {
            $user->tokens()->when($keepTokenId, fn ($q, $id) => $q->where('id', '!=', $id))->delete();
        }
    }
}
