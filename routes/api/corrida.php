<?php

// CODEX: 5 linhas alteradas neste arquivo; limita consultas repetidas da prévia de cancelamento.

use App\Http\Controllers\Corrida\AumentarOfertaNegociaController;
use App\Http\Controllers\Corrida\AvaliacoesCorridaController;
use App\Http\Controllers\Corrida\ChatCorridaController;
use App\Http\Controllers\Corrida\CobrancaCartaoController;
use App\Http\Controllers\Corrida\CobrancaPixController;
use App\Http\Controllers\Corrida\CorridaController;
use App\Http\Controllers\Corrida\CorridaMotoristaController;
use App\Http\Controllers\Corrida\NegociacaoCorridaController;
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
// Negocia: o motorista propõe, o passageiro vê as propostas e escolhe uma
Route::post('motorista/corridas/{corrida}/propostas', [NegociacaoCorridaController::class, 'propor'])
    ->middleware('throttle:30,1,negocia-propor');
Route::get('corridas/{corrida}/propostas', [NegociacaoCorridaController::class, 'propostas']);
Route::post('corridas/{corrida}/oferta', AumentarOfertaNegociaController::class)
    ->whereNumber('corrida')
    ->middleware('throttle:20,1,negocia-aumentar');
Route::post('corridas/{corrida}/propostas/{proposta}/escolher', [NegociacaoCorridaController::class, 'escolher'])
    ->whereNumber('proposta')
    ->middleware('throttle:20,1,negocia-escolher');
Route::post('corridas/{corrida}/propostas/{proposta}/recusar', [NegociacaoCorridaController::class, 'recusar'])
    ->whereNumber('proposta')
    ->middleware('throttle:30,1,negocia-recusar');
Route::post('motorista/corridas/{corrida}/{acao}', [CorridaMotoristaController::class, 'transicionar'])
    ->whereIn('acao', ['cheguei', 'iniciar', 'finalizar']);
Route::post('motorista/corridas/{corrida}/cancelar', [CorridaMotoristaController::class, 'cancelar']);
Route::post('motorista/corridas/{corrida}/confirmar-parada', [CorridaMotoristaController::class, 'confirmarParada']);
Route::post('motorista/corridas/{corrida}/destino/{alteracao}/{resposta}', [CorridaMotoristaController::class, 'responderNovoDestino'])
    ->whereNumber('alteracao')
    ->whereIn('resposta', ['aceitar', 'recusar']);

Route::get('minha-corrida-atual', [CorridaController::class, 'minhaCorridaAtual']);
Route::get('corridas/{corrida}/cancelamento', [CorridaController::class, 'previsaoCancelamento'])
    ->middleware('throttle:10,1,previa-cancelamento');
Route::post('corridas/{corrida}/cancelar', [CorridaController::class, 'cancelar']);
Route::post('corridas/{corrida}/pagamento', [CorridaController::class, 'alterarPagamento']);
Route::post('corridas/{corrida}/destino', [CorridaController::class, 'pedirNovoDestino'])->middleware('throttle:10,1,novo-destino');
Route::delete('corridas/{corrida}/destino', [CorridaController::class, 'desistirDoNovoDestino']);
Route::post('corridas/{corrida}/pix', [CobrancaPixController::class, 'criar'])->middleware('throttle:10,1,cobranca-pix');
Route::get('corridas/{corrida}/pix', [CobrancaPixController::class, 'consultar']);
Route::post('corridas/{corrida}/pix/simular', [CobrancaPixController::class, 'simular'])->middleware('throttle:10,1,simular-pix');
Route::post('corridas/{corrida}/cartao', [CobrancaCartaoController::class, 'criar'])->middleware('throttle:10,1,cobranca-cartao');
Route::get('corridas/{corrida}/cartao', [CobrancaCartaoController::class, 'consultar']);
Route::post('corridas/{corrida}/cartao/simular', [CobrancaCartaoController::class, 'simular'])->middleware('throttle:10,1,simular-cartao');

Route::get('corridas/{corrida}/mensagens', [ChatCorridaController::class, 'index'])->whereNumber('corrida');
Route::post('corridas/{corrida}/mensagens', [ChatCorridaController::class, 'store'])->whereNumber('corrida')->middleware('throttle:60,1');

Route::apiResource('corridas', CorridaController::class)->only(['index', 'store', 'show']);
Route::post('precos-corrida', [CorridaController::class, 'precosCorrida'])->middleware('throttle:30,1,precos-corrida');
Route::get('cotacoes-corrida/{cotacao}', [CorridaController::class, 'mostrarCotacao']);
Route::get('corridas-negociada', [CorridaController::class, 'simularCorridaNegociada']);
Route::get('corrida-para-avaliar', [AvaliacoesCorridaController::class, 'pendente']);
Route::apiResource('avaliacoes-corridas', AvaliacoesCorridaController::class)
    ->only(['store']);
