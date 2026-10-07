<?php

namespace App\Providers;

use App\Contracts\GatewaySaque;
use App\Services\Saque\GatewaySaqueSimulado;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GatewaySaque::class, fn () => match (config('saques.gateway')) {
            'simulado' => new GatewaySaqueSimulado,
            default => throw new InvalidArgumentException('Provedor de saque desconhecido: '.config('saques.gateway')),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
