<?php

use App\Http\Controllers\Tarifa\TarifaController;
use Illuminate\Support\Facades\Route;

// dentro do grupo da gestão (ver routes/api/gestao.php)
Route::apiResource('tarifas', TarifaController::class);
