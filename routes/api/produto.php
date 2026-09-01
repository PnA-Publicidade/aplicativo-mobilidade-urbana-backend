<?php

use App\Http\Controllers\Produto\ProdutoCategoriaController;
use App\Http\Controllers\Produto\ProdutosCorridaController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::apiResource('produtos-corridas', ProdutosCorridaController::class);
Route::apiResource('produto-categorias', ProdutoCategoriaController::class);
