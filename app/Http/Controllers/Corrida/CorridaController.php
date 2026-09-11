<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Models\Corrida;
use App\Models\ProdutosCorrida;
use App\Services\EstimarRotaService;
use App\Services\ObterTracadoRotaService;
use App\Services\SimularCorridaNegociadaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CorridaController extends Controller
{
    private const METROS_POR_GRAU_LATITUDE = 111320;

    public function __construct(
        protected EstimarRotaService $estimarRotaService,
        protected SimularCorridaNegociadaService $simularCorridaNegociadaService,
        protected ObterTracadoRotaService $obterTracadoRotaService
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
    public function index(): LengthAwarePaginator
    {
        return Corrida::with(
            [
                'motorista.user',
                'passageiro.user',
                'veiculo',
                'corrida_destinos',
                'corrida_financeiro.corrida_desconto',
            ]
        )->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): void
    {
        $intinerarioPassageiro = [
            [
                'endereco_formatado' => 'R. Coimbra, 5205 - Conj. 4 de Janeiro, Porto Velho - RO, 76820-556, Brazil',
                'latitude' => -8.7478987,
                'longitude' => -63.864684,
                'ordem' => 0,
            ],
            [
                'endereco_formatado' => 'Av. Nações Unidas, 555 - Km 1, Porto Velho - RO, 76804-175, Brazil',
                'latitude' => -8.765801,
                'longitude' => -63.8926692,
                'ordem' => 1,
            ],
        ];

        $estimativaIntinerarioPassageiro = $this->estimarRotaService->executar(enderecos: $intinerarioPassageiro);
        $tempoIntinerarioPassageiro = $estimativaIntinerarioPassageiro['tempo_minutos'];
        $distanciaIntinerarioPassageiro = $estimativaIntinerarioPassageiro['distancia_km'];

        $prudutoEscolhido = 'negocia';
        $produto = ProdutosCorrida::where('codigo', $prudutoEscolhido)->first();

        $intinerarioMotoristaAteOrigem = [
            [
                'endereco_formatado' => 'R. Coimbra, 4994 - Flodoaldo Pontes Pinto, Porto Velho - RO, 76820-556, Brazil',
                'latitude' => -8.7491451,
                'longitude' => -63.8662573,
                'ordem' => 0,
            ],
            [
                'endereco_formatado' => 'R. Coimbra, 5205 - Conj. 4 de Janeiro, Porto Velho - RO, 76820-556, Brazil',
                'latitude' => -8.7478987,
                'longitude' => -63.864684,
                'ordem' => 1,
            ],
        ];
        $estimativaIntinerarioMotoristaAteOrigem = $this->estimarRotaService->executar(enderecos: $intinerarioMotoristaAteOrigem);
        $tempoIntinerarioMotoristaAteOrigem = $estimativaIntinerarioMotoristaAteOrigem['tempo_minutos'];
        $distanciaIntinerarioMotoristaAteOrigem = $estimativaIntinerarioMotoristaAteOrigem['distancia_km'];
        $tempoTotalIntinarantes = $tempoIntinerarioMotoristaAteOrigem + $tempoIntinerarioPassageiro;
        $distanciaTotalIntinerarios = $distanciaIntinerarioMotoristaAteOrigem + $distanciaIntinerarioPassageiro;
        // aqui manda o service SimularCorridaNegociadaService e manda as informacoes de intinerarios (distancia e tempo).
    }

    /**
     * Display the specified resource.
     */
    public function show(Corrida $corrida): void
    {
        //
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

        [$biasLatitude, $biasLongitude] = $this->centroDoViesDeBusca($request);

        // o viés entra na chave: a mesma busca feita de cidades diferentes
        // não pode reaproveitar o resultado uma da outra. Arredondado a ~1km
        // pra não fragmentar o cache a cada metro que o usuário anda.
        $chaveCache = sprintf(
            'busca-endereco:%s:%.2f,%.2f',
            md5(mb_strtolower(trim($endereco))),
            $biasLatitude,
            $biasLongitude
        );

        $resultados = Cache::remember(
            $chaveCache,
            now()->addHours(6),
            fn () => $this->consultarPlaces($endereco, $biasLatitude, $biasLongitude)
        );

        if (empty($resultados)) {
            return response()->json([], 404);
        }

        return response()->json($resultados);
    }

    /**
     * Centro do viés de busca: a posição do usuário quando ela vem na
     * requisição, senão o padrão configurado.
     *
     * Sem viés, o searchText do Places casa "Avenida Sete" com qualquer
     * cidade do país — era o que fazia busca ambígua trazer endereço a
     * centenas de quilômetros.
     *
     * @return array{0: float, 1: float}
     */
    private function centroDoViesDeBusca(Request $request): array
    {
        $latitude = $request->float('latitude');
        $longitude = $request->float('longitude');

        $informouPosicao = $latitude !== 0.0
            && $longitude !== 0.0
            && abs($latitude) <= 90
            && abs($longitude) <= 180;

        if ($informouPosicao) {
            return [$latitude, $longitude];
        }

        return [
            (float) config('services.google_maps.bias_latitude'),
            (float) config('services.google_maps.bias_longitude'),
        ];
    }

    /**
     * @return list<array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}>
     */
    private function consultarPlaces(string $endereco, float $biasLatitude, float $biasLongitude): array
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
                'rectangle' => $this->caixaAoRedor($biasLatitude, $biasLongitude),
            ],
        ]);

        $places = $response->json('places') ?? [];

        return array_values(array_map(
            fn (array $place) => $this->formatarPlace($place),
            $places
        ));
    }

    /**
     * Caixa (sudoeste/nordeste) ao redor do centro, a partir do raio configurado.
     *
     * O searchText só aceita "rectangle" em locationRestriction — "circle" é
     * recusado com INVALID_ARGUMENT —, então o raio vira uma caixa.
     *
     * Restrição em vez de viés de propósito: o locationBias é uma preferência
     * suave e o Google ainda devolvia "Avenida Sete de Setembro" em Salvador
     * para quem busca em Porto Velho. Não existe corrida entre as duas.
     *
     * @return array{low: array{latitude: float, longitude: float}, high: array{latitude: float, longitude: float}}
     */
    private function caixaAoRedor(float $latitude, float $longitude): array
    {
        $raioMetros = (float) config('services.google_maps.bias_raio_metros');

        $grausLatitude = $raioMetros / self::METROS_POR_GRAU_LATITUDE;

        // um grau de longitude encurta conforme se afasta do equador
        $metrosPorGrauLongitude = self::METROS_POR_GRAU_LATITUDE
            * max(cos(deg2rad($latitude)), 0.01);

        $grausLongitude = $raioMetros / $metrosPorGrauLongitude;

        return [
            'low' => [
                'latitude' => max($latitude - $grausLatitude, -90),
                'longitude' => max($longitude - $grausLongitude, -180),
            ],
            'high' => [
                'latitude' => min($latitude + $grausLatitude, 90),
                'longitude' => min($longitude + $grausLongitude, 180),
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

    public function simularCorridaNegociada(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'distancia_km' => 'required|numeric',
            'tempo_min' => 'required|numeric',
            'diferenca_negociada' => 'nullable|numeric',
            'valor_por_km' => 'nullable|numeric',
            'valor_por_minuto' => 'nullable|numeric',
            'taxa_percentual' => 'nullable|numeric',
        ]);

        return response()->json($this->simularCorridaNegociadaService->executar($dados));
    }
}
