<?php

use App\Http\Controllers\Corrida\AvaliacoesCorridaController;
use App\Http\Controllers\Corrida\CorridaController;
use App\Http\Controllers\Corrida\CorridaDescontoController;
use App\Http\Controllers\Corrida\CorridaDestinoController;
use App\Http\Controllers\Corrida\CorridaFinaceiroController;
use App\Http\Controllers\Corrida\CorridaMotoristaController;
use App\Http\Controllers\Corrida\CorridaNegociacoesController;
use App\Http\Controllers\Corrida\StatusBuscaController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::get('buscar-endereco', [CorridaController::class, 'buscarEndereco']);
Route::get('calculos-entre-endereco', [CorridaController::class, 'calculoEntreEnderecos']);
Route::post('tracado-rota', [CorridaController::class, 'tracadoRota']);

Route::get('motorista/situacao', [CorridaMotoristaController::class, 'situacao']);
Route::post('motorista/disponibilidade', [CorridaMotoristaController::class, 'disponibilidade']);
Route::post('motorista/posicao', [CorridaMotoristaController::class, 'posicao']);
Route::get('motorista/corridas-disponiveis', [CorridaMotoristaController::class, 'corridasDisponiveis']);
Route::post('motorista/corridas/{corrida}/aceitar', [CorridaMotoristaController::class, 'aceitar']);
Route::post('motorista/corridas/{corrida}/{acao}', [CorridaMotoristaController::class, 'transicionar'])
    ->whereIn('acao', ['cheguei', 'iniciar', 'finalizar']);
Route::post('motorista/corridas/{corrida}/cancelar', [CorridaMotoristaController::class, 'cancelar']);

Route::get('minha-corrida-atual', [CorridaController::class, 'minhaCorridaAtual']);
Route::post('corridas/{corrida}/cancelar', [CorridaController::class, 'cancelar']);

Route::apiResource('corridas', CorridaController::class);
Route::post('precos-corrida', [CorridaController::class, 'precosCorrida']);
Route::get('cotacoes-corrida/{cotacao}', [CorridaController::class, 'mostrarCotacao']);
Route::get('corridas-negociada', [CorridaController::class, 'simularCorridaNegociada']);
Route::apiResource('corridas-negociacoes', CorridaNegociacoesController::class);
Route::apiResource('corrida-destinos', CorridaDestinoController::class);
Route::apiResource('corrida-descontos', CorridaDescontoController::class);
Route::apiResource('corrida-financeiros', CorridaFinaceiroController::class);
Route::get('corrida-para-avaliar', [AvaliacoesCorridaController::class, 'pendente']);
Route::apiResource('avaliacoes-corridas', AvaliacoesCorridaController::class)
    ->only(['store']);
Route::apiResource('status-buscas', StatusBuscaController::class);
