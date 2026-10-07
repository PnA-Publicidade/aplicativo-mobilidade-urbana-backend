<?php

namespace App\Http\Controllers\Motorista;

// CODEX: 92 linhas alteradas; adiciona listagem e cadastro seguro dos veículos do motorista autenticado.

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\StatusBusca;
use App\Models\Veiculo;
use App\Services\CarteiraMotoristaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MotoristaController extends Controller
{
    /**
     * Lista somente os veículos vinculados ao motorista autenticado.
     */
    public function meusVeiculos(Request $request): JsonResponse
    {
        $motorista = Motorista::where('user_id', $request->user()->id)->first();

        if ($motorista === null) {
            return response()->json(['message' => 'Cadastro de motorista não encontrado.'], 403);
        }

        $veiculos = MotoristaVeiculo::query()
            ->where('motorista_id', $motorista->id)
            ->with('veiculo')
            ->orderByDesc('id')
            ->get()
            ->pluck('veiculo')
            ->filter()
            ->values();

        return response()->json(['data' => $veiculos]);
    }

    /**
     * Ganhos do dia (todas as corridas finalizadas hoje) e o saldo da carteira
     * (ver CarteiraMotoristaService: dinheiro não entra, saques descontam).
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
                ->whereDate('corridas.tempo_final', $hoje->toDateString())
                ->sum('corrida_financeiros.valor_liquido_motorista'), 2),
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
            'categoria' => 'required|string|in:carro,moto,bicicleta',
            'uf' => 'required|string|size:2',
        ], [
            'placa.regex' => 'Informe uma placa brasileira válida.',
            'placa.unique' => 'Esta placa já está cadastrada.',
            'renavam.digits' => 'O RENAVAM deve ter 11 números.',
            'renavam.unique' => 'Este RENAVAM já está cadastrado.',
        ]);

        $motorista = Motorista::where('user_id', $request->user()->id)->first();

        if ($motorista === null) {
            return response()->json(['message' => 'Cadastro de motorista não encontrado.'], 403);
        }

        $veiculo = DB::transaction(function () use ($dados, $motorista): Veiculo {
            $veiculo = Veiculo::create([
                ...$dados,
                'status' => 'aprovado',
            ]);

            MotoristaVeiculo::create([
                'motorista_id' => $motorista->id,
                'veiculo_id' => $veiculo->id,
            ]);

            StatusBusca::where('motorista_id', $motorista->id)
                ->update(['veiculo_id' => $veiculo->id]);

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
    public function index(): LengthAwarePaginator
    {
        return Motorista::with('user')->orderBy('id', 'desc')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'user_id' => 'required|integer|exists:users,id|unique:motoristas,user_id',
            'cnh_numero' => 'required|string',
            'cnh_categoria' => 'required|string',
            'cnh_expiracao' => 'required|date',
            'ear' => 'required|boolean',
        ]);

        $motorista = Motorista::create($dados);

        return response()->json([
            'success' => true,
            'message' => 'Registro realizado com sucesso',
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
            'cnh_numero' => 'sometimes|required|string',
            'cnh_categoria' => 'sometimes|required|string',
            'cnh_expiracao' => 'sometimes|required|date',
            'ear' => 'sometimes|required|boolean',
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

        $motoristaVeiculo = MotoristaVeiculo::create($dados);

        return response()->json([
            'success' => true,
            'message' => 'Registro realizado com sucesso',
            'data' => $motoristaVeiculo,
        ], 201);
    }

    /**
     * @return LengthAwarePaginator<int, MotoristaVeiculo>
     */
    public function motoristaVeiculos(int $motoristaid): LengthAwarePaginator
    {
        return MotoristaVeiculo::with(['motorista', 'veiculo'])
            ->where('motorista_id', $motoristaid)->paginate();
    }
}
