<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

require __DIR__.'/api/auth.php';
require __DIR__.'/api/webhooks.php';

Route::middleware('auth:jwt')->group(function () {
    Route::post('broadcasting/auth', fn (Request $request) => Broadcast::auth($request));

    require __DIR__.'/api/usuario.php';
    require __DIR__.'/api/veiculo.php';
    require __DIR__.'/api/motorista.php';
    require __DIR__.'/api/passageiro.php';
    require __DIR__.'/api/corrida.php';
    require __DIR__.'/api/tarifa.php';
    require __DIR__.'/api/produto.php';
    require __DIR__.'/api/publicidade.php';
    require __DIR__.'/api/estimativa.php';
    require __DIR__.'/api/ajuda.php';
    require __DIR__.'/api/notificacoes.php';
    require __DIR__.'/api/pagamentos.php';
});
