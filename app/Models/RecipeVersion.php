<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['recipe_id', 'version', 'snapshot', 'created_by'];

    protected $casts = ['snapshot' => 'array'];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
