<?php

// CODEX: 4 linhas alteradas; publica as rotas autenticadas dos veículos da própria conta.

use App\Http\Controllers\Motorista\CarteiraMotoristaController;
use App\Http\Controllers\Motorista\GanhosController;
use App\Http\Controllers\Motorista\MetodosResgateController;
use App\Http\Controllers\Motorista\MotoristaCadastroController;
use App\Http\Controllers\Motorista\MotoristaController;
use App\Http\Controllers\Motorista\PreferenciasMotoristaController;
use App\Http\Controllers\Motorista\SolicitacoesVeiculoController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::get('motorista/cadastro', [MotoristaCadastroController::class, 'mostrar']);
Route::post('motorista/cadastro/cnh', [MotoristaCadastroController::class, 'salvarCnh']);
Route::post('motorista/cadastro/documentos', [MotoristaCadastroController::class, 'enviarDocumento']);
Route::delete('motorista/cadastro/documentos/{documento}', [MotoristaCadastroController::class, 'removerDocumento']);
// atalho de desenvolvimento: ver MotoristaCadastroController::aprovarDev
Route::post('motorista/cadastro/aprovar-dev', [MotoristaCadastroController::class, 'aprovarDev']);
Route::post('motorista/cadastro/liberar-dev', [MotoristaCadastroController::class, 'liberarDev']);
Route::get('motorista/me/solicitacoes-veiculo', [SolicitacoesVeiculoController::class, 'index']);
Route::post('motorista/me/solicitacoes-veiculo', [SolicitacoesVeiculoController::class, 'store'])->middleware('throttle:10,1');
Route::get('motorista/me/veiculos', [MotoristaController::class, 'meusVeiculos']);
Route::put('motorista/me/veiculo-ativo', [MotoristaController::class, 'selecionarMeuVeiculo']);
Route::post('motorista/me/veiculos', [MotoristaController::class, 'cadastrarMeuVeiculo']);
Route::delete('motorista/me/veiculos/{veiculo}', [MotoristaController::class, 'removerMeuVeiculo'])->whereNumber('veiculo');
Route::get('motorista/me/estatisticas', [MotoristaController::class, 'estatisticas']);
// viagem compartilhada: aceitar chamadas de grupo e lugares do carro
Route::get('motorista/me/preferencias', [PreferenciasMotoristaController::class, 'mostrar']);
Route::put('motorista/me/preferencias', [PreferenciasMotoristaController::class, 'atualizar']);
Route::get('motorista/me/ganhos', [MotoristaController::class, 'ganhos']);
// "Seus ganhos": resumo do dia, semana ou mês e a lista por trás de cada valor
Route::get('motorista/me/ganhos/resumo', [GanhosController::class, 'resumo']);
Route::get('motorista/me/ganhos/detalhes', [GanhosController::class, 'detalhes']);

// carteira, saques e para onde eles vão (chave Pix ou conta bancária)
Route::get('motorista/me/carteira', [CarteiraMotoristaController::class, 'carteira']);
Route::post('motorista/me/saques', [CarteiraMotoristaController::class, 'sacar'])->middleware('throttle:5,1,saques');
Route::get('motorista/me/saques/{saque}', [CarteiraMotoristaController::class, 'saque'])->whereNumber('saque');
Route::get('motorista/me/metodos-resgate', [MetodosResgateController::class, 'index']);
Route::post('motorista/me/metodos-resgate/codigo', [MetodosResgateController::class, 'enviarCodigo'])->middleware('throttle:3,1,codigo-resgate');
Route::post('motorista/me/metodos-resgate', [MetodosResgateController::class, 'store'])->middleware('throttle:10,1,metodos-resgate');
Route::post('motorista/me/metodos-resgate/{metodo}/principal', [MetodosResgateController::class, 'principal'])->whereNumber('metodo');
Route::delete('motorista/me/metodos-resgate/{metodo}', [MetodosResgateController::class, 'destroy'])->whereNumber('metodo');
