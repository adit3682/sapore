<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Pemeriksaan kesiapan produksi. Jalankan setelah deploy dan setiap kali ada yang mencurigakan:
 *
 *   php artisan sapore:check
 *
 * GAGAL = harus diperbaiki sebelum dipakai (exit code 1). PERINGATAN = disarankan.
 */
class CheckProduction extends Command
{
    protected $signature = 'sapore:check';

    protected $description = 'Periksa kesiapan produksi (PHP, ekstensi, konfigurasi, database, akun)';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(): int
    {
        $this->checkRuntime();
        $this->checkConfig();
        $database = $this->checkDatabase();
        if ($database) {
            $this->checkData();
        }

        $this->table(['Pemeriksaan', 'Status', 'Keterangan'], $this->rows);

        $fails = count(array_filter($this->rows, fn ($r) => $r[1] === 'GAGAL'));
        $warns = count(array_filter($this->rows, fn ($r) => $r[1] === 'PERINGATAN'));

        if ($fails > 0) {
            $this->error("{$fails} pemeriksaan GAGAL, {$warns} peringatan. Perbaiki yang GAGAL sebelum dipakai.");

            return self::FAILURE;
        }
        $this->info("Semua pemeriksaan wajib lolos ({$warns} peringatan).");

        return self::SUCCESS;
    }

    private function checkRuntime(): void
    {
        $this->row('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'OK' : 'GAGAL', 'Versi sekarang '.PHP_VERSION);

        foreach (['bcmath', 'intl', 'mbstring', 'fileinfo', 'openssl', 'curl', 'dom', 'zip', 'ctype', 'tokenizer'] as $ext) {
            $this->row("Ekstensi {$ext}", extension_loaded($ext) ? 'OK' : 'GAGAL', extension_loaded($ext) ? '' : 'Aktifkan di PHP Configuration > Extensions (hPanel)');
        }

        $driver = config('database.connections.'.config('database.default').'.driver');
        $pdo = 'pdo_'.($driver === 'mariadb' ? 'mysql' : $driver);
        $this->row("Ekstensi {$pdo}", extension_loaded($pdo) ? 'OK' : 'GAGAL', '');

        $this->row('Export Excel (openspout)', class_exists(\OpenSpout\Writer\XLSX\Writer::class) ? 'OK' : 'GAGAL', 'composer require openspout/openspout');
        $this->row('Token API (Sanctum di User)', method_exists(User::class, 'createToken') ? 'OK' : 'GAGAL', 'Tambahkan trait HasApiTokens di model User');
    }

    private function checkConfig(): void
    {
        $this->row('APP_KEY terisi', filled(config('app.key')) ? 'OK' : 'GAGAL', 'php artisan key:generate (lalu simpan APP_KEY di tempat aman)');
        $this->row('APP_DEBUG mati', config('app.debug') ? 'GAGAL' : 'OK', 'APP_DEBUG=false; debug membocorkan isi .env dan kode ke pengunjung');
        $this->row('APP_ENV production', config('app.env') === 'production' ? 'OK' : 'PERINGATAN', 'Sekarang: '.config('app.env'));
        $this->row('APP_URL memakai https', str_starts_with((string) config('app.url'), 'https://') ? 'OK' : 'PERINGATAN', 'Sekarang: '.config('app.url'));
        $this->row('Zona waktu Asia/Jakarta', config('app.timezone') === 'Asia/Jakarta' ? 'OK' : 'PERINGATAN', 'Sekarang: '.config('app.timezone').'; laporan harian memakai jam ini');
        $this->row('Cookie sesi hanya lewat https', config('session.secure') ? 'OK' : 'PERINGATAN', 'SESSION_SECURE_COOKIE=true');
        $this->row('Konfigurasi di-cache', app()->configurationIsCached() ? 'OK' : 'PERINGATAN', 'php artisan config:cache (dijalankan deploy.sh)');
        $this->row('storage dapat ditulis', is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')) ? 'OK' : 'GAGAL', 'chmod -R 775 storage bootstrap/cache');
        $this->row('Tautan storage (foto resep)', is_link(public_path('storage')) || is_dir(public_path('storage')) ? 'OK' : 'PERINGATAN', 'php artisan storage:link');
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
            $this->row('Koneksi database', 'OK', DB::connection()->getDatabaseName());
        } catch (\Throwable $e) {
            $this->row('Koneksi database', 'GAGAL', mb_substr($e->getMessage(), 0, 90));

            return false;
        }

        $migrated = Schema::hasTable('users') && Schema::hasColumn('users', 'must_change_password')
            && Schema::hasTable('order_returns') && Schema::hasTable('notifications');
        $this->row('Migrasi terbaru sudah jalan', $migrated ? 'OK' : 'GAGAL', 'php artisan migrate --force (dan make:notifications-table bila belum ada)');

        // pemeriksaan data hanya butuh tabel dasarnya; jangan berhenti hanya karena satu migrasi belum jalan
        return Schema::hasTable('users') && Schema::hasTable('units') && Schema::hasTable('branches');
    }

    private function checkData(): void
    {
        $this->row('Satuan dasar tersedia', Unit::count() > 0 ? 'OK' : 'GAGAL', 'Jalankan php artisan sapore:install');
        $this->row('Cabang tersedia', Branch::count() > 0 ? 'OK' : 'GAGAL', 'Jalankan php artisan sapore:install');
        $this->row('Ada akun Owner aktif', User::where('role', 'owner')->where('is_active', true)->exists() ? 'OK' : 'GAGAL', 'Jalankan php artisan sapore:install');

        $weak = User::all()->filter(fn (User $u) => Hash::check('password', $u->password))->pluck('email');
        $this->row('Tidak ada akun ber-password bawaan', $weak->isEmpty() ? 'OK' : 'GAGAL', $weak->isEmpty() ? '' : 'Masih memakai password bawaan: '.$weak->implode(', '));

        $noBranch = User::where('role', '!=', 'owner')->whereNull('branch_id')->pluck('email');
        $this->row('Staf punya cabang', $noBranch->isEmpty() ? 'OK' : 'PERINGATAN', $noBranch->isEmpty() ? '' : 'Tidak melihat data apa pun: '.$noBranch->implode(', '));

        $sampleSeed = User::whereIn('email', ['owner@sapore.test', 'manager@sapore.test', 'chef@sapore.test', 'inventory@sapore.test', 'kasir@sapore.test'])->pluck('email');
        $this->row('Tidak ada akun contoh seeder', $sampleSeed->isEmpty() ? 'OK' : 'PERINGATAN', $sampleSeed->isEmpty() ? '' : 'Hapus/nonaktifkan: '.$sampleSeed->implode(', '));
    }

    private function row(string $label, string $status, string $detail): void
    {
        $this->rows[] = [$label, $status, $detail];
    }
}
