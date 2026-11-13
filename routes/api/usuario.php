<?php

use App\Http\Controllers\Usuario\LocaisPopularesController;
use App\Http\Controllers\Usuario\LocaisSalvosController;
use App\Http\Controllers\Usuario\PerfilController;
use App\Http\Controllers\Usuario\SugestoesLocaisController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo dos apps (ver routes/api.php): o perfil logado, motorista ou passageiro
Route::get('usuario-logado', [PerfilController::class, 'usuarioLogado']);
Route::put('usuario-alterar-foto-perfil/{id}', [PerfilController::class, 'alterarFotoPerfil']);
Route::delete('usuario-remover-foto-perfil/{id}', [PerfilController::class, 'removerFotoPerfil']);
// telefone e e-mail: código primeiro, troca depois
Route::post('usuario-alterar-contato/codigo', [PerfilController::class, 'enviarCodigoDeContato'])->middleware('throttle:5,1,codigo-contato');
Route::put('usuario-alterar-contato', [PerfilController::class, 'alterarContato'])->middleware('throttle:10,1,alterar-contato');

// casa, trabalho e favoritos do passageiro
Route::get('locais-salvos', [LocaisSalvosController::class, 'index']);
Route::post('locais-salvos', [LocaisSalvosController::class, 'store']);
Route::delete('locais-salvos/{localSalvo}', [LocaisSalvosController::class, 'destroy'])->whereNumber('localSalvo');

// busca de destino: lugares em alta e sugestões de lugar
Route::get('locais/populares', [LocaisPopularesController::class, 'index'])->middleware('throttle:30,1,locais-populares');
Route::post('locais/sugestoes', [SugestoesLocaisController::class, 'store'])->middleware('throttle:10,1,sugestoes-locais');
