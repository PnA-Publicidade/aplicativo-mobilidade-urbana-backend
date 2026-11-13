<?php

use App\Http\Controllers\Publicidade\BannerPublicidadeController;
use App\Http\Controllers\Publicidade\CidadeController;
use Illuminate\Support\Facades\Route;

// dentro do grupo da gestão (ver routes/api/gestao.php); os apps leem os
// banners por GET /api/banners (routes/api.php)
Route::get('publicidades-arquivados', [BannerPublicidadeController::class, 'arquivados']);
Route::post('publicidades/{banner}/restaurar', [BannerPublicidadeController::class, 'restaurar'])
    ->withTrashed();
Route::apiResource('publicidades', BannerPublicidadeController::class)
    ->parameters(['publicidades' => 'banner']);
Route::apiResource('cidades', CidadeController::class);
