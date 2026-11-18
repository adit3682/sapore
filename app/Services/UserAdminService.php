<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Support\Access;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Pengelolaan akun oleh admin.
 *
 *  Owner   : semua akun, semua peran, semua cabang.
 *  Manager : hanya staf (chef, inventory, cashier) di CABANGNYA SENDIRI. Tidak bisa membuat/mengubah
 *            akun owner atau manager, tidak bisa memindahkan staf ke cabang lain (cegah eskalasi hak akses).
 *  Semua  : tidak boleh mengubah peran atau status aktif akunnya sendiri; harus tersisa minimal satu Owner aktif.
 *  Akun baru dan password yang direset wajib diganti saat login berikutnya (must_change_password).
 *  Menonaktifkan akun, mengubah peran, atau mereset password mencabut semua token API akun itu.
 */
class UserAdminService
{
    public const STAFF_ROLES = ['chef', 'inventory', 'cashier'];

    public function roleValue(User $user): string
    {
        return $user->role instanceof Role ? $user->role->value : (string) $user->role;
    }

    /** @return string[] nilai peran yang boleh diberikan oleh $actor */
    public function assignableRoles(User $actor): array
    {
        return match (Access::roleOf($actor)) {
            Role::Owner => array_map(fn (Role $r) => $r->value, Role::cases()),
            Role::Manager => self::STAFF_ROLES,
            default => [],
        };
    }

    public function canManage(User $actor, User $target): bool
    {
        if ($actor->is_active === false) {
            return false;
        }

        return match (Access::roleOf($actor)) {
            Role::Owner => true,
            Role::Manager => $actor->branch_id !== null
                && (int) $target->branch_id === (int) $actor->branch_id
                && in_array($this->roleValue($target), self::STAFF_ROLES, true),
            default => false,
        };
    }

    /** @param array<string, mixed> $data name, email, password, role, branch_id?, is_active? */
    public function create(User $actor, array $data): User
    {
        $role = (string) ($data['role'] ?? '');
        if (! in_array($role, $this->assignableRoles($actor), true)) {
            throw new AuthorizationException('Anda tidak boleh membuat akun dengan peran ini.');
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email === '' || User::where('email', $email)->exists()) {
            throw new InvalidArgumentException('Email kosong atau sudah dipakai akun lain.');
        }
        $password = (string) ($data['password'] ?? '');
        if (mb_strlen($password) < 8) {
            throw new InvalidArgumentException('Password sementara minimal 8 karakter.');
        }
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException('Nama wajib diisi.');
        }

        $branchId = $this->resolveBranch($actor, $role, $data['branch_id'] ?? null);

        $user = new User();
        $user->forceFill([
            'name' => trim($data['name']),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'branch_id' => $branchId,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'must_change_password' => true,
        ])->save();

        return $user;
    }

    /** @param array<string, mixed> $data name?, email?, role?, branch_id?, is_active? */
    public function update(User $actor, User $target, array $data): User
    {
        if (! $this->canManage($actor, $target)) {
            throw new AuthorizationException('Anda tidak boleh mengubah akun ini.');
        }

        $self = $actor->id === $target->id;
        $currentRole = $this->roleValue($target);
        $newRole = array_key_exists('role', $data) ? (string) $data['role'] : $currentRole;
        $newActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $target->is_active;
        $roleChanged = $newRole !== $currentRole;
        $deactivated = $target->is_active && ! $newActive;

        if ($self && ($roleChanged || $newActive !== (bool) $target->is_active)) {
            throw new AuthorizationException('Anda tidak bisa mengubah peran atau status aktif akun Anda sendiri.');
        }
        if ($roleChanged && ! in_array($newRole, $this->assignableRoles($actor), true)) {
            throw new AuthorizationException('Anda tidak boleh memberikan peran ini.');
        }

        // Manager tidak boleh memindahkan staf ke cabang lain.
        if (Access::roleOf($actor) === Role::Manager && array_key_exists('branch_id', $data)
            && $data['branch_id'] !== null && (int) $data['branch_id'] !== (int) $actor->branch_id) {
            throw new AuthorizationException('Anda hanya boleh mengelola staf di cabang Anda sendiri.');
        }

        if ($currentRole === Role::Owner->value && $target->is_active && ($roleChanged || ! $newActive)
            && ! User::where('role', Role::Owner->value)->where('is_active', true)->where('id', '!=', $target->id)->exists()) {
            throw new InvalidArgumentException('Harus tersisa minimal satu Owner aktif.');
        }

        $email = array_key_exists('email', $data) ? strtolower(trim((string) $data['email'])) : $target->email;
        if ($email === '' || User::where('email', $email)->where('id', '!=', $target->id)->exists()) {
            throw new InvalidArgumentException('Email kosong atau sudah dipakai akun lain.');
        }

        $branchInput = array_key_exists('branch_id', $data) ? $data['branch_id'] : $target->branch_id;
        $branchId = $this->resolveBranch($actor, $newRole, $branchInput);

        $target->forceFill([
            'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $target->name,
            'email' => $email,
            'role' => $newRole,
            'branch_id' => $branchId,
            'is_active' => $newActive,
        ])->save();

        if ($roleChanged || $deactivated) {
            $this->revokeTokens($target);
        }

        return $target;
    }

    /** Reset password oleh admin: password sementara + wajib diganti + token API dicabut. */
    public function resetPassword(User $actor, User $target, string $temporaryPassword): User
    {
        if (! $this->canManage($actor, $target)) {
            throw new AuthorizationException('Anda tidak boleh mereset password akun ini.');
        }
        if ($actor->id === $target->id) {
            throw new InvalidArgumentException('Untuk akun sendiri gunakan menu Ganti Password.');
        }
        if (mb_strlen($temporaryPassword) < 8) {
            throw new InvalidArgumentException('Password sementara minimal 8 karakter.');
        }

        $target->forceFill([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
            'password_changed_at' => null,
        ])->save();
        $this->revokeTokens($target);

        return $target;
    }

    private function resolveBranch(User $actor, string $role, mixed $requested): ?int
    {
        if ($role === Role::Owner->value) {
            return null; // Owner = akun pusat
        }
        if (Access::roleOf($actor) === Role::Manager) {
            return (int) $actor->branch_id;
        }

        if (! $requested || ! Branch::whereKey($requested)->exists()) {
            throw new InvalidArgumentException('Pilih cabang untuk akun ini.');
        }

        return (int) $requested;
    }

    private function revokeTokens(User $user): void
    {
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }
    }
}
