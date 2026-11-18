<?php

namespace Tests\Feature;

use App\Filament\Pages\ChangePassword;
use App\Models\User;
use App\Services\PasswordService;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Butuh: trait HasApiTokens di User, kolom baru dari migration Langkah 10, dan (untuk uji halaman)
 * tabel notifications dari `php artisan make:notifications-table`.
 */
class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
        $this->user = User::where('email', 'kasir@sapore.test')->firstOrFail(); // password awal "password", wajib diganti
    }

    public function test_seeded_accounts_must_change_password(): void
    {
        foreach (User::all() as $u) {
            $this->assertTrue($u->must_change_password, "{$u->email} harus wajib ganti password");
        }
    }

    public function test_change_clears_flag_and_stores_hash(): void
    {
        app(PasswordService::class)->change($this->user, 'password', 'Rahasia123');

        $fresh = $this->user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertNotNull($fresh->password_changed_at);
        $this->assertTrue(Hash::check('Rahasia123', $fresh->password));
    }

    public function test_change_rejects_wrong_current_weak_and_same_passwords(): void
    {
        $svc = app(PasswordService::class);

        foreach ([
            ['salah', 'Rahasia123', 'current_password'],   // password saat ini salah
            ['password', 'abcdefgh', 'password'],          // tanpa angka
            ['password', 'Ab1', 'password'],               // terlalu pendek
            ['password', 'password', 'password'],          // sama dengan yang lama (dan tanpa angka)
        ] as [$current, $new, $field]) {
            try {
                $svc->change($this->user, $current, $new);
                $this->fail("Seharusnya ditolak: {$current} -> {$new}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->assertTrue($this->user->fresh()->must_change_password); // tidak ada yang berubah
    }

    public function test_change_revokes_other_tokens_but_keeps_current_one(): void
    {
        $keep = $this->user->createToken('tablet-ini')->accessToken;
        $this->user->createToken('tablet-lama');

        app(PasswordService::class)->change($this->user, 'password', 'Rahasia123', $keep->id);

        $this->assertSame(1, $this->user->fresh()->tokens()->count());
        $this->assertSame($keep->id, $this->user->fresh()->tokens()->first()->id);
    }

    public function test_panel_redirects_to_change_password_until_changed(): void
    {
        $this->actingAs($this->user)
            ->get('/admin')
            ->assertRedirect(ChangePassword::getUrl());

        $this->actingAs($this->user)
            ->get('/admin/kasir')
            ->assertRedirect(ChangePassword::getUrl());
    }

    public function test_panel_is_open_after_password_changed(): void
    {
        app(PasswordService::class)->change($this->user, 'password', 'Rahasia123');

        $this->actingAs($this->user->fresh())
            ->get('/admin')
            ->assertSuccessful();
    }

    public function test_api_blocks_business_endpoints_until_password_changed(): void
    {
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'kasir@sapore.test', 'password' => 'password', 'device_name' => 'tablet'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);
        $token = $login->json('token');

        $this->withToken($token)->getJson('/api/v1/catalog')
            ->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();   // /auth/* tetap boleh

        // konfirmasi tidak cocok / password lemah
        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password', 'password' => 'Rahasia123', 'password_confirmation' => 'Beda12345',
        ])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password', 'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh',
        ])->assertStatus(422);

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password', 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

        // token perangkat ini tetap berlaku dan sekarang boleh memakai endpoint bisnis
        $this->withToken($token)->getJson('/api/v1/catalog')->assertOk();
    }
}
