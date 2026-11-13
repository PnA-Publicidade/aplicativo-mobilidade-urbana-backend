<?php

use App\Http\Controllers\Publicidade\BannerDoAppController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

require __DIR__.'/api/auth.php';
require __DIR__.'/api/gestao.php';
require __DIR__.'/api/webhooks.php';

// rotas dos apps: o token do motorista ou o do passageiro (o controller pega o
// perfil certo com PerfilLogado; o do outro app recebe 403 ou 404, como antes).
// Cadastro de veículos, tarifas, produtos, publicidade, cidades, motoristas e
// documentos é da gestão (routes/api/gestao.php): token de app não chega lá.
Route::middleware('auth:motoristas,passageiros')->group(function () {
    Route::post('broadcasting/auth', fn (Request $request) => Broadcast::auth($request));
    // os banners da cidade onde o usuário está
    Route::get('banners', [BannerDoAppController::class, 'index']);

    require __DIR__.'/api/usuario.php';
    require __DIR__.'/api/motorista.php';
    require __DIR__.'/api/corrida.php';
    require __DIR__.'/api/compartilhada.php';
    require __DIR__.'/api/ajuda.php';
    require __DIR__.'/api/notificacoes.php';
    require __DIR__.'/api/pagamentos.php';
});
