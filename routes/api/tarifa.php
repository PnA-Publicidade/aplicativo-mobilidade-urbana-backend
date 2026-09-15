<?php

use App\Http\Controllers\Tarifa\TarifaController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::apiResource('tarifas', TarifaController::class);
