<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RefreshController;
use App\Http\Controllers\Usuario\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/login', LoginController::class)->name('auth.login');
    Route::post('auth/register', [UsuarioController::class, 'register']);
    Route::post('/auth/verificar-codigo', [LoginController::class, 'verificarCodigo']);
});

Route::middleware('throttle:3,1')->group(function () {
    Route::post('auth/enviar-codigo', [LoginController::class, 'enviarCodigo']);
});

Route::middleware('throttle:10,1')->group(function () {
    Route::get('auth/verifica-se-conta-existe', [LoginController::class, 'verificaSeContaExiste']);
});

Route::post('auth/refresh', RefreshController::class)->middleware('throttle:30,1')->name('auth.refresh');

Route::middleware('auth:jwt')->group(function () {
    Route::post('auth/logout', LogoutController::class)->name('auth.logout');
});
