<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrderReturn extends Model
{
    protected $fillable = [
        'uuid', 'number', 'order_id', 'branch_id', 'mode', 'refund_method', 'reason',
        'subtotal_refund', 'tax_refund', 'service_refund', 'total_refund', 'cost_refund',
        'returned_by', 'returned_at',
    ];

    protected $casts = [
        'subtotal_refund' => 'decimal:2',
        'tax_refund' => 'decimal:2',
        'service_refund' => 'decimal:2',
        'total_refund' => 'decimal:2',
        'cost_refund' => 'decimal:4',
        'returned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->uuid ??= (string) Str::uuid();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class);
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
