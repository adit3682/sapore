<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Pengaturan usaha. Urutan prioritas:
 *   1. tarif khusus cabang (khusus pajak dan service, kolom branches.tax_rate / service_rate bila tidak NULL)
 *   2. pengaturan umum yang disimpan di database (diubah dari menu Pengaturan > Pengaturan Usaha)
 *   3. nilai bawaan di config/sapore.php (dari .env)
 *
 * Nilai 0 sah (mis. tanpa service charge) dan tidak dianggap "kosong".
 * Perubahan hanya berlaku untuk order BARU: tarif yang dipakai disalin ke tiap order saat dibuat.
 */
class SettingsService
{
    public const CACHE_KEY = 'sapore.settings';

    /** @return array<string, ?string> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::pluck('value', 'key')->all());
    }

    public function get(string $key): ?string
    {
        $value = $this->all()[$key] ?? null;

        return $value === '' ? null : $value;
    }

    public function set(string $key, string|int|float|null $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
    }

    /** @param array<string, string|int|float|null> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    /** Pajak dalam persen (string, mis. "10" atau "11.5"). */
    public function taxRate(?int $branchId = null): string
    {
        return $this->rate('tax_rate', 10, $branchId);
    }

    /** Service charge dalam persen. */
    public function serviceRate(?int $branchId = null): string
    {
        return $this->rate('service_rate', 5, $branchId);
    }

    /** Target food cost (persen). */
    public function foodCostTarget(): string
    {
        return $this->percent($this->get('food_cost_target') ?? config('sapore.food_cost_target', 35));
    }

    /** @return array{name: string, footer: string, paper: int} */
    public function receipt(): array
    {
        $paper = (int) ($this->get('receipt_paper') ?? config('sapore.receipt.paper', 80));

        return [
            'name' => (string) ($this->get('receipt_name') ?? config('sapore.receipt.name', 'SAPORÉ')),
            'footer' => (string) ($this->get('receipt_footer') ?? config('sapore.receipt.footer', 'Terima kasih atas kunjungan Anda')),
            'paper' => $paper === 58 ? 58 : 80,
        ];
    }

    private function rate(string $key, int $fallback, ?int $branchId): string
    {
        if ($branchId) {
            $branch = Branch::whereKey($branchId)->first(['id', $key]);
            if ($branch && $branch->{$key} !== null) {
                return $this->percent($branch->{$key});
            }
        }

        return $this->percent($this->get($key) ?? config("sapore.{$key}", $fallback));
    }

    /** "10.00" -> "10", "11.50" -> "11.5", "0.00" -> "0". */
    private function percent(mixed $value): string
    {
        $v = number_format((float) $value, 2, '.', '');

        return rtrim(rtrim($v, '0'), '.') ?: '0';
    }
}
