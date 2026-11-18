<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    protected $fillable = ['ingredient_id', 'branch_id', 'quantity', 'reserved_quantity'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Stok yang masih bebas dipakai (dikurangi soft allocation). */
    public function getAvailableAttribute(): string
    {
        return bcsub((string) $this->quantity, (string) $this->reserved_quantity, 4);
    }
}
