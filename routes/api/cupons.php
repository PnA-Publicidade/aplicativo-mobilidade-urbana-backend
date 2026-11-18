<?php

use App\Http\Controllers\Cupom\CupomController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php); só o passageiro tem carteira
Route::get('cupons', [CupomController::class, 'index']);
Route::post('cupons/resgatar', [CupomController::class, 'resgatar'])->middleware('throttle:20,1,cupom-resgatar');
