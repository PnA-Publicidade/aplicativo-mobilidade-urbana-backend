<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocaisPopularesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LocaisPopularesController extends Controller
{
    private const KM_POR_GRAU = 111.32;

    public function index(LocaisPopularesRequest $request): JsonResponse
    {
        $latitude = (float) $request->validated('latitude');
        $longitude = (float) $request->validated('longitude');
        $raioKm = (float) config('locais.populares.raio_km');
        $limite = (int) config('locais.populares.limite');

        $grausLatitude = $raioKm / self::KM_POR_GRAU;
        $grausLongitude = $raioKm / (self::KM_POR_GRAU * max(cos(deg2rad($latitude)), 0.01));

        $locais = DB::table('corrida_destinos')
            ->join('corridas', 'corridas.id', '=', 'corrida_destinos.corrida_id')
            ->whereIn('corrida_destinos.tipo', ['parada', 'destino'])
            ->where('corridas.tempo_solicitacao', '>=', now()->subDays((int) config('locais.populares.dias')))
            ->whereBetween('corrida_destinos.latitude', [$latitude - $grausLatitude, $latitude + $grausLatitude])
            ->whereBetween('corrida_destinos.longitude', [$longitude - $grausLongitude, $longitude + $grausLongitude])
            ->groupBy('corrida_destinos.endereco')
            ->selectRaw('corrida_destinos.endereco, MAX(corrida_destinos.nome_local) AS nome, AVG(corrida_destinos.latitude) AS latitude, AVG(corrida_destinos.longitude) AS longitude, COUNT(DISTINCT corridas.passageiro_id) AS passageiros, COUNT(*) AS vezes')
            ->havingRaw('COUNT(DISTINCT corridas.passageiro_id) >= ?', [(int) config('locais.populares.minimo_passageiros')])
            ->orderByDesc('passageiros')
            ->orderByDesc('vezes')
            ->limit($limite * 3)
            ->get()
            ->filter(fn (object $local) => $this->distanciaKm($latitude, $longitude, (float) $local->latitude, (float) $local->longitude) <= $raioKm)
            ->take($limite)
            ->map(fn (object $local) => [
                'name' => (string) ($local->nome ?: $local->endereco),
                'formattedAddress' => (string) $local->endereco,
                'latitude' => round((float) $local->latitude, 7),
                'longitude' => round((float) $local->longitude, 7),
            ])
            ->values();

        return response()->json(['data' => $locais]);
    }

    private function distanciaKm(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $dLatitude = deg2rad($latitudeB - $latitudeA);
        $dLongitude = deg2rad($longitudeB - $longitudeA);
        $a = sin($dLatitude / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($dLongitude / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
