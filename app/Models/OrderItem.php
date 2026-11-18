<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'item_type', 'item_id', 'name', 'unit_price', 'unit_cost', 'qty', 'line_total'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'qty' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
