<?php

namespace App\Filament\Support;

use App\Support\Access;
use Illuminate\Database\Eloquent\Model;

/**
 * Untuk Filament Resource. Deklarasikan `protected static string $accessKey = 'recipes';`
 * (kunci modul di Access::matrix()). canViewAny juga menyembunyikan menu dari navigasi.
 */
trait ChecksAccess
{
    public static function canViewAny(): bool
    {
        return Access::can(auth()->user(), static::$accessKey, 'view');
    }

    public static function canView(Model $record): bool
    {
        return Access::can(auth()->user(), static::$accessKey, 'view');
    }

    public static function canCreate(): bool
    {
        return Access::can(auth()->user(), static::$accessKey, 'create');
    }

    public static function canEdit(Model $record): bool
    {
        return Access::can(auth()->user(), static::$accessKey, 'update');
    }

    public static function canDelete(Model $record): bool
    {
        return Access::can(auth()->user(), static::$accessKey, 'delete');
    }

    public static function canDeleteAny(): bool
    {
        return Access::can(auth()->user(), static::$accessKey, 'delete');
    }
}
