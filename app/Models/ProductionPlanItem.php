<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProductionPlanItem extends Model
{
    protected $fillable = ['production_plan_id', 'item_type', 'item_id', 'qty'];

    protected $casts = ['qty' => 'decimal:2'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    /** item_type: 'menu' | 'package' (butuh morph map, lihat README). */
    public function item(): MorphTo
    {
        return $this->morphTo('item', 'item_type', 'item_id');
    }
}
