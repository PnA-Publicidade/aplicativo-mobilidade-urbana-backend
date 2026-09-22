<?php

use App\Http\Controllers\Corrida\AvaliacoesCorridaController;
use App\Http\Controllers\Corrida\CorridaController;
use App\Http\Controllers\Corrida\CorridaMotoristaController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::middleware('throttle:30,1')->group(function () {
    Route::get('buscar-endereco', [CorridaController::class, 'buscarEndereco']);
    Route::post('ajustar-ponto-embarque', [CorridaController::class, 'ajustarPontoEmbarque']);
    Route::get('calculos-entre-endereco', [CorridaController::class, 'calculoEntreEnderecos']);
    Route::post('tracado-rota', [CorridaController::class, 'tracadoRota']);
});

Route::get('motorista/situacao', [CorridaMotoristaController::class, 'situacao']);
Route::post('motorista/disponibilidade', [CorridaMotoristaController::class, 'disponibilidade']);
Route::post('motorista/posicao', [CorridaMotoristaController::class, 'posicao']);
Route::get('motorista/corridas-disponiveis', [CorridaMotoristaController::class, 'corridasDisponiveis']);
Route::post('motorista/corridas/{corrida}/aceitar', [CorridaMotoristaController::class, 'aceitar']);
Route::post('motorista/corridas/{corrida}/{acao}', [CorridaMotoristaController::class, 'transicionar'])
    ->whereIn('acao', ['cheguei', 'iniciar', 'finalizar']);
Route::post('motorista/corridas/{corrida}/cancelar', [CorridaMotoristaController::class, 'cancelar']);

Route::get('minha-corrida-atual', [CorridaController::class, 'minhaCorridaAtual']);
Route::get('corridas/{corrida}/cancelamento', [CorridaController::class, 'previsaoCancelamento']);
Route::post('corridas/{corrida}/cancelar', [CorridaController::class, 'cancelar']);

Route::apiResource('corridas', CorridaController::class)->only(['index', 'store', 'show']);
Route::post('precos-corrida', [CorridaController::class, 'precosCorrida'])->middleware('throttle:30,1');
Route::get('cotacoes-corrida/{cotacao}', [CorridaController::class, 'mostrarCotacao']);
Route::get('corridas-negociada', [CorridaController::class, 'simularCorridaNegociada']);
Route::get('corrida-para-avaliar', [AvaliacoesCorridaController::class, 'pendente']);
Route::apiResource('avaliacoes-corridas', AvaliacoesCorridaController::class)
    ->only(['store']);
