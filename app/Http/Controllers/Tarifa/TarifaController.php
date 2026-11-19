<?php

namespace App\Http\Controllers\Tarifa;

use App\Http\Controllers\Controller;
use App\Models\Cidade;
use App\Models\Estado;
use App\Models\ProdutosCorrida;
use App\Models\Tarifa;
use App\Services\CalcularPrecoCorridaService;
use App\Services\ResolverTarifaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tarifas no painel de gestão. Cada tarifa vale para um escopo: uma cidade, um
 * estado ou o país todo. Na hora de cobrar, a da cidade vence a do estado, que
 * vence a geral (ver ResolverTarifaService).
 */
class TarifaController extends Controller
{
    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function index(Request $request): array
    {
        $dados = $request->validate([
            'escopo' => ['nullable', Rule::in(['geral', 'estado', 'cidade'])],
            'produto_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
        ]);

        $tarifas = Tarifa::query()
            ->with('produto:id,nome,codigo,ordem')
            ->when($dados['produto_id'] ?? null, fn ($q, $id) => $q->where('produto_id', $id))
            ->when(($dados['escopo'] ?? null) === 'geral', fn ($q) => $q->whereNull('cidade_id')->whereNull('estado_id'))
            ->when(($dados['escopo'] ?? null) === 'estado', fn ($q) => $q->whereNull('cidade_id')->whereNotNull('estado_id'))
            ->when(($dados['escopo'] ?? null) === 'cidade', fn ($q) => $q->whereNotNull('cidade_id'))
            ->get();

        $cidades = Cidade::query()->whereIn('id', $tarifas->pluck('cidade_id')->filter())->get(['id', 'nome', 'uf'])->keyBy('id');
        $estados = Estado::query()->whereIn('id', $tarifas->pluck('estado_id')->filter())->get(['id', 'nome', 'sigla'])->keyBy('id');

        $linhas = $tarifas
            ->map(fn (Tarifa $tarifa) => $this->formatar($tarifa, $cidades->get($tarifa->cidade_id), $estados->get($tarifa->estado_id)))
            ->when(
                filled($dados['search'] ?? null),
                fn ($colecao) => $colecao->filter(fn (array $linha) => str_contains(
                    mb_strtolower($linha['produto']['nome'].' '.$linha['escopo_rotulo']),
                    mb_strtolower((string) $dados['search']),
                ))
            )
            // a mais específica primeiro, depois pela ordem das categorias
            ->sortBy([
                fn (array $a, array $b) => $a['produto']['ordem'] <=> $b['produto']['ordem'],
                fn (array $a, array $b) => $b['escopo_prioridade'] <=> $a['escopo_prioridade'],
            ])
            ->values();

        return ['data' => $linhas->all(), 'total' => $linhas->count()];
    }

    /** Estados, cidades e categorias para preencher o formulário. */
    public function opcoes(): JsonResponse
    {
        return response()->json([
            'estados' => Estado::query()->orderBy('sigla')->get(['id', 'sigla', 'nome']),
            'cidades' => Cidade::query()->orderBy('nome')->get(['id', 'nome', 'uf']),
            'produtos' => ProdutosCorrida::query()->orderBy('ordem')->get(['id', 'nome', 'codigo']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tarifa = Tarifa::create($this->validar($request));

        return response()->json(['success' => true, 'message' => 'Tarifa criada.', 'data' => $this->formatarUma($tarifa->fresh())], 201);
    }

    public function show(Tarifa $tarifa): JsonResponse
    {
        return response()->json($this->formatarUma($tarifa));
    }

    public function update(Request $request, Tarifa $tarifa): JsonResponse
    {
        $tarifa->update($this->validar($request));

        return response()->json(['success' => true, 'message' => 'Tarifa salva.', 'data' => $this->formatarUma($tarifa->fresh())]);
    }

    public function destroy(Tarifa $tarifa): JsonResponse
    {
        $tarifa->delete();

        return response()->json(['success' => true, 'message' => 'Tarifa removida.']);
    }

    /**
     * Quanto custaria uma corrida de verdade: resolve a tarifa que valeria agora
     * para a cidade e a categoria e mostra a composição, com a regulação.
     */
    public function simular(Request $request, ResolverTarifaService $resolver, CalcularPrecoCorridaService $calculo): JsonResponse
    {
        $dados = $request->validate([
            'produto_id' => 'required|integer|exists:produtos_corridas,id',
            'cidade_id' => 'nullable|integer|exists:cidades,id',
            'distancia_km' => 'required|numeric|min:0|max:1000',
            'tempo_min' => 'required|numeric|min:0|max:1440',
            'multiplicador' => 'nullable|numeric|min:1|max:10',
        ]);

        $tarifa = $resolver->paraProduto((int) $dados['produto_id'], $dados['cidade_id'] ?? null, now());

        if ($tarifa === null) {
            return response()->json(['message' => 'Nenhuma tarifa ativa vale agora para esta cidade e categoria.'], 422);
        }

        $dinamica = isset($dados['multiplicador']) && (float) $dados['multiplicador'] > 1
            ? ['modo' => 'multiplicador', 'multiplicador' => (float) $dados['multiplicador']]
            : null;

        $preco = $calculo->executar($tarifa, (float) $dados['distancia_km'], (float) $dados['tempo_min'], dinamica: $dinamica);

        return response()->json([
            'tarifa_id' => $tarifa->id,
            'escopo' => $this->escopoDe($tarifa),
            'composicao' => $preco['composicao'],
            'valores' => $preco['valores'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        $dinheiro = 'required|numeric|min:0|max:100000';

        $dados = $request->validate([
            'produto_id' => 'required|integer|exists:produtos_corridas,id',
            'cidade_id' => 'nullable|integer|exists:cidades,id',
            'estado_id' => 'nullable|integer|exists:estados,id',
            'tarifa_base' => $dinheiro,
            'valor_por_km' => $dinheiro,
            'valor_por_minuto' => $dinheiro,
            'valor_por_minuto_espera' => $dinheiro,
            'valor_minimo_corrida' => $dinheiro,
            'taxa_plataforma_percentual' => 'required|numeric|min:0|max:95',
            'regulacao_tipo' => ['nullable', Rule::in(['percentual', 'valor_fixo'])],
            'regulacao_valor' => 'nullable|required_with:regulacao_tipo|numeric|min:0|max:100000',
            'horario_inicio' => 'nullable|required_with:horario_fim|date_format:H:i,H:i:s',
            'horario_fim' => 'nullable|required_with:horario_inicio|date_format:H:i,H:i:s',
            'dias_semana' => 'nullable|array',
            'dias_semana.*' => 'integer|between:1,7',
            'raio_busca_motorista_km' => 'required|numeric|min:0.1|max:200',
            'ativo' => 'required|boolean',
        ]);

        $dados = $this->normalizar($dados);

        // a cidade já aponta o estado: dizer os dois é ambíguo
        validator($dados, [])->after(function (Validator $validador) use ($dados): void {
            if (($dados['cidade_id'] ?? null) !== null && ($dados['estado_id'] ?? null) !== null) {
                $validador->errors()->add('estado_id', 'Escolha a cidade ou o estado, não os dois.');
            }

            if (($dados['regulacao_tipo'] ?? null) === 'percentual' && (float) $dados['regulacao_valor'] > 100) {
                $validador->errors()->add('regulacao_valor', 'A regulação em percentual vai até 100%.');
            }
        })->validate();

        return $dados;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function normalizar(array $dados): array
    {
        $dias = array_values(array_unique(array_map('intval', $dados['dias_semana'] ?? [])));
        sort($dias);

        // sem dias marcados a tarifa vale a semana toda; sem regulação, os dois campos ficam nulos
        $dados['dias_semana'] = $dias === [] ? null : implode(',', $dias);
        $dados['horario_inicio'] = isset($dados['horario_inicio']) ? substr($dados['horario_inicio'].':00', 0, 8) : null;
        $dados['horario_fim'] = isset($dados['horario_fim']) ? substr($dados['horario_fim'].':00', 0, 8) : null;
        $dados['vira_dia'] = $dados['horario_inicio'] !== null && $dados['horario_fim'] < $dados['horario_inicio'];

        if (empty($dados['regulacao_tipo'])) {
            $dados['regulacao_tipo'] = null;
            $dados['regulacao_valor'] = null;
        }

        return $dados;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatarUma(Tarifa $tarifa): array
    {
        $tarifa->loadMissing('produto:id,nome,codigo,ordem');

        return $this->formatar(
            $tarifa,
            $tarifa->cidade_id === null ? null : Cidade::query()->find($tarifa->cidade_id, ['id', 'nome', 'uf']),
            $tarifa->estado_id === null ? null : Estado::query()->find($tarifa->estado_id, ['id', 'nome', 'sigla']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatar(Tarifa $tarifa, ?Cidade $cidade, ?Estado $estado): array
    {
        $dias = $tarifa->dias_semana === null || trim((string) $tarifa->dias_semana) === ''
            ? []
            : array_values(array_filter(array_map(
                fn ($d) => (int) trim((string) $d, " []\t"),
                explode(',', (string) $tarifa->dias_semana),
            ), fn (int $d) => $d >= 1 && $d <= 7));

        $escopo = $this->escopoDe($tarifa);

        return [
            'id' => $tarifa->id,
            'produto_id' => $tarifa->produto_id,
            'produto' => [
                'id' => $tarifa->produto?->id,
                'nome' => $tarifa->produto?->nome ?? 'Sem categoria',
                'codigo' => $tarifa->produto?->codigo,
                'ordem' => (int) ($tarifa->produto?->ordem ?? 999),
            ],
            'cidade_id' => $tarifa->cidade_id,
            'estado_id' => $tarifa->estado_id,
            'escopo' => $escopo,
            'escopo_prioridade' => ['cidade' => 2, 'estado' => 1, 'geral' => 0][$escopo],
            'escopo_rotulo' => match ($escopo) {
                'cidade' => ($cidade?->nome ?? 'Cidade').'/'.($cidade?->uf ?? ''),
                'estado' => 'Estado '.($estado?->sigla ?? ''),
                default => 'Geral',
            },
            'tarifa_base' => $tarifa->tarifa_base,
            'valor_por_km' => $tarifa->valor_por_km,
            'valor_por_minuto' => $tarifa->valor_por_minuto,
            'valor_por_minuto_espera' => $tarifa->valor_por_minuto_espera,
            'valor_minimo_corrida' => $tarifa->valor_minimo_corrida,
            'taxa_plataforma_percentual' => $tarifa->taxa_plataforma_percentual,
            'regulacao_tipo' => $tarifa->regulacao_tipo,
            'regulacao_valor' => $tarifa->regulacao_valor,
            'horario_inicio' => $tarifa->horario_inicio,
            'horario_fim' => $tarifa->horario_fim,
            'vira_dia' => (bool) $tarifa->vira_dia,
            'dias_semana' => $dias,
            'raio_busca_motorista_km' => $tarifa->raio_busca_motorista_km,
            'ativo' => (bool) $tarifa->ativo,
        ];
    }

    private function escopoDe(Tarifa $tarifa): string
    {
        return match (true) {
            $tarifa->cidade_id !== null => 'cidade',
            $tarifa->estado_id !== null => 'estado',
            default => 'geral',
        };
    }
}
