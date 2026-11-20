<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Satu-satunya sumber kebenaran hak akses (matriks Langkah 10) dan penguncian cabang.
 *
 * Kemampuan: view, create, update, delete, ditambah kemampuan khusus modul
 * (approve, submit, void, waste, adjust, confirm, generate_pr).
 * Resource, halaman, dan aksi memanggil Access::can(); jangan menulis cek peran sendiri-sendiri.
 */
final class Access
{
    /** @return array<string, array<string, string[]>> */
    public static function matrix(): array
    {
        $r = ['view'];
        $cr = ['view', 'create'];
        $crud = ['view', 'create', 'update', 'delete'];

        return [
            'branches' => ['owner' => $crud],
            'settings' => ['owner' => ['view', 'update']], // Pengaturan Usaha: tarif umum, target food cost, struk
            'users' => ['owner' => $crud, 'manager' => $crud], // manager dibatasi lagi oleh UserAdminService
            'master_data' => ['owner' => $crud, 'manager' => $crud, 'chef' => $r, 'inventory' => $crud],
            'recipes' => ['owner' => $crud, 'manager' => $crud, 'chef' => $crud],
            'menus' => ['owner' => $crud, 'manager' => $crud, 'chef' => $r],
            'production_plans' => [
                'owner' => [...$crud, 'generate_pr'], 'manager' => [...$crud, 'generate_pr'],
                'chef' => [...$crud, 'generate_pr'], 'inventory' => ['view', 'generate_pr'],
            ],
            'stock' => [
                'owner' => ['view', 'waste'], 'manager' => ['view', 'waste'],
                'chef' => $r, 'inventory' => ['view', 'waste', 'adjust'],
            ],
            'stock_movements' => ['owner' => $r, 'manager' => $r, 'chef' => $r, 'inventory' => $r],
            'goods_receipts' => ['owner' => $cr, 'manager' => $cr, 'inventory' => $cr],
            // PR dibuat dari Rencana Produksi (generate_pr). "submit" = mengajukan PR draft supaya bisa diputuskan.
            'purchase_requests' => [
                'owner' => ['view', 'submit', 'approve'], 'manager' => ['view', 'submit', 'approve'],
                'chef' => ['view', 'create', 'submit'], 'inventory' => ['view', 'create', 'submit'],
            ],
            'stock_opnames' => [
                'owner' => [...$crud, 'confirm'], 'manager' => [...$crud, 'confirm'], 'inventory' => [...$crud, 'confirm'],
            ],
            'pos' => ['owner' => $cr, 'manager' => $cr, 'cashier' => $cr],
            'orders' => [
                'owner' => ['view', 'void', 'refund'], 'manager' => ['view', 'void', 'refund'], 'cashier' => ['view', 'void'],
            ],
        ];
    }

    public static function roleOf(?User $user): ?Role
    {
        if (! $user) {
            return null;
        }

        return $user->role instanceof Role ? $user->role : Role::tryFrom((string) $user->role);
    }

    public static function can(?User $user, string $module, string $ability): bool
    {
        $role = self::roleOf($user);
        if (! $role || $user->is_active === false) {
            return false;
        }

        return in_array($ability, self::matrix()[$module][$role->value] ?? [], true);
    }

    /** @param array<int, Role> $roles */
    public static function hasRole(?User $user, array $roles): bool
    {
        return $user && $user->is_active !== false && in_array(self::roleOf($user), $roles, true);
    }

    /**
     * Penguncian cabang.
     *  null  = akun pusat (Owner tanpa cabang): melihat semua cabang.
     *  int   = hanya cabang itu.
     *  -1    = akun non-Owner yang tidak punya cabang: tidak melihat apa pun (gagal aman, bukan "semua").
     */
    public static function branchScope(?User $user): ?int
    {
        if (! $user) {
            return -1;
        }
        if (self::roleOf($user) === Role::Owner) {
            return null;
        }
        if ($user->branch_id) {
            return (int) $user->branch_id;
        }

        return -1;
    }

    public static function isCentral(?User $user): bool
    {
        return self::branchScope($user) === null;
    }

    /** @return Collection<int, string> id => nama cabang yang boleh dipilih pengguna */
    public static function branchOptions(?User $user): Collection
    {
        $scope = self::branchScope($user);

        return Branch::query()
            ->when($scope !== null, fn ($q) => $q->whereKey($scope))
            ->orderBy('name')->pluck('name', 'id');
    }
}
