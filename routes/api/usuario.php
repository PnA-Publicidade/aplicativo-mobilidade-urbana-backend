<?php

use App\Http\Controllers\Usuario\UsuarioController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::apiResource('users', UsuarioController::class);
Route::get('usuario-logado', [UsuarioController::class, 'usuarioLogado']);
Route::delete('usuario-remover-foto-perfil/{id}', [UsuarioController::class, 'removerFotoPerfil']);
Route::post('usuario-arquivar', [UsuarioController::class, 'usuarioArquivar']);
Route::post('usuario-deletar', [UsuarioController::class, 'usuarioDeletar']);
Route::post('usuario-restaurar', [UsuarioController::class, 'usuarioRestaurar']);
Route::get('usuarios-arquivados', [UsuarioController::class, 'usuariosArquivados']);
Route::put('usuario-alterar-foto-perfil/{id}', [UsuarioController::class, 'alterarFotoPerfil']);

Route::get('/user', function (Request $request) {
    /** @var JWTGuard */
    $guard = auth('jwt');
    $uid = $guard->payload()->get('uid');

    return [
        'user' => $request->user(),
        'uid' => $uid,
    ];
});
