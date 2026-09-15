<?php

use App\Http\Controllers\Corrida\AvaliacoesCorridaController;
use App\Http\Controllers\Corrida\CorridaController;
use App\Http\Controllers\Corrida\CorridaDescontoController;
use App\Http\Controllers\Corrida\CorridaDestinoController;
use App\Http\Controllers\Corrida\CorridaFinaceiroController;
use App\Http\Controllers\Corrida\CorridaNegociacoesController;
use App\Http\Controllers\Corrida\StatusBuscaController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::get('buscar-endereco', [CorridaController::class, 'buscarEndereco']);
Route::get('calculos-entre-endereco', [CorridaController::class, 'calculoEntreEnderecos']);

Route::apiResource('corridas', CorridaController::class);
Route::get('corridas-negociada', [CorridaController::class, 'simularCorridaNegociada']);
Route::apiResource('corridas-negociacoes', CorridaNegociacoesController::class);
Route::apiResource('corrida-destinos', CorridaDestinoController::class);
Route::apiResource('corrida-descontos', CorridaDescontoController::class);
Route::apiResource('corrida-financeiros', CorridaFinaceiroController::class);
Route::apiResource('avaliacoes-corridas', AvaliacoesCorridaController::class);
Route::apiResource('status-buscas', StatusBuscaController::class);
