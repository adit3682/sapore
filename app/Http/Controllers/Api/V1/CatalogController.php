<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OrderActionRequest;
use App\Http\Resources\Api\V1\CatalogItemResource;
use App\Models\Menu;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class CatalogController extends Controller
{
    /**
     * Tanpa `updated_since`: seluruh menu & paket AKTIF.
     * Dengan `updated_since` (ISO 8601): semua yang berubah sejak itu, termasuk yang baru dinonaktifkan
     * (is_active=false) agar APK bisa menyembunyikannya. Simpan `server_time` untuk sinkron berikutnya.
     */
    public function index(OrderActionRequest $request): JsonResponse
    {
        $since = $request->query('updated_since');
        $sinceAt = $since ? Carbon::parse($since) : null;

        $menus = Menu::with('category')->when($sinceAt, fn ($q) => $q->where('updated_at', '>=', $sinceAt), fn ($q) => $q->where('is_active', true))->get();
        $packages = Package::when($sinceAt, fn ($q) => $q->where('updated_at', '>=', $sinceAt), fn ($q) => $q->where('is_active', true))->get();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'data' => CatalogItemResource::collection($menus->concat($packages)->values()),
        ]);
    }
}
