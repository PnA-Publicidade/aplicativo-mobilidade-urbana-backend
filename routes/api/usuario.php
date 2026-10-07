<?php

use App\Http\Controllers\Usuario\LocaisPopularesController;
use App\Http\Controllers\Usuario\LocaisSalvosController;
use App\Http\Controllers\Usuario\SugestoesLocaisController;
use App\Http\Controllers\Usuario\UsuarioController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::apiResource('users', UsuarioController::class)->only(['index', 'store', 'show', 'update']);
Route::get('usuario-logado', [UsuarioController::class, 'usuarioLogado']);
Route::delete('usuario-remover-foto-perfil/{id}', [UsuarioController::class, 'removerFotoPerfil']);
Route::post('usuario-arquivar', [UsuarioController::class, 'usuarioArquivar']);
Route::post('usuario-deletar', [UsuarioController::class, 'usuarioDeletar']);
Route::post('usuario-restaurar', [UsuarioController::class, 'usuarioRestaurar']);
Route::get('usuarios-arquivados', [UsuarioController::class, 'usuariosArquivados']);
Route::put('usuario-alterar-foto-perfil/{id}', [UsuarioController::class, 'alterarFotoPerfil']);

// casa, trabalho e favoritos do passageiro
Route::get('locais-salvos', [LocaisSalvosController::class, 'index']);
Route::post('locais-salvos', [LocaisSalvosController::class, 'store']);
Route::delete('locais-salvos/{localSalvo}', [LocaisSalvosController::class, 'destroy'])->whereNumber('localSalvo');

// busca de destino: lugares em alta e sugestões de lugar
Route::get('locais/populares', [LocaisPopularesController::class, 'index'])->middleware('throttle:30,1');
Route::post('locais/sugestoes', [SugestoesLocaisController::class, 'store'])->middleware('throttle:10,1');

Route::get('/user', function (Request $request) {
    /** @var JWTGuard */
    $guard = auth('jwt');
    $uid = $guard->payload()->get('uid');

    return [
        'user' => $request->user(),
        'uid' => $uid,
    ];
});
