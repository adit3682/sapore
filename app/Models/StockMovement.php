<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sumber kebenaran stok. Jangan ubah stocks.quantity tanpa membuat baris di sini.
 * Baris bersifat append-only (tidak diedit/dihapus).
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid', 'ingredient_id', 'branch_id', 'type', 'qty', 'unit_id', 'qty_base',
        'unit_cost', 'balance_after', 'reference_type', 'reference_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'type' => MovementType::class,
        'qty' => 'decimal:4',
        'qty_base' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'balance_after' => 'decimal:4',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
