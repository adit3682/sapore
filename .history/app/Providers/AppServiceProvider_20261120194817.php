<?php

namespace App\Providers;

use App\Models\Menu;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
        JsonResource::withoutWrapping();

        Relation::enforceMorphMap([
            'menu' => Menu::class,
            'package' => Package::class,
            'user' => User::class,
        ]);
    }
}