<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\UserAdminService;
use Database\Seeders\SaporeSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Butuh trait HasApiTokens di model User (untuk uji pencabutan token). */
class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    private UserAdminService $svc;
    private Branch $a;
    private Branch $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->svc = app(UserAdminService::class);
        $this->a = Branch::firstOrFail();
        $this->b = Branch::create(['name' => 'Cabang B']);
    }

    private function u(string $name): User
    {
        return User::where('email', "{$name}@sapore.test")->firstOrFail();
    }

    private function make(array $over = []): User
    {
        return $this->svc->create($this->u('owner'), $over + [
            'name' => 'Staf Baru', 'email' => 'baru'.uniqid().'@sapore.test', 'password' => 'Sementara1',
            'role' => 'cashier', 'branch_id' => $this->b->id,
        ]);
    }

    public function test_owner_creates_users_in_any_branch_and_role(): void
    {
        $manager = $this->make(['role' => 'manager', 'branch_id' => $this->b->id]);

        $this->assertSame('manager', $this->svc->roleValue($manager));
        $this->assertSame($this->b->id, $manager->branch_id);
        $this->assertTrue($manager->must_change_password);   // password sementara wajib diganti
        $this->assertTrue($manager->is_active);
        $this->assertTrue(Hash::check('Sementara1', $manager->password));
    }

    public function test_owner_account_is_central_and_other_roles_need_a_branch(): void
    {
        $owner2 = $this->make(['role' => 'owner', 'branch_id' => $this->b->id]);
        $this->assertNull($owner2->branch_id);

        $this->expectException(\InvalidArgumentException::class);
        $this->make(['role' => 'chef', 'branch_id' => null]);
    }

    public function test_manager_can_only_create_staff_in_own_branch(): void
    {
        $manager = $this->u('manager'); // cabang A

        $chef = $this->svc->create($manager, [
            'name' => 'Chef Baru', 'email' => 'chefbaru@sapore.test', 'password' => 'Sementara1',
            'role' => 'chef', 'branch_id' => $this->b->id, // dicoba ke cabang lain: diabaikan
        ]);
        $this->assertSame($this->a->id, $chef->branch_id);
        $this->assertSame(['chef', 'inventory', 'cashier'], $this->svc->assignableRoles($manager));
    }

    public function test_manager_cannot_create_manager_or_owner(): void
    {
        foreach (['manager', 'owner'] as $role) {
            try {
                $this->svc->create($this->u('manager'), [
                    'name' => 'X', 'email' => "{$role}x@sapore.test", 'password' => 'Sementara1', 'role' => $role,
                ]);
                $this->fail("Manager seharusnya tidak boleh membuat {$role}");
            } catch (AuthorizationException) {
                $this->assertFalse(User::where('email', "{$role}x@sapore.test")->exists());
            }
        }
    }

    public function test_staff_and_chef_cannot_manage_users_at_all(): void
    {
        $this->assertSame([], $this->svc->assignableRoles($this->u('chef')));
        $this->assertFalse($this->svc->canManage($this->u('chef'), $this->u('kasir')));

        $this->expectException(AuthorizationException::class);
        $this->svc->create($this->u('chef'), ['name' => 'X', 'email' => 'x@sapore.test', 'password' => 'Sementara1', 'role' => 'cashier']);
    }

    public function test_manager_cannot_touch_other_branch_or_higher_roles(): void
    {
        $manager = $this->u('manager'); // cabang A
        $otherBranchStaff = $this->make(['role' => 'cashier', 'branch_id' => $this->b->id]);
        $anotherManager = $this->make(['role' => 'manager', 'branch_id' => $this->a->id]);

        $this->assertFalse($this->svc->canManage($manager, $otherBranchStaff));
        $this->assertFalse($this->svc->canManage($manager, $anotherManager));
        $this->assertFalse($this->svc->canManage($manager, $this->u('owner')));
        $this->assertTrue($this->svc->canManage($manager, $this->u('kasir')));

        $this->expectException(AuthorizationException::class);
        $this->svc->update($manager, $otherBranchStaff, ['name' => 'Diubah']);
    }

    public function test_manager_cannot_escalate_or_move_staff(): void
    {
        $manager = $this->u('manager');
        $cashier = $this->u('kasir');

        try {
            $this->svc->update($manager, $cashier, ['role' => 'manager']);
            $this->fail('Eskalasi peran harus ditolak');
        } catch (AuthorizationException) {
            $this->assertSame('cashier', $this->svc->roleValue($cashier->fresh()));
        }

        try {
            $this->svc->update($manager, $cashier, ['branch_id' => $this->b->id]);
            $this->fail('Pemindahan lintas cabang harus ditolak');
        } catch (AuthorizationException) {
            $this->assertSame($this->a->id, $cashier->fresh()->branch_id);
        }

        $this->svc->update($manager, $cashier, ['name' => 'Rina Baru', 'role' => 'inventory']); // sesama staf: boleh
        $this->assertSame('inventory', $this->svc->roleValue($cashier->fresh()));
    }

    public function test_nobody_changes_own_role_or_active_status(): void
    {
        $owner = $this->u('owner');

        try {
            $this->svc->update($owner, $owner, ['role' => 'manager']);
            $this->fail('Ubah peran sendiri harus ditolak');
        } catch (AuthorizationException) {
            $this->assertSame('owner', $this->svc->roleValue($owner->fresh()));
        }

        $this->expectException(AuthorizationException::class);
        $this->svc->update($owner, $owner, ['is_active' => false]);
    }

    public function test_owner_can_edit_own_name_and_email(): void
    {
        $owner = $this->u('owner');
        $this->svc->update($owner, $owner, ['name' => 'Owner Baru', 'role' => 'owner', 'is_active' => true]);

        $this->assertSame('Owner Baru', $owner->fresh()->name);
    }

    public function test_duplicate_email_is_rejected_case_insensitively(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->make(['email' => 'KASIR@sapore.test']);
    }

    public function test_deactivating_or_changing_role_revokes_api_tokens(): void
    {
        $manager = $this->u('manager');
        $chef = $this->u('chef');
        $chef->createToken('tablet');
        $this->assertSame(1, $chef->tokens()->count());

        $this->svc->update($manager, $chef, ['is_active' => false]);
        $this->assertSame(0, $chef->fresh()->tokens()->count());
        $this->assertFalse($chef->fresh()->is_active);

        $cashier = $this->u('kasir');
        $cashier->createToken('tablet');
        $this->svc->update($manager, $cashier, ['role' => 'inventory']);
        $this->assertSame(0, $cashier->fresh()->tokens()->count());
    }

    public function test_reset_password_forces_change_and_revokes_tokens(): void
    {
        $manager = $this->u('manager');
        $cashier = $this->u('kasir');
        $cashier->forceFill(['must_change_password' => false])->save();
        $cashier->createToken('tablet');

        $this->svc->resetPassword($manager, $cashier, 'Sementara2');

        $fresh = $cashier->fresh();
        $this->assertTrue($fresh->must_change_password);
        $this->assertTrue(Hash::check('Sementara2', $fresh->password));
        $this->assertSame(0, $fresh->tokens()->count());
    }

    public function test_reset_password_rules(): void
    {
        $manager = $this->u('manager');

        try {
            $this->svc->resetPassword($manager, $this->u('owner'), 'Sementara2');
            $this->fail('Manager tidak boleh mereset Owner');
        } catch (AuthorizationException) {
        }

        try {
            $this->svc->resetPassword($this->u('owner'), $this->u('owner'), 'Sementara2');
            $this->fail('Reset akun sendiri harus lewat Ganti Password');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->resetPassword($manager, $this->u('kasir'), 'pendek');
    }
}
