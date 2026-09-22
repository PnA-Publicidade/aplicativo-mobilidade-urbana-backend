<?php

use App\Http\Controllers\Publicidade\BannerPublicidadeController;
use Illuminate\Support\Facades\Route;

// já dentro do grupo auth:jwt (ver routes/api.php)
Route::apiResource('publicidades', BannerPublicidadeController::class);
