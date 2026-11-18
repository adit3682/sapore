<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Stock;
use Illuminate\Console\Command;

class SyncStockRows extends Command
{
    protected $signature = 'sapore:sync-stock';

    protected $description = 'Buat baris stok (0) yang belum ada untuk setiap kombinasi bahan x cabang';

    public function handle(): int
    {
        $created = 0;
        foreach (Branch::pluck('id') as $branchId) {
            foreach (Ingredient::pluck('id') as $ingredientId) {
                $row = Stock::firstOrCreate(['ingredient_id' => $ingredientId, 'branch_id' => $branchId], ['quantity' => 0]);
                $created += $row->wasRecentlyCreated ? 1 : 0;
            }
        }
        $this->info("{$created} baris stok dibuat.");

        return self::SUCCESS;
    }
}
