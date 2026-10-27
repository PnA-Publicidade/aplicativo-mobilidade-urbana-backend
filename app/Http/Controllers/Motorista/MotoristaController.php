<?php

namespace App\Http\Controllers\Motorista;

// CODEX: 92 linhas alteradas; adiciona listagem e cadastro seguro dos veículos do motorista autenticado.

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\ProdutosCorrida;
use App\Models\Veiculo;
use App\Services\AtualizarSituacaoMotoristaService;
use App\Services\CadastrarPerfilUsuarioService;
use App\Services\CarteiraMotoristaService;
use App\Services\CompartilhamentoVeiculoService;
use App\Services\DocumentosMotoristaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MotoristaController extends Controller
{
    /**
     * Lista os veículos vinculados; a consulta de Meus veículos pode incluir os em análise.
     */
    public function meusVeiculos(Request $request): JsonResponse
    {
        $motorista = Motorista::where('user_id', $request->user()->id)->first();

        if ($motorista === null) {
            return response()->json(['message' => 'Cadastro de motorista não encontrado.'], 403);
        }

        $veiculos = MotoristaVeiculo::query()
            ->where('motorista_id', $motorista->id)
            ->whereHas('veiculo', fn ($query) => $request->boolean('incluir_em_analise')
                ? $query->visiveis() : $query->liberados())
            ->with('veiculo')
            ->orderByDesc('id')
            ->get()
            ->pluck('veiculo')
            ->filter()
            ->values();

        $liberados = Veiculo::liberados()->where('status', 'aprovado')
            ->whereIn('id', $veiculos->pluck('id'))->pluck('id')->all();

        return response()->json([
            'data' => $veiculos->map(fn ($veiculo) => [
                ...$veiculo->toArray(),
                'pode_selecionar' => in_array($veiculo->id, $liberados, true),
                'categorias_servico' => in_array($veiculo->id, $liberados, true)
                    ? ProdutosCorrida::atendidosPor($veiculo)->orderBy('ordem')->orderBy('id')->get(['id', 'codigo', 'nome'])
                    : [],
            ]),
            ...app(CompartilhamentoVeiculoService::class)->contextoSelecao($motorista),
        ]);
    }

    public function selecionarMeuVeiculo(Request $request): JsonResponse
    {
        $dados = $request->validate(['veiculo_id' => 'required|integer|exists:veiculos,id']);
        $motorista = Motorista::where('user_id', $request->user()->id)->first();
        abort_if($motorista === null, 403, 'Cadastro de motorista não encontrado.');
        $service = app(CompartilhamentoVeiculoService::class);
        try {
            $veiculo = $service->selecionarVeiculo($motorista, (int) $dados['veiculo_id']);
        } catch (\RuntimeException $erro) {
            if (! in_array($erro->getCode(), [403, 409, 422], true)) {
                throw $erro;
            }

            return response()->json(['message' => $erro->getMessage()], $erro->getCode());
        }

        return response()->json([
            'message' => 'Veículo em uso atualizado.', 'data' => $veiculo,
            ...$service->contextoSelecao($motorista),
        ]);
    }

    public function removerMeuVeiculo(Request $request, int $veiculo): JsonResponse
    {
        $motorista = Motorista::where('user_id', $request->user()->id)->first();
        abort_if($motorista === null, 403, 'Cadastro de motorista não encontrado.');
        $service = app(CompartilhamentoVeiculoService::class);
        try {
            $service->desvincular($motorista, $veiculo);
        } catch (\RuntimeException $erro) {
            if (! in_array($erro->getCode(), [403, 404, 409, 422], true)) {
                throw $erro;
            }

            return response()->json(['message' => $erro->getMessage()], $erro->getCode());
        }

        return response()->json([
            'message' => 'Veículo removido.',
            ...$service->contextoSelecao($motorista),
        ]);
    }

    /**
     * Ganhos do dia (corridas finalizadas hoje com pagamento confirmado, a
     * mesma regra do GanhosMotoristaService da gestão, mais as taxas de
     * cancelamento pagas no dia do cancelamento) e o saldo da carteira
     * (ver CarteiraMotoristaService: dinheiro não entra, saques descontam).
     * corridas_hoje conta todas as finalizadas do dia.
     */
    public function ganhos(Request $request, CarteiraMotoristaService $carteira): JsonResponse
    {
        $motorista = Motorista::where('user_id', $request->user()->id)->first();

        if ($motorista === null) {
            return response()->json(['message' => 'Cadastro de motorista não encontrado.'], 403);
        }

        $finalizadas = DB::table('corridas')
            ->join('corrida_financeiros', 'corrida_financeiros.corrida_id', '=', 'corridas.id')
            ->where('corridas.motorista_id', $motorista->id)
            ->where('corridas.status_corrida', 'finalizada');

        $hoje = now();

        return response()->json([
            'data' => $hoje->translatedFormat('d/m'),
            'ganhos_do_dia' => round((float) (clone $finalizadas)
                ->where('corridas.status_pagamento', 'pago')
                ->whereDate('corridas.tempo_final', $hoje->toDateString())
                ->sum('corrida_financeiros.valor_liquido_motorista')
                + $carteira->taxasDeCancelamentoNoDia($motorista, $hoje->toDateString()), 2),
            'saldo' => $carteira->saldo($motorista),
            'corridas_hoje' => (clone $finalizadas)
                ->whereDate('corridas.tempo_final', $hoje->toDateString())
                ->count(),
        ]);
    }

    /**
     * Números do menu lateral, como na 99: só pesa o que dependeu do
     * motorista. A aceitação conta as chamadas que ele aceitou ou recusou
     * (deixar tocar até o fim é recusar); a finalização, as corridas que ele
     * terminou ou cancelou. Cancelamento do passageiro não conta contra ele.
     */
    public function estatisticas(Request $request): JsonResponse
    {
        $motorista = Motorista::where('user_id', $request->user()->id)->first();

        if ($motorista === null) {
            return response()->json(['message' => 'Cadastro de motorista não encontrado.'], 403);
        }

        $corridas = DB::table('corridas')->where('motorista_id', $motorista->id);
        $aceitas = (clone $corridas)->whereNotNull('tempo_aceite')->count();
        $finalizadas = (clone $corridas)->where('status_corrida', 'finalizada')->count();
        $canceladasPeloMotorista = (clone $corridas)
            ->where('status_corrida', 'cancelada')
            ->where('cancelado_por', 'motorista')
            ->count();
        $encerradas = $finalizadas + $canceladasPeloMotorista;

        $ofertadas = DB::table('ofertas_motorista')
            ->join('corridas', 'corridas.id', '=', 'ofertas_motorista.corrida_id')
            ->where('ofertas_motorista.motorista_id', $motorista->id);
        $aceitasDasOfertas = (clone $ofertadas)
            ->where('corridas.motorista_id', $motorista->id)
            ->whereNotNull('corridas.tempo_aceite')
            ->count();
        $recusadas = (clone $ofertadas)
            ->whereNotNull('ofertas_motorista.recusada_em')
            ->where(fn ($consulta) => $consulta
                ->whereNull('corridas.motorista_id')
                ->orWhere('corridas.motorista_id', '!=', $motorista->id))
            ->count();
        $respondidas = $aceitasDasOfertas + $recusadas;

        return response()->json([
            'corridas_aceitas' => $aceitas,
            'corridas_finalizadas' => $finalizadas,
            'taxa_finalizacao' => $encerradas > 0 ? round($finalizadas / $encerradas * 100) : null,
            'taxa_aceitacao' => $respondidas > 0 ? round($aceitasDasOfertas / $respondidas * 100) : null,
        ]);
    }

    /**
     * Cadastra e vincula um veículo à própria conta, sem confiar em IDs
     * de motorista enviados pelo aplicativo.
     */
    public function cadastrarMeuVeiculo(Request $request): JsonResponse
    {
        $request->merge([
            'marca' => trim((string) $request->input('marca')),
            'modelo' => trim((string) $request->input('modelo')),
            'cor' => trim((string) $request->input('cor')),
            'placa' => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('placa'))),
            'renavam' => preg_replace('/\D/', '', (string) $request->input('renavam')),
            'cpf_cnpj_proprietario' => preg_replace('/\D/', '', (string) $request->input('cpf_cnpj_proprietario')),
            'categoria' => strtolower(trim((string) $request->input('categoria'))),
            'uf' => strtoupper(trim((string) $request->input('uf'))),
        ]);

        $anoMaximo = now()->year + 1;
        $dados = $request->validate([
            'marca' => 'required|string|max:60',
            'modelo' => 'required|string|max:80',
            'ano_fabricacao' => "required|integer|between:1900,{$anoMaximo}",
            'ano_modelo' => "required|integer|between:1900,{$anoMaximo}",
            'cor' => 'required|string|max:40',
            'placa' => ['required', 'string', 'regex:/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', Rule::unique('veiculos', 'placa')],
            'renavam' => ['required', 'digits:11', Rule::unique('veiculos', 'renavam')],
            'chassi' => ['nullable', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/'],
            'cpf_cnpj_proprietario' => ['required', 'string', 'regex:/^(?:[0-9]{11}|[0-9]{14})$/'],
            'nome_proprietario' => 'nullable|string|max:255',
            'categoria' => 'required|string|in:carro,moto',
            // não existe moto elétrica nas categorias
            'eletrico' => 'sometimes|boolean|declined_if:categoria,moto',
            'taxi' => 'sometimes|boolean',
            'uf' => 'required|string|size:2',
        ], [
            'categoria.in' => 'O veículo deve ser carro ou moto.',
            'eletrico.declined_if' => 'Só carros podem ser cadastrados como elétricos.',
            'placa.regex' => 'Informe uma placa brasileira válida.',
            'placa.unique' => 'Esta placa já está cadastrada.',
            'renavam.digits' => 'O RENAVAM deve ter 11 números.',
            'renavam.unique' => 'Este RENAVAM já está cadastrado.',
            'cpf_cnpj_proprietario.required' => 'Informe o CPF ou CNPJ do proprietário, como está no documento do veículo.',
            'cpf_cnpj_proprietario.regex' => 'Informe um CPF (11 números) ou CNPJ (14 números).',
        ]);

        $motorista = Motorista::where('user_id', $request->user()->id)->first();

        if ($motorista === null) {
            return response()->json(['message' => 'Cadastro de motorista não encontrado.'], 403);
        }

        $veiculo = DB::transaction(function () use ($dados, $motorista): Veiculo {
            // elétrico e táxi são só pedidos: valem depois que a gestão confere
            $veiculo = Veiculo::create([
                ...array_diff_key($dados, array_flip(['eletrico', 'taxi'])),
                'eletrico' => false,
                'taxi' => false,
                'eletrico_solicitado' => (bool) ($dados['eletrico'] ?? false),
                'taxi_solicitado' => (bool) ($dados['taxi'] ?? false),
                'status' => 'aprovado',
            ]);

            app(CompartilhamentoVeiculoService::class)->vincular($motorista, $veiculo);

            // O veículo usado na sessão atual permanece selecionado.

            app(AtualizarSituacaoMotoristaService::class)->executar($motorista);

            return $veiculo;
        });

        return response()->json([
            'message' => 'Veículo adicionado com sucesso.',
            'data' => $veiculo,
        ], 201);
    }

    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, Motorista>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        $dados = $request->validate([
            'search' => 'nullable|string|max:255',
            'rowsPerPage' => 'sometimes|integer|between:0,50',
        ]);

        $busca = trim($dados['search'] ?? '');
        $query = Motorista::with('user');

        if ($busca !== '') {
            $termo = '%'.$busca.'%';
            $cpf = preg_match('/^[\d\s.-]+$/', $busca) ? preg_replace('/\D/', '', $busca) : '';
            $termoCpf = '%'.($cpf !== '' ? $cpf : $busca).'%';

            $query->where(function (Builder $consulta) use ($termo, $termoCpf): void {
                $consulta->where('nome', 'like', $termo)
                    ->orWhere('cpf', 'like', $termoCpf)
                    ->orWhereHas('user', function (Builder $usuario) use ($termo, $termoCpf): void {
                        $usuario->where('name', 'like', $termo)
                            ->orWhere('cpf', 'like', $termoCpf);
                    });
            });
        }

        return $query->orderBy('id', 'desc')->paginate(($dados['rowsPerPage'] ?? 15) ?: 15);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, CadastrarPerfilUsuarioService $cadastro): JsonResponse
    {
        $motorista = $cadastro->executar($request, 'motorista');

        return response()->json([
            'success' => true,
            'message' => 'Motorista cadastrado com sucesso.',
            'data' => $motorista,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $motoristaid): Motorista
    {
        return Motorista::with('user')->findOrFail($motoristaid);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $motoristaid): JsonResponse
    {
        $motorista = Motorista::findOrFail($motoristaid);

        $dados = $request->validate([
            'numero_registro' => 'sometimes|required|string|max:20',
            'cnh_categoria' => 'sometimes|required|string',
            'cnh_expiracao' => 'sometimes|required|date',
            'ear' => 'sometimes|required|boolean',
            'observacao' => 'sometimes|nullable|string|max:5000',
        ]);

        $motorista->update($dados);

        return response()->json([
            'success' => true,
            'message' => 'Registro atualizado com sucesso',
            'data' => $motorista,
        ], 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Motorista $motorista): void
    {
        //
    }

    public function adicionarVeiculoAoMotorista(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'motorista_id' => 'required|integer|exists:motoristas,id',
            'veiculo_id' => 'required|integer|exists:veiculos,id',
        ]);

        $motoristaVeiculo = DB::transaction(function () use ($dados): MotoristaVeiculo {
            $motorista = Motorista::whereHas('user')->lockForUpdate()->findOrFail($dados['motorista_id']);
            Veiculo::lockForUpdate()->findOrFail($dados['veiculo_id']);
            $vinculo = app(CompartilhamentoVeiculoService::class)->vincular($motorista, Veiculo::findOrFail($dados['veiculo_id']));
            app(AtualizarSituacaoMotoristaService::class)->executar($motorista);

            return $vinculo;
        });

        return response()->json([
            'success' => true,
            'message' => 'Registro realizado com sucesso',
            'data' => $motoristaVeiculo,
        ], 201);
    }

    /**
     * @return LengthAwarePaginator<int, MotoristaVeiculo>
     */
    public function motoristaVeiculos(int $motoristaid): JsonResponse
    {
        $motorista = Motorista::findOrFail($motoristaid);
        $veiculos = MotoristaVeiculo::with(['motorista', 'veiculo.ultimoCrlv'])
            ->where('motorista_id', $motoristaid)
            ->whereHas('veiculo')->paginate();

        return response()->json([
            ...$veiculos->toArray(),
            'veiculos' => app(DocumentosMotoristaService::class)->veiculos($motorista),
            'possui_veiculo_cadastrado' => MotoristaVeiculo::where('motorista_id', $motoristaid)->exists(),
        ]);
    }
}
