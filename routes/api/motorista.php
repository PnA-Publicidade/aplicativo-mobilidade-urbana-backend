<?php

use App\Http\Controllers\Motorista\MotoristaController;
use App\Http\Controllers\Motorista\MotoristaDocumentoController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::get('motorista-veiculos/{motoristaId}', [MotoristaController::class, 'motoristaVeiculos']);
Route::apiResource('motoristas', MotoristaController::class);
Route::post('adicionar-veiculo-ao-motorista', [MotoristaController::class, 'adicionarVeiculoAoMotorista']);

Route::apiResource('motorista-documentos', MotoristaDocumentoController::class);
Route::put('mudar-status-documento/{motoristaDocumentoId}', [MotoristaDocumentoController::class, 'mudarStatusDocumento']);
