<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private function install(array $over = []): int
    {
        return Artisan::call('sapore:install', $over + [
            '--branch' => 'Cabang Utama',
            '--owner-name' => 'Budi Santoso',
            '--owner-email' => 'Budi@Resto.id',
            '--owner-password' => 'Sementara1',
        ]);
    }

    public function test_installs_basics_branch_and_central_owner(): void
    {
        $this->assertSame(0, $this->install());

        $owner = User::where('email', 'budi@resto.id')->firstOrFail();   // email dinormalkan huruf kecil
        $this->assertSame('owner', $owner->role->value);
        $this->assertNull($owner->branch_id);                              // akun pusat
        $this->assertTrue($owner->is_active);
        $this->assertTrue($owner->must_change_password);                   // wajib ganti saat login pertama
        $this->assertTrue(Hash::check('Sementara1', $owner->password));

        $this->assertSame('Cabang Utama', Branch::firstOrFail()->name);
        foreach (['kg', 'g', 'L', 'ml', 'pcs', 'butir', 'porsi'] as $symbol) {
            $this->assertTrue(Unit::where('symbol', $symbol)->exists(), "satuan {$symbol}");
        }
        $this->assertSame(2, UnitConversion::count());                     // kg<->g, L<->ml
        $this->assertTrue(Category::where('type', 'menu')->where('name', 'Paket')->exists());
        $this->assertTrue(Category::where('type', 'ingredient')->where('name', 'Protein')->exists());
        $this->assertSame(1, User::count());                               // tidak ada akun contoh
    }

    public function test_generates_a_random_password_when_not_given(): void
    {
        $this->assertSame(0, $this->install(['--owner-password' => '']));
        $output = Artisan::output();

        $this->assertStringContainsString('Password sementara', $output);
        preg_match('/: (\S{12})\s*$/m', $output, $m);
        $this->assertNotEmpty($m[1] ?? null);
        $this->assertTrue(Hash::check($m[1], User::where('role', 'owner')->first()->password));
    }

    public function test_refuses_when_an_owner_already_exists(): void
    {
        $this->assertSame(0, $this->install());
        $this->assertSame(1, $this->install(['--owner-email' => 'lain@resto.id']));
        $this->assertSame(1, User::count());
    }

    public function test_rejects_invalid_input_without_creating_anything(): void
    {
        $this->assertSame(1, $this->install(['--owner-email' => 'bukan-email']));
        $this->assertSame(1, $this->install(['--owner-password' => 'pendek']));

        $this->assertSame(0, User::count());
        $this->assertSame(0, Unit::count());   // tidak ada data setengah jadi
    }

    public function test_basics_are_idempotent(): void
    {
        $this->install();
        User::query()->delete();               // simulasi ulang instalasi
        $this->install();

        $this->assertSame(7, Unit::count());
        $this->assertSame(1, Branch::count());
    }
}
