<?php

use App\Http\Controllers\Passageiro\PassageiroController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::apiResource('passageiros', PassageiroController::class);
Route::get('passageiros-arquivados', [PassageiroController::class, 'passageirosArquivados']);
