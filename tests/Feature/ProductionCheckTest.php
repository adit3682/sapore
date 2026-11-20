<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_flags_accounts_that_still_use_the_default_password(): void
    {
        $this->seed(SaporeSeeder::class);   // akun contoh berpassword "password"

        $code = Artisan::call('sapore:check');
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Masih memakai password bawaan', $output);
        $this->assertStringContainsString('kasir@sapore.test', $output);
        $this->assertStringContainsString('Tidak ada akun contoh seeder', $output);
    }

    public function test_reports_runtime_requirements(): void
    {
        Artisan::call('sapore:check');
        $output = Artisan::output();

        foreach (['Ekstensi bcmath', 'Koneksi database', 'APP_DEBUG mati', 'Zona waktu Asia/Jakarta', 'Migrasi terbaru sudah jalan'] as $label) {
            $this->assertStringContainsString($label, $output);
        }
    }

    public function test_fresh_install_has_no_default_password_findings(): void
    {
        Artisan::call('sapore:install', [
            '--branch' => 'Cabang Utama', '--owner-name' => 'Budi', '--owner-email' => 'budi@resto.id', '--owner-password' => 'Sementara1',
        ]);

        Artisan::call('sapore:check');
        $output = Artisan::output();

        $this->assertStringNotContainsString('Masih memakai password bawaan', $output);
        $this->assertStringNotContainsString('Hapus/nonaktifkan', $output);
        $this->assertSame(1, User::count());
    }
}
