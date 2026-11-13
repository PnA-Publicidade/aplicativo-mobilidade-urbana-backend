<?php

use App\Http\Controllers\Veiculo\VeiculosController;
use Illuminate\Support\Facades\Route;

// dentro do grupo da gestão (ver routes/api/gestao.php)
Route::apiResource('veiculos', VeiculosController::class);
Route::get('veiculo-por-placa/{placa}', [VeiculosController::class, 'veiculoPorPlaca']);
