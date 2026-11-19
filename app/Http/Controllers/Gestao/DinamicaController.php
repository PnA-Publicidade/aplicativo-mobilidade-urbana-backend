<?php

namespace App\Http\Controllers\Gestao;

use App\Events\DinamicaAtualizada;
use App\Http\Controllers\Controller;
use App\Models\Cidade;
use App\Models\DinamicaCidade;
use App\Models\DinamicaRegiao;
use App\Services\ConfiguracaoPlataformaService;
use App\Services\DinamicaService;
use App\Support\Avisar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Preço dinâmico no painel de gestão: liga por cidade, escolhe o modo de
 * cobrança e os limites, e vê ou força os hexágonos de alta demanda.
 */
class DinamicaController extends Controller
{
    public function __construct(
        private readonly DinamicaService $dinamica,
        private readonly ConfiguracaoPlataformaService $configuracoes,
    ) {}

    public function index(): JsonResponse
    {
        $cidades = DinamicaCidade::query()
            ->with('cidade:id,nome,uf')
            ->withCount(['regioes as regioes_ativas' => fn ($q) => $q->vigentes()])
            ->orderBy('id')
            ->get()
            ->map(fn (DinamicaCidade $cidade) => $this->formatarCidade($cidade));

        return response()->json([
            'tamanho_celula_metros' => $this->dinamica->tamanhoCelula(),
            'cidades' => $cidades,
        ]);
    }

    public function atualizarGeral(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'tamanho_celula_metros' => 'required|integer|between:300,5000',
        ]);

        $this->configuracoes->definirDinamicaTamanhoCelulaMetros((int) $dados['tamanho_celula_metros']);

        // o tamanho muda as células: as regiões antigas não valem mais
        DinamicaRegiao::query()->delete();
        $this->dinamica->recalcularTodas();

        return $this->index();
    }

    /** cria ou atualiza as regras de uma cidade */
    public function salvarCidade(Request $request, int $cidade): JsonResponse
    {
        Cidade::query()->findOrFail($cidade);

        $dados = $request->validate([
            'ativo' => 'sometimes|boolean',
            'modo' => ['sometimes', Rule::in([DinamicaCidade::MODO_MULTIPLICADOR, DinamicaCidade::MODO_VALOR_FIXO])],
            'centro_latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'centro_longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'raio_km' => 'sometimes|numeric|between:1,200',
            // vazio = sem teto: o multiplicador flutua livremente com a demanda
            'multiplicador_maximo' => 'sometimes|nullable|numeric|between:1.1,10',
            'valor_maximo' => 'sometimes|numeric|between:0.5,500',
            'razao_minima' => 'sometimes|numeric|between:0.5,20',
            'razao_maxima' => 'sometimes|numeric|between:0.6,50',
            'pedidos_minimos' => 'sometimes|integer|between:1,200',
            'janela_minutos' => 'sometimes|integer|between:1,120',
            'validade_minutos' => 'sometimes|integer|between:1,120',
        ]);

        $existente = DinamicaCidade::query()->where('cidade_id', $cidade)->first();
        $razaoMinima = (float) ($dados['razao_minima'] ?? $existente?->razao_minima ?? 1.5);
        $razaoMaxima = (float) ($dados['razao_maxima'] ?? $existente?->razao_maxima ?? 3);

        if ($razaoMaxima <= $razaoMinima) {
            return response()->json([
                'message' => 'A razão máxima precisa ser maior que a mínima.',
                'errors' => ['razao_maxima' => ['A razão máxima precisa ser maior que a mínima.']],
            ], 422);
        }

        // fresh(): os padrões da tabela (limites, janela...) só chegam ao modelo relido
        $registro = DinamicaCidade::query()->updateOrCreate(['cidade_id' => $cidade], $dados)->fresh(['cidade']);

        if (($dados['ativo'] ?? null) === true && ! $registro->temCentro()) {
            return response()->json([
                'message' => 'Informe o centro da cidade (latitude e longitude) antes de ligar.',
                'errors' => ['centro_latitude' => ['Informe o centro da cidade antes de ligar.']],
            ], 422);
        }

        $this->dinamica->recalcularCidade($registro);

        return response()->json($this->formatarCidade($registro));
    }

    public function regioes(int $cidade): JsonResponse
    {
        $config = DinamicaCidade::query()->where('cidade_id', $cidade)->firstOrFail();

        return response()->json([
            'data' => $this->dinamica->regioesParaMapa($config),
        ]);
    }

    /** força um hexágono: a gestão manda esquentar este ponto */
    public function forcarRegiao(Request $request, int $cidade): JsonResponse
    {
        $config = DinamicaCidade::query()->where('cidade_id', $cidade)->firstOrFail();

        $dados = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'multiplicador' => 'nullable|numeric|between:1,10',
            'valor_adicional' => 'nullable|numeric|between:0,500',
            'minutos' => 'nullable|integer|between:1,1440',
        ]);

        $regiao = $this->dinamica->forcarRegiao(
            $config,
            (float) $dados['latitude'],
            (float) $dados['longitude'],
            (float) ($dados['multiplicador'] ?? 1),
            (float) ($dados['valor_adicional'] ?? 0),
            isset($dados['minutos']) ? (int) $dados['minutos'] : null,
        );

        return response()->json($this->dinamica->descreverRegiao($regiao, $config), 201);
    }

    public function removerRegiao(int $cidade, int $regiao): JsonResponse
    {
        DinamicaRegiao::query()->where('cidade_id', $cidade)->whereKey($regiao)->delete();
        Avisar::semQuebrar(new DinamicaAtualizada);

        return response()->json(null, 204);
    }

    public function recalcular(int $cidade): JsonResponse
    {
        $config = DinamicaCidade::query()->where('cidade_id', $cidade)->firstOrFail();
        $this->dinamica->recalcularCidade($config);

        return response()->json(['data' => $this->dinamica->regioesParaMapa($config)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatarCidade(DinamicaCidade $cidade): array
    {
        return [
            'cidade_id' => $cidade->cidade_id,
            'nome' => $cidade->cidade?->nome,
            'uf' => $cidade->cidade?->uf,
            'ativo' => $cidade->ativo,
            'modo' => $cidade->modo,
            'centro_latitude' => $cidade->centro_latitude,
            'centro_longitude' => $cidade->centro_longitude,
            'raio_km' => $cidade->raio_km,
            'multiplicador_maximo' => $cidade->multiplicador_maximo,
            'valor_maximo' => $cidade->valor_maximo,
            'razao_minima' => $cidade->razao_minima,
            'razao_maxima' => $cidade->razao_maxima,
            'pedidos_minimos' => $cidade->pedidos_minimos,
            'janela_minutos' => $cidade->janela_minutos,
            'validade_minutos' => $cidade->validade_minutos,
            'regioes_ativas' => (int) ($cidade->regioes_ativas ?? $cidade->regioes()->vigentes()->count()),
        ];
    }
}
