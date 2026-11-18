<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageItem extends Model
{
    protected $fillable = ['package_id', 'menu_id', 'qty'];

    protected $casts = ['qty' => 'decimal:2'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }
}
