<?php

use App\Http\Controllers\Auth\AutenticacaoAppController;
use Illuminate\Support\Facades\Route;

// Login de cada app: procura só na tabela do app e devolve um token que não
// vale no outro app (guards motoristas e passageiros).
foreach (['motorista' => 'motoristas', 'passageiro' => 'passageiros'] as $perfil => $guard) {
    Route::prefix("$perfil/auth")->name("$perfil.auth.")->group(function () use ($perfil, $guard) {
        Route::middleware("throttle:10,1,$perfil-auth-entrar")->group(function () use ($perfil) {
            Route::post('login', [AutenticacaoAppController::class, 'login'])->defaults('perfil', $perfil)->name('login');
            Route::post('register', [AutenticacaoAppController::class, 'register'])->defaults('perfil', $perfil)->name('register');
            Route::post('verificar-codigo', [AutenticacaoAppController::class, 'verificarCodigo'])->defaults('perfil', $perfil)->name('verificar-codigo');
        });
        Route::post('enviar-codigo', [AutenticacaoAppController::class, 'enviarCodigo'])
            ->middleware("throttle:3,1,$perfil-auth-codigo")->defaults('perfil', $perfil)->name('enviar-codigo');
        Route::middleware("throttle:10,1,$perfil-auth-conta-existe")->group(function () use ($perfil) {
            Route::get('verifica-se-conta-existe', [AutenticacaoAppController::class, 'verificaSeContaExiste'])->defaults('perfil', $perfil)->name('verifica-se-conta-existe');
            Route::post('valida-email-cadastro', [AutenticacaoAppController::class, 'validaEmailCadastro'])->defaults('perfil', $perfil)->name('valida-email-cadastro');
        });
        Route::post('refresh', [AutenticacaoAppController::class, 'refresh'])
            ->middleware("throttle:30,1,$perfil-auth-refresh")->defaults('perfil', $perfil)->name('refresh');
        Route::post('logout', [AutenticacaoAppController::class, 'logout'])
            ->middleware("auth:$guard")->defaults('perfil', $perfil)->name('logout');
    });
}
