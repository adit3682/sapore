<?php

namespace App\Models;

use App\Enums\RecipeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = [
        'code', 'name', 'yield_qty', 'yield_unit_id', 'total_cost', 'cost_per_portion',
        'version', 'status', 'instructions', 'photo_path', 'created_by',
    ];

    protected $casts = [
        'status' => RecipeStatus::class,
        'yield_qty' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'cost_per_portion' => 'decimal:4',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class);
    }

    public function yieldUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'yield_unit_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }
}
