<?php

use App\Http\Controllers\Estimativa\EstimativasController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::prefix('estimativa')->group(function () {
    Route::get('rota/distancia', [EstimativasController::class, 'estimarRota']);
});
