<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\User;
use App\Services\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password) || ! $user->is_active) {
            return response()->json(['message' => 'Email atau password salah.'], 422);
        }

        return response()->json([
            'token' => $user->createToken($request->validated('device_name'))->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /**
     * Akun dengan must_change_password hanya boleh memanggil endpoint /auth/* sampai password diganti.
     * Token perangkat ini tetap berlaku; token perangkat lain dicabut.
     */
    public function changePassword(ChangePasswordRequest $request, PasswordService $passwords): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        $passwords->change(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
            $token instanceof PersonalAccessToken ? $token->id : null,
        );

        return response()->json([
            'message' => 'Password berhasil diganti.',
            'user' => $this->userPayload($request->user()->fresh()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role instanceof \BackedEnum ? $user->role->value : $user->role,
            'branch_id' => $user->branch_id,
            'branch' => $user->branch?->name,
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }
}
