<?php

namespace App\Http\Controllers\Motorista;

use App\Http\Controllers\Controller;
use App\Models\DinamicaCidade;
use App\Services\DinamicaService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mapa de calor do preço dinâmico no app do motorista. */
class DinamicaController extends Controller
{
    public function __construct(private readonly DinamicaService $dinamica) {}

    /**
     * Hexágonos de alta demanda da cidade do motorista. Sem cidade, ou com o
     * recurso desligado nela, volta vazio e o app não pinta nada.
     */
    public function mostrar(Request $request): JsonResponse
    {
        $motorista = PerfilLogado::motorista($request) ?? abort(403, 'Só o motorista vê o mapa de demanda.');

        $cidade = $motorista->cidade_id === null
            ? null
            : DinamicaCidade::query()->where('cidade_id', $motorista->cidade_id)->where('ativo', true)->first();

        if ($cidade === null) {
            return response()->json([
                'ativa' => false,
                'modo' => null,
                'raio_metros' => $this->dinamica->tamanhoCelula(),
                'regioes' => [],
                'demandas' => [],
            ]);
        }

        return response()->json([
            'ativa' => true,
            'modo' => $cidade->modo,
            'raio_metros' => $this->dinamica->tamanhoCelula(),
            'regioes' => $this->dinamica->regioesParaMapa($cidade),
            'demandas' => $this->dinamica->demandaParaMapa($cidade),
            'atualizado_em' => now()->toIso8601String(),
        ]);
    }
}
