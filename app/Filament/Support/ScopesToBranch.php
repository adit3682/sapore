<?php

namespace App\Filament\Support;

use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

/**
 * Untuk Resource yang tabelnya punya kolom branch_id. Akun cabang hanya melihat (dan hanya bisa membuka
 * lewat URL) data cabangnya; akun pusat melihat semua. Rekaman cabang lain menghasilkan 404.
 */
trait ScopesToBranch
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $scope = Access::branchScope(auth()->user());

        return $scope === null
            ? $query
            : $query->where($query->getModel()->getTable().'.branch_id', $scope);
    }
}
