<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReturnItem extends Model
{
    protected $fillable = ['order_return_id', 'order_item_id', 'item_type', 'item_id', 'name', 'unit_price', 'unit_cost', 'qty', 'line_total'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'qty' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
