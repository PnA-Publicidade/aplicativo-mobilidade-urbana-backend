<?php

namespace App\Http\Controllers\Estimativa;

use App\Http\Controllers\Controller;
use App\Services\EstimarRotaService;

class EstimativasController extends Controller
{
    public function __construct(
        protected EstimarRotaService $estimarRotaService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function estimarRota(): array
    {
        $enderecos = [
            [
                'name' => 'R. Coimbra, 4994',
                'formattedAddress' => 'Flodoaldo Pontes Pinto, Porto Velho - RO, 76820-556, Brazil',
                'latitude' => -8.7491451,
                'longitude' => -63.8662573,
                'order' => 0, // origem
            ],
            [
                'name' => 'R. Coimbra, 5205',
                'formattedAddress' => 'Conj. 4 de Janeiro, Porto Velho - RO, 76820-556, Brazil',
                'latitude' => -8.7478987,
                'longitude' => -63.864684,
                'order' => 1, // parada
            ],
            [
                'name' => 'Av. Nações Unidas, 555',
                'formattedAddress' => 'Km 1, Porto Velho - RO, 76804-175, Brazil',
                'latitude' => -8.765801,
                'longitude' => -63.8926692,
                'order' => 2, // destino final
            ],
        ];

        return $this->estimarRotaService->executar(enderecos: $enderecos);
    }
}
