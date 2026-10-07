<?php

// CODEX: 5 linhas alteradas neste arquivo; limita consultas repetidas da prévia de cancelamento.

use App\Http\Controllers\Corrida\AvaliacoesCorridaController;
use App\Http\Controllers\Corrida\CobrancaCartaoController;
use App\Http\Controllers\Corrida\CobrancaPixController;
use App\Http\Controllers\Corrida\CorridaController;
use App\Http\Controllers\Corrida\CorridaMotoristaController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
// Route::middleware('throttle:30,1')->group(function () {
Route::get('buscar-endereco', [CorridaController::class, 'buscarEndereco']);
Route::post('ajustar-ponto-embarque', [CorridaController::class, 'ajustarPontoEmbarque']);
Route::get('calculos-entre-endereco', [CorridaController::class, 'calculoEntreEnderecos']);
Route::post('tracado-rota', [CorridaController::class, 'tracadoRota']);
Route::post('navegacao-rota', [CorridaController::class, 'navegacaoRota']);
// });

Route::get('motorista/situacao', [CorridaMotoristaController::class, 'situacao']);
Route::post('motorista/disponibilidade', [CorridaMotoristaController::class, 'disponibilidade']);
Route::post('motorista/posicao', [CorridaMotoristaController::class, 'posicao']);
Route::get('motorista/corridas-disponiveis', [CorridaMotoristaController::class, 'corridasDisponiveis']);
Route::post('motorista/corridas/{corrida}/aceitar', [CorridaMotoristaController::class, 'aceitar']);
Route::post('motorista/corridas/{corrida}/recusar', [CorridaMotoristaController::class, 'recusar']);
Route::post('motorista/corridas/{corrida}/{acao}', [CorridaMotoristaController::class, 'transicionar'])
    ->whereIn('acao', ['cheguei', 'iniciar', 'finalizar']);
Route::post('motorista/corridas/{corrida}/cancelar', [CorridaMotoristaController::class, 'cancelar']);
Route::post('motorista/corridas/{corrida}/confirmar-parada', [CorridaMotoristaController::class, 'confirmarParada']);
Route::post('motorista/corridas/{corrida}/destino/{alteracao}/{resposta}', [CorridaMotoristaController::class, 'responderNovoDestino'])
    ->whereNumber('alteracao')
    ->whereIn('resposta', ['aceitar', 'recusar']);

Route::get('minha-corrida-atual', [CorridaController::class, 'minhaCorridaAtual']);
Route::get('corridas/{corrida}/cancelamento', [CorridaController::class, 'previsaoCancelamento'])
    ->middleware('throttle:10,1');
Route::post('corridas/{corrida}/cancelar', [CorridaController::class, 'cancelar']);
Route::post('corridas/{corrida}/pagamento', [CorridaController::class, 'alterarPagamento']);
Route::post('corridas/{corrida}/destino', [CorridaController::class, 'pedirNovoDestino'])->middleware('throttle:10,1');
Route::delete('corridas/{corrida}/destino', [CorridaController::class, 'desistirDoNovoDestino']);
Route::post('corridas/{corrida}/pix', [CobrancaPixController::class, 'criar'])->middleware('throttle:10,1');
Route::get('corridas/{corrida}/pix', [CobrancaPixController::class, 'consultar']);
Route::post('corridas/{corrida}/pix/simular', [CobrancaPixController::class, 'simular'])->middleware('throttle:10,1');
Route::post('corridas/{corrida}/cartao', [CobrancaCartaoController::class, 'criar'])->middleware('throttle:10,1');
Route::get('corridas/{corrida}/cartao', [CobrancaCartaoController::class, 'consultar']);

Route::apiResource('corridas', CorridaController::class)->only(['index', 'store', 'show']);
Route::post('precos-corrida', [CorridaController::class, 'precosCorrida'])->middleware('throttle:30,1');
Route::get('cotacoes-corrida/{cotacao}', [CorridaController::class, 'mostrarCotacao']);
Route::get('corridas-negociada', [CorridaController::class, 'simularCorridaNegociada']);
Route::get('corrida-para-avaliar', [AvaliacoesCorridaController::class, 'pendente']);
Route::apiResource('avaliacoes-corridas', AvaliacoesCorridaController::class)
    ->only(['store']);
