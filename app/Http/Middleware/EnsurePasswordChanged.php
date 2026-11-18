<?php

namespace App\Http\Middleware;

use App\Filament\Pages\ChangePassword;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel web: selama must_change_password = true, semua halaman dialihkan ke Ganti Password
 * (kecuali halaman itu sendiri, keluar/logout, dan permintaan Livewire).
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            if ($request->is('livewire*') || $request->routeIs('filament.*.pages.ganti-password', 'filament.*.auth.logout')) {
                return $next($request);
            }

            return redirect(ChangePassword::getUrl());
        }

        return $next($request);
    }
}
