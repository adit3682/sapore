<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = ['name', 'address', 'phone', 'tax_rate', 'service_rate', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'tax_rate' => 'decimal:2',
        'service_rate' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // cabang baru: siapkan baris stok (0) untuk semua bahan
        static::created(function (self $branch) {
            Ingredient::query()->pluck('id')->each(
                fn ($id) => Stock::firstOrCreate(['ingredient_id' => $id, 'branch_id' => $branch->id], ['quantity' => 0])
            );
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }
}
