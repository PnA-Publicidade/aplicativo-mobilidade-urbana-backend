<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Models\Corrida;
use App\Models\ProdutosCorrida;
use App\Services\EstimarRotaService;
use App\Services\SimularCorridaNegociadaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CorridaController extends Controller
{
    public function __construct(
        protected EstimarRotaService $estimarRotaService,
        protected SimularCorridaNegociadaService $simularCorridaNegociadaService
    ) {}

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

        if (empty($endereco)) {
            return response()->json(null);
        }

        $response = Http::get(
            'https://maps.googleapis.com/maps/api/place/textsearch/json',
            [
                'query' => $endereco,
                'key' => config('services.google_maps.key'),
                'language' => 'pt-BR',
            ]
        );

        $data = $response->json();

        if (empty($data['results'])) {
            return response()->json(null);
        }

        $resultado = $data['results'][0];

        $name = $resultado['name'] ?? '';
        $formattedAddress = $resultado['formatted_address'] ?? '';

        // Remove o texto do "name" do início do formattedAddress
        if (! empty($name) && str_contains($formattedAddress, $name)) {

            // Remove o name + vírgula/espaço após ele
            $formattedAddress = preg_replace(
                '/^'.preg_quote($name, '/').'\s*,?\s*-?\s*/u',
                '',
                $formattedAddress
            );

            // Remove possíveis vírgulas/espaços sobrando no início
            $formattedAddress = ltrim($formattedAddress, ', -');
        }

        return response()->json([
            'name' => $name,
            'formattedAddress' => $formattedAddress,
            'latitude' => $resultado['geometry']['location']['lat'],
            'longitude' => $resultado['geometry']['location']['lng'],
        ]);
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
