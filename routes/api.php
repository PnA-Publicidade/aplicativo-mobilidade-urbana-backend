<?php

use Illuminate\Support\Facades\Route;

// Rotas organizadas por domínio em routes/api/*.php, espelhando as pastas
// de App\Http\Controllers\<Dominio>. Cada arquivo só declara Route::...,
// sem se preocupar com middleware — ele herda o grupo em que é incluído
// aqui embaixo.

// auth.php cuida do próprio agrupamento (parte pública com throttle, parte
// autenticada com auth:jwt), por isso é incluído fora do grupo abaixo.
require __DIR__.'/api/auth.php';

Route::middleware('auth:jwt')->group(function () {
    require __DIR__.'/api/usuario.php';
    require __DIR__.'/api/veiculo.php';
    require __DIR__.'/api/motorista.php';
    require __DIR__.'/api/passageiro.php';
    require __DIR__.'/api/corrida.php';
    require __DIR__.'/api/tarifa.php';
    require __DIR__.'/api/produto.php';
    require __DIR__.'/api/estimativa.php';
});
