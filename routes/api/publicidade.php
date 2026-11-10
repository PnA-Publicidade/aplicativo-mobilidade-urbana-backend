<?php

use App\Http\Controllers\Publicidade\BannerDoAppController;
use App\Http\Controllers\Publicidade\BannerPublicidadeController;
use App\Http\Controllers\Publicidade\CidadeController;
use Illuminate\Support\Facades\Route;

// leitura para os apps: os banners da cidade onde o usuário está
Route::get('banners', [BannerDoAppController::class, 'index']);

// já dentro do grupo auth:jwt (ver routes/api.php). Sem papel de gestão por
// enquanto: qualquer conta logada gerencia, como o resto do painel.
Route::get('publicidades-arquivados', [BannerPublicidadeController::class, 'arquivados']);
Route::post('publicidades/{banner}/restaurar', [BannerPublicidadeController::class, 'restaurar'])
    ->withTrashed();
Route::apiResource('publicidades', BannerPublicidadeController::class)
    ->parameters(['publicidades' => 'banner']);
Route::apiResource('cidades', CidadeController::class);
