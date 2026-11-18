<?php

use App\Http\Controllers\Tarifa\TarifaController;
use Illuminate\Support\Facades\Route;

// dentro do grupo da gestão (ver routes/api/gestao.php)
Route::get('tarifas/opcoes', [TarifaController::class, 'opcoes']);
Route::post('tarifas/simular', [TarifaController::class, 'simular'])->middleware('throttle:60,1');
Route::apiResource('tarifas', TarifaController::class);
