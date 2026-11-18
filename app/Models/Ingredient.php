<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    protected $fillable = [
        'code', 'name', 'category_id', 'base_unit_id',
        'min_stock', 'max_stock', 'avg_cost', 'last_cost', 'is_active',
    ];

    protected $casts = [
        'min_stock' => 'decimal:4',
        'max_stock' => 'decimal:4',
        'avg_cost' => 'decimal:4',
        'last_cost' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // bahan baru: siapkan baris stok (0) di semua cabang agar muncul di halaman Stok Bahan
        static::created(function (self $ingredient) {
            Branch::query()->pluck('id')->each(
                fn ($id) => Stock::firstOrCreate(['ingredient_id' => $ingredient->id, 'branch_id' => $id], ['quantity' => 0])
            );
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(UnitConversion::class);
    }
}
