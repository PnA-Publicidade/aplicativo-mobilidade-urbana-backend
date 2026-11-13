<?php

use App\Http\Controllers\Estimativa\EstimativasController;
use Illuminate\Support\Facades\Route;

// dentro do grupo da gestão (ver routes/api/gestao.php)
Route::prefix('estimativa')->group(function () {
    Route::get('rota/distancia', [EstimativasController::class, 'estimarRota']);
});
