<?php

use App\Http\Controllers\Veiculo\VeiculosController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::apiResource('veiculos', VeiculosController::class);
Route::get('veiculo-por-placa/{placa}', [VeiculosController::class, 'veiculoPorPlaca']);
