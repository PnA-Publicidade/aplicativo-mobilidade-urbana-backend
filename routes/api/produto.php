<?php

use App\Http\Controllers\Produto\ProdutoCategoriaController;
use App\Http\Controllers\Produto\ProdutosCorridaController;
use Illuminate\Support\Facades\Route;

// dentro do grupo da gestão (ver routes/api/gestao.php)
Route::apiResource('produtos-corridas', ProdutosCorridaController::class);
Route::apiResource('produto-categorias', ProdutoCategoriaController::class);
