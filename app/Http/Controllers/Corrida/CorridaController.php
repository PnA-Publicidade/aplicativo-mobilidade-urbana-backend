<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Models\Corrida;
use App\Models\CotacaoCorrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Services\CalcularPrecoCorridaService;
use App\Services\DespachoCorridaService;
use App\Services\EstimarChegadaService;
use App\Services\EstimarRotaService;
use App\Services\ObterTracadoRotaService;
use App\Services\ResolverTarifaService;
use App\Services\SimularCorridaNegociadaService;
use App\Services\SolicitarCorridaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CorridaController extends Controller
{
    private const STATUS_ATIVOS = [
        'solicitada',
        'em_busca',
        'aceita',
        'motorista_chegou',
        'em_andamento',
    ];

    private const METROS_POR_GRAU_LATITUDE = 111320;

    public function __construct(
        protected EstimarRotaService $estimarRotaService,
        protected SimularCorridaNegociadaService $simularCorridaNegociadaService,
        protected ObterTracadoRotaService $obterTracadoRotaService,
        protected ResolverTarifaService $resolverTarifaService,
        protected CalcularPrecoCorridaService $calcularPrecoCorridaService,
        protected SolicitarCorridaService $solicitarCorridaService,
        protected DespachoCorridaService $despachoCorridaService,
        protected EstimarChegadaService $estimarChegadaService
    ) {}

    public function tracadoRota(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pontos' => 'required|array|min:2',
            'pontos.*.latitude' => 'required|numeric|between:-90,90',
            'pontos.*.longitude' => 'required|numeric|between:-180,180',
        ]);

        $pontos = array_values(array_map(
            fn (array $ponto) => [
                'latitude' => (float) $ponto['latitude'],
                'longitude' => (float) $ponto['longitude'],
            ],
            $dados['pontos']
        ));

        return response()->json([
            'coordinates' => $this->obterTracadoRotaService->executar($pontos),
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, Corrida>
     */
    /**
     * @return LengthAwarePaginator<int, Corrida>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return $this->doUsuario($request)
            ->with([
                'produto',
                'motorista.user',
                'passageiro.user',
                'veiculo',
                'corrida_destinos',
                'corrida_financeiro.corrida_desconto',
            ])
            ->orderByDesc('id')
            ->paginate();
    }

    public function cancelar(Request $request, int $corrida): JsonResponse
    {
        $dados = $request->validate(['motivo' => 'nullable|string|max:255']);

        $passageiroId = Passageiro::where('user_id', $request->user()->id)->value('id');

        try {
            $cancelada = $this->despachoCorridaService->cancelar(
                corridaId: $corrida,
                quem: 'passageiro',
                donoId: $passageiroId === null ? null : (int) $passageiroId,
                motivo: $dados['motivo'] ?? null
            );
        } catch (RuntimeException $excecao) {
            $status = in_array($excecao->getCode(), [404, 409], true)
                ? (int) $excecao->getCode()
                : 422;

            return response()->json(['message' => $excecao->getMessage()], $status);
        }

        return response()->json($cancelada);
    }

    public function minhaCorridaAtual(Request $request): JsonResponse
    {
        $corrida = $this->doUsuario($request)
            ->whereIn('status_corrida', self::STATUS_ATIVOS)
            ->with(['motorista.user', 'veiculo', 'corrida_destinos', 'corrida_financeiro'])
            ->orderByDesc('id')
            ->first();

        if ($corrida === null) {
            return response()->json(['corrida' => null]);
        }

        $posicao = $corrida->motorista_id === null
            ? null
            : $this->despachoCorridaService->posicaoDoMotorista($corrida->motorista_id);

        return response()->json([
            'corrida' => $corrida,
            'motorista_posicao' => $posicao,
            'chegada' => $this->estimarChegadaService->paraCorrida($corrida),
        ]);
    }

    /**
     * @return Builder<Corrida>
     */
    private function doUsuario(Request $request): Builder
    {
        $usuarioId = $request->user()->id;

        $passageiroId = Passageiro::where('user_id', $usuarioId)->value('id');
        $motoristaId = Motorista::where('user_id', $usuarioId)->value('id');

        return Corrida::query()->where(function (Builder $consulta) use ($passageiroId, $motoristaId) {
            $consulta->whereRaw('1 = 0');

            if ($passageiroId !== null) {
                $consulta->orWhere('passageiro_id', $passageiroId);
            }

            if ($motoristaId !== null) {
                $consulta->orWhere('motorista_id', $motoristaId);
            }
        });
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'cotacao_id' => 'required|uuid',
            'produto_codigo' => 'required|string|max:60',
            'metodo_pagamento' => 'nullable|in:dinheiro,cartao,pix',
        ]);

        $cotacao = CotacaoCorrida::find((string) $dados['cotacao_id']);

        if ($cotacao === null || $cotacao->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Cotação não encontrada.'], 404);
        }

        if ($cotacao->expirada()) {
            return response()->json(['message' => 'Cotação expirada. Refaça a consulta de preços.'], 410);
        }

        try {
            $corrida = $this->solicitarCorridaService->executar(
                usuario: $request->user(),
                cotacao: $cotacao,
                produtoCodigo: (string) $dados['produto_codigo'],
                metodoPagamento: $dados['metodo_pagamento'] ?? null
            );
        } catch (RuntimeException $excecao) {
            $status = $excecao->getCode() === 409 ? 409 : 422;

            return response()->json(['message' => $excecao->getMessage()], $status);
        }

        return response()->json($corrida, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $corrida): JsonResponse
    {
        $encontrada = $this->doUsuario($request)
            ->whereKey($corrida)
            ->with(['motorista.user', 'veiculo', 'corrida_destinos', 'corrida_financeiro'])
            ->first();

        if ($encontrada === null) {
            return response()->json(['message' => 'Corrida não encontrada.'], 404);
        }

        return response()->json($encontrada);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Corrida $corrida): void
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Corrida $corrida): void
    {
        //
    }

    public function buscarEndereco(Request $request): JsonResponse
    {
        $endereco = $request->string('endereco')->toString();

        // menos de 3 caracteres quase nunca traz resultado útil — evita
        // gastar requisição da Places API à toa
        if (mb_strlen(trim($endereco)) < 3) {
            return response()->json([], 404);
        }

        $chaveCache = 'busca-endereco:'.md5(mb_strtolower(trim($endereco)));

        $resultados = Cache::remember(
            $chaveCache,
            now()->addHours(6),
            fn () => $this->consultarPlaces($endereco)
        );

        if (empty($resultados)) {
            return response()->json([], 404);
        }

        return response()->json($this->ordenarPorProximidade($resultados, $request));
    }

    /**
     * @param  list<array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}>  $resultados
     * @return list<array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}>
     */
    private function ordenarPorProximidade(array $resultados, Request $request): array
    {
        $posicao = $this->posicaoDoUsuario($request);

        if ($posicao === null) {
            return $resultados;
        }

        [$latitude, $longitude] = $posicao;

        $ordenados = $resultados;

        usort(
            $ordenados,
            fn (array $a, array $b) => $this->faixaDeProximidade($a, $latitude, $longitude)
                <=> $this->faixaDeProximidade($b, $latitude, $longitude)
        );

        return $ordenados;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function posicaoDoUsuario(Request $request): ?array
    {
        $latitude = $request->float('latitude');
        $longitude = $request->float('longitude');

        $valida = $latitude !== 0.0
            && $longitude !== 0.0
            && abs($latitude) <= 90
            && abs($longitude) <= 180;

        return $valida ? [$latitude, $longitude] : null;
    }

    /**
     * @param  array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}  $resultado
     */
    private function faixaDeProximidade(array $resultado, float $latitude, float $longitude): int
    {
        if ($resultado['latitude'] === null || $resultado['longitude'] === null) {
            return PHP_INT_MAX;
        }

        $deltaLatitude = ($resultado['latitude'] - $latitude) * self::METROS_POR_GRAU_LATITUDE;

        $deltaLongitude = ($resultado['longitude'] - $longitude)
            * self::METROS_POR_GRAU_LATITUDE
            * max(cos(deg2rad($latitude)), 0.01);

        $quilometros = hypot($deltaLatitude, $deltaLongitude) / 1000;

        $faixa = (float) config('services.google_maps.faixa_proximidade_km');

        return (int) log(1 + $quilometros / max($faixa, 0.1), 2);
    }

    /**
     * @return list<array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}>
     */
    private function consultarPlaces(string $endereco): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => config('services.google_maps.key'),
            'X-Goog-FieldMask' => 'places.displayName,places.formattedAddress,places.location',
        ])->post('https://places.googleapis.com/v1/places:searchText', [
            'textQuery' => $endereco,
            'languageCode' => 'pt-BR',
            'maxResultCount' => (int) config('services.google_maps.max_resultados_busca'),
            'locationRestriction' => [
                'rectangle' => $this->caixaDaRegiaoAtendida(),
            ],
        ]);

        $places = $response->json('places') ?? [];

        return array_values(array_map(
            fn (array $place) => $this->formatarPlace($place),
            $places
        ));
    }

    /**
     * @return array{low: array{latitude: float, longitude: float}, high: array{latitude: float, longitude: float}}
     */
    private function caixaDaRegiaoAtendida(): array
    {
        $regiao = config('services.google_maps.regiao_atendida');

        return [
            'low' => [
                'latitude' => (float) $regiao['latitude_min'],
                'longitude' => (float) $regiao['longitude_min'],
            ],
            'high' => [
                'latitude' => (float) $regiao['latitude_max'],
                'longitude' => (float) $regiao['longitude_max'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}
     */
    private function formatarPlace(array $place): array
    {
        $name = $place['displayName']['text'] ?? '';
        $formattedAddress = $place['formattedAddress'] ?? '';

        // o formattedAddress costuma repetir o name no começo ("Shopping X,
        // Av. Y, 100") — sem tirar, a lista mostra o mesmo texto duas vezes
        if (! empty($name) && str_contains($formattedAddress, $name)) {
            $formattedAddress = preg_replace(
                '/^'.preg_quote($name, '/').'\s*,?\s*-?\s*/u',
                '',
                $formattedAddress
            ) ?? $formattedAddress;

            $formattedAddress = ltrim($formattedAddress, ', -');
        }

        return [
            'name' => $name,
            'formattedAddress' => $formattedAddress,
            'latitude' => $place['location']['latitude'] ?? null,
            'longitude' => $place['location']['longitude'] ?? null,
        ];
    }

    public function calculoEntreEnderecos(Request $request): JsonResponse
    {
        $enderecos = $request->input('enderecos', []);

        return response()->json($this->estimarRotaService->executar(enderecos: $enderecos));
    }

    public function precosCorrida(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'enderecos' => 'required|array|min:2',
            'enderecos.*.order' => 'required|integer|min:0',
            'enderecos.*.latitude' => 'required|numeric|between:-90,90',
            'enderecos.*.longitude' => 'required|numeric|between:-180,180',
            'enderecos.*.formattedAddress' => 'nullable|string|max:500',
            'cidade_id' => 'nullable|integer',
            'tempo_espera_min' => 'nullable|numeric|min:0|max:600',
        ]);

        $rota = $this->estimarRotaService->executar(enderecos: $dados['enderecos']);

        $distanciaKm = (float) ($rota['distancia_km'] ?? 0);
        $tempoMin = (float) ($rota['tempo_minutos'] ?? 0);

        if ($distanciaKm <= 0) {
            return response()->json(['message' => 'Não foi possível calcular a rota.'], 422);
        }

        $tarifas = $this->resolverTarifaService->paraTodosOsProdutos(
            isset($dados['cidade_id']) ? (int) $dados['cidade_id'] : null,
            now()
        );

        if ($tarifas->isEmpty()) {
            return response()->json(['message' => 'Nenhuma tarifa ativa para este momento.'], 422);
        }

        $categorias = $tarifas
            ->map(fn ($tarifa) => $this->calcularPrecoCorridaService->executar(
                tarifa: $tarifa,
                distanciaKm: $distanciaKm,
                tempoMin: $tempoMin,
                tempoEsperaMin: (float) ($dados['tempo_espera_min'] ?? 0)
            ))
            ->values();

        $cotacao = CotacaoCorrida::create([
            'user_id' => $request->user()->id,
            'cidade_id' => isset($dados['cidade_id']) ? (int) $dados['cidade_id'] : null,
            'distancia_km' => $distanciaKm,
            'tempo_min' => $tempoMin,
            'enderecos' => $dados['enderecos'],
            'categorias' => $categorias->all(),
            'expira_em' => now()->addMinutes(
                (int) config('precificacao.cotacao_validade_minutos')
            ),
        ]);

        return response()->json([
            'cotacao_id' => $cotacao->id,
            'expira_em' => $cotacao->expira_em->toIso8601String(),
            'rota' => $rota,
            'categorias' => $categorias,
        ]);
    }

    public function mostrarCotacao(Request $request, string $cotacao): JsonResponse
    {
        $registro = CotacaoCorrida::find($cotacao);

        if ($registro === null || $registro->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Cotação não encontrada.'], 404);
        }

        if ($registro->expirada()) {
            return response()->json(['message' => 'Cotação expirada.'], 410);
        }

        return response()->json([
            'cotacao_id' => $registro->id,
            'expira_em' => $registro->expira_em->toIso8601String(),
            'rota' => [
                'distancia_km' => $registro->distancia_km,
                'tempo_minutos' => $registro->tempo_min,
            ],
            'categorias' => $registro->categorias,
        ]);
    }

    public function simularCorridaNegociada(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'distancia_km' => [
                'required', 'numeric', 'min:0',
                'max:'.config('precificacao.distancia_maxima_km'),
            ],
            'tempo_min' => [
                'required', 'numeric', 'min:0',
                'max:'.config('precificacao.tempo_maximo_min'),
            ],
            'diferenca_negociada' => 'nullable|numeric',
        ]);

        return response()->json($this->simularCorridaNegociadaService->executar($dados));
    }
}
