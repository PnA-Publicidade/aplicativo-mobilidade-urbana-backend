<?php

// CODEX: 4 linhas alteradas; publica as rotas autenticadas dos veículos da própria conta.

use App\Http\Controllers\Motorista\CarteiraMotoristaController;
use App\Http\Controllers\Motorista\MetodosResgateController;
use App\Http\Controllers\Motorista\MotoristaCadastroController;
use App\Http\Controllers\Motorista\MotoristaController;
use App\Http\Controllers\Motorista\MotoristaDocumentoController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::get('motorista/cadastro', [MotoristaCadastroController::class, 'mostrar']);
Route::post('motorista/cadastro/cnh', [MotoristaCadastroController::class, 'salvarCnh']);
Route::post('motorista/cadastro/documentos', [MotoristaCadastroController::class, 'enviarDocumento']);
Route::delete('motorista/cadastro/documentos/{documento}', [MotoristaCadastroController::class, 'removerDocumento']);
// atalho de desenvolvimento: ver MotoristaCadastroController::aprovarDev
Route::post('motorista/cadastro/aprovar-dev', [MotoristaCadastroController::class, 'aprovarDev']);
Route::get('motorista/me/veiculos', [MotoristaController::class, 'meusVeiculos']);
Route::post('motorista/me/veiculos', [MotoristaController::class, 'cadastrarMeuVeiculo']);
Route::get('motorista/me/estatisticas', [MotoristaController::class, 'estatisticas']);
Route::get('motorista/me/ganhos', [MotoristaController::class, 'ganhos']);

// carteira, saques e para onde eles vão (chave Pix ou conta bancária)
Route::get('motorista/me/carteira', [CarteiraMotoristaController::class, 'carteira']);
Route::post('motorista/me/saques', [CarteiraMotoristaController::class, 'sacar'])->middleware('throttle:5,1');
Route::get('motorista/me/saques/{saque}', [CarteiraMotoristaController::class, 'saque'])->whereNumber('saque');
Route::get('motorista/me/metodos-resgate', [MetodosResgateController::class, 'index']);
Route::post('motorista/me/metodos-resgate/codigo', [MetodosResgateController::class, 'enviarCodigo'])->middleware('throttle:3,1');
Route::post('motorista/me/metodos-resgate', [MetodosResgateController::class, 'store'])->middleware('throttle:10,1');
Route::post('motorista/me/metodos-resgate/{metodo}/principal', [MetodosResgateController::class, 'principal'])->whereNumber('metodo');
Route::delete('motorista/me/metodos-resgate/{metodo}', [MetodosResgateController::class, 'destroy'])->whereNumber('metodo');

Route::get('motorista-veiculos/{motoristaId}', [MotoristaController::class, 'motoristaVeiculos']);
Route::apiResource('motoristas', MotoristaController::class);
Route::post('adicionar-veiculo-ao-motorista', [MotoristaController::class, 'adicionarVeiculoAoMotorista']);

Route::get('motorista-documentos/tipos', [MotoristaDocumentoController::class, 'tipos']);
Route::get('motorista-documentos/motivos-reprovacao', [MotoristaDocumentoController::class, 'motivosReprovacao']);
Route::get('motorista-documentos/{motoristaId}/resumo', [MotoristaDocumentoController::class, 'resumo']);
Route::get('motorista-documentos/{motoristaDocumentoId}/download', [MotoristaDocumentoController::class, 'baixar']);
Route::apiResource('motorista-documentos', MotoristaDocumentoController::class);
Route::put('mudar-status-documento/{motoristaDocumentoId}', [MotoristaDocumentoController::class, 'mudarStatusDocumento']);
