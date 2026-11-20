<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pemasangan awal PRODUKSI. Pengganti SaporeSeeder (yang membuat akun contoh berpassword "password"
 * dan hanya untuk pengembangan/tes). Membuat data dasar, cabang pertama, dan satu akun Owner.
 *
 *   php artisan sapore:install
 *   php artisan sapore:install --branch="Cabang Utama" --owner-name="Budi" --owner-email=budi@resto.id
 *
 * Aman dijalankan ulang untuk data dasar, tetapi menolak bila akun Owner sudah ada.
 */
class InstallSapore extends Command
{
    protected $signature = 'sapore:install
        {--branch= : Nama cabang pertama}
        {--owner-name= : Nama Owner}
        {--owner-email= : Email Owner (untuk login)}
        {--owner-password= : Password sementara (minimal 8 karakter). Kosong = dibuat acak dan ditampilkan sekali}';

    protected $description = 'Pasang data awal produksi: satuan, kategori, cabang pertama, dan akun Owner';

    public function handle(): int
    {
        if (User::where('role', 'owner')->exists()) {
            $this->error('Akun Owner sudah ada, instalasi awal dibatalkan. Kelola akun lewat menu Pengaturan > Pengguna.');

            return self::FAILURE;
        }

        $branchName = trim((string) ($this->option('branch') ?: $this->ask('Nama cabang pertama', 'Cabang Utama')));
        $name = trim((string) ($this->option('owner-name') ?: $this->ask('Nama Owner')));
        $email = strtolower(trim((string) ($this->option('owner-email') ?: $this->ask('Email Owner (untuk login)'))));
        $password = (string) $this->option('owner-password');

        if ($branchName === '' || $name === '') {
            $this->error('Nama cabang dan nama Owner wajib diisi.');

            return self::FAILURE;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Format email tidak valid.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('Email itu sudah dipakai akun lain.');

            return self::FAILURE;
        }

        $generated = false;
        if ($password === '') {
            $password = Str::password(12, symbols: false);
            $generated = true;
        } elseif (mb_strlen($password) < 8) {
            $this->error('Password sementara minimal 8 karakter.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($branchName, $name, $email, $password) {
            $this->seedBasics();

            Branch::firstOrCreate(['name' => $branchName], ['is_active' => true]);

            // Owner = akun pusat (tanpa cabang). Password sementara wajib diganti saat login pertama.
            (new User())->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => bcrypt($password),
                'role' => 'owner',
                'branch_id' => null,
                'is_active' => true,
                'must_change_password' => true,
            ])->save();
        });

        $this->info('Instalasi selesai.');
        $this->line("Cabang : {$branchName}");
        $this->line("Owner  : {$name} <{$email}>");
        if ($generated) {
            $this->warn("Password sementara (hanya ditampilkan sekali): {$password}");
        }
        $this->line('Login di /admin lalu ganti password saat diminta. Staf lain dibuat dari Pengaturan > Pengguna.');

        return self::SUCCESS;
    }

    /** Satuan, konversi umum, dan kategori. Idempoten. */
    private function seedBasics(): void
    {
        $units = [];
        foreach ([
            ['Kilogram', 'kg'], ['Gram', 'g'], ['Liter', 'L'], ['Mililiter', 'ml'],
            ['Pieces', 'pcs'], ['Butir', 'butir'], ['Porsi', 'porsi'],
        ] as [$unitName, $symbol]) {
            $units[$symbol] = Unit::firstOrCreate(['symbol' => $symbol], ['name' => $unitName]);
        }

        foreach ([['kg', 'g', 1000], ['L', 'ml', 1000]] as [$from, $to, $multiplier]) {
            UnitConversion::firstOrCreate(
                ['from_unit_id' => $units[$from]->id, 'to_unit_id' => $units[$to]->id, 'ingredient_id' => null],
                ['multiplier' => $multiplier]
            );
        }

        foreach (['Protein', 'Sayuran', 'Minyak', 'Tepung', 'Dairy', 'Bumbu'] as $category) {
            Category::firstOrCreate(['name' => $category, 'type' => 'ingredient']);
        }
        foreach (['Main Course', 'Paket', 'Minuman', 'Snack', 'Dessert'] as $category) {
            Category::firstOrCreate(['name' => $category, 'type' => 'menu']);
        }
    }
}
