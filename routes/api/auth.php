<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Usuario\UsuarioController;
use Illuminate\Support\Facades\Route;

// limite de tentativas por IP — sem isso, login/código de verificação
// podiam ser forçados na base de tentativa e erro sem nenhum bloqueio
Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/login', LoginController::class)->name('auth.login');
    Route::post('auth/register', [UsuarioController::class, 'register']);
    Route::post('/auth/verificar-codigo', [LoginController::class, 'verificarCodigo']);
    Route::post('auth/enviar-codigo', [LoginController::class, 'enviarCodigo']);
    Route::get('auth/verifica-se-conta-existe', [LoginController::class, 'verificaSeContaExiste']);
});

Route::middleware('auth:jwt')->group(function () {
    Route::post('auth/logout', LogoutController::class)->name('auth.logout');
});
