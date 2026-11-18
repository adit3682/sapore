<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\User;
use Filament\Notifications\Notification;

/**
 * Memberi tahu Inventory & Manager saat sebuah bahan baru saja turun di bawah stok minimum.
 * Tidak pernah menggagalkan transaksi: kegagalan hanya dicatat di log.
 *
 * @phpstan-type Crossing array{ingredient: Ingredient, balance: string, branch_id: int}
 */
class LowStockNotifier
{
    /** @param array<int, array{ingredient: Ingredient, balance: string, branch_id: int}> $crossings */
    public function notify(array $crossings): void
    {
        foreach ($crossings as $c) {
            try {
                $recipients = User::query()
                    ->where('is_active', true)
                    ->whereIn('role', ['inventory', 'manager'])
                    ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $c['branch_id']))
                    ->get();

                if ($recipients->isEmpty()) {
                    continue;
                }

                $unit = $c['ingredient']->baseUnit?->symbol;
                $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') ?: '0';

                Notification::make()
                    ->warning()
                    ->title("Stok {$c['ingredient']->name} menipis")
                    ->body("Tersisa {$fmt($c['balance'])} {$unit} (minimum {$fmt($c['ingredient']->min_stock)} {$unit}).")
                    ->sendToDatabase($recipients);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
