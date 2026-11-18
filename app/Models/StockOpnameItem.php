<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameItem extends Model
{
    protected $fillable = ['stock_opname_id', 'ingredient_id', 'system_qty', 'physical_qty', 'difference', 'stock_movement_id'];

    protected $casts = [
        'system_qty' => 'decimal:4',
        'physical_qty' => 'decimal:4',
        'difference' => 'decimal:4',
    ];

    /** Kolom input kosong berarti "belum dihitung" (NULL), bukan 0. */
    public function setPhysicalQtyAttribute($value): void
    {
        $this->attributes['physical_qty'] = ($value === '' || $value === null) ? null : $value;
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
