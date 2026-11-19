<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'uuid', 'number', 'branch_id', 'cashier_id', 'order_type', 'table_label', 'status',
        'subtotal', 'tax_rate', 'tax_amount', 'service_rate', 'service_amount', 'total',
        'payment_method', 'amount_paid', 'change_amount', 'refunded_total', 'paid_at', 'notes',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'service_rate' => 'decimal:2',
        'service_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'refunded_total' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->uuid ??= (string) Str::uuid();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }

    /** none | partial | full: seberapa besar order ini sudah diretur. */
    public function returnState(): string
    {
        $refunded = (float) $this->refunded_total;
        if ($refunded <= 0) {
            return 'none';
        }

        return $refunded + 0.004 >= (float) $this->total ? 'full' : 'partial';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
