<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\OrderController;
use Illuminate\Support\Facades\Route;

/*
| API v1 untuk APK Flutter (kasir). Awalan /api otomatis dari Laravel.
| Perubahan yang merusak klien lama harus masuk /api/v2, jangan mengubah v1.
*/
Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('catalog', [CatalogController::class, 'index']);

        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{uuid}', [OrderController::class, 'show']);
        Route::post('orders/{uuid}/pay', [OrderController::class, 'pay']);
        Route::post('orders/{uuid}/void', [OrderController::class, 'void']);
    });
});
