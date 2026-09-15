<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

class Avisar
{
    public static function semQuebrar(object $evento): void
    {
        try {
            event($evento);
        } catch (Throwable $falha) {
            Log::warning('Broadcast falhou, seguindo sem ele', [
                'evento' => $evento::class,
                'erro' => $falha->getMessage(),
            ]);
        }
    }
}
