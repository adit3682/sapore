<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        $forget = fn () => Cache::forget(\App\Services\SettingsService::CACHE_KEY);
        static::saved($forget);
        static::deleted($forget);
    }
}
