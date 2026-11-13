<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Services\RestricaoNotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestricoesNotaController extends Controller
{
    private const POR_PAGINA = 20;

    public function __construct(private readonly RestricaoNotaService $restricoes) {}

    /**
     * Abas: `alertas` (avisos e prazos correndo), `restritos` (em vigor, inclusive os
     * simulados do modo sombra) e `historico` (terminadas ou liberadas).
     * `perfil` filtra motoristas ou passageiros.
     */
    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'aba' => 'nullable|in:alertas,restritos,historico',
            'perfil' => 'nullable|in:motorista,passageiro',
            'search' => 'nullable|string|max:100',
        ]);

        $aba = $dados['aba'] ?? 'alertas';
        $tabela = $aba === 'alertas' ? 'alertas_por_nota' : 'restricoes_por_nota';
        $nome = 'COALESCE(motoristas.name, passageiros.name)';

        $consulta = DB::table("$tabela as t")
            ->leftJoin('motoristas', fn ($j) => $j->on('motoristas.id', '=', 't.perfil_id')->where('t.perfil', 'motorista'))
            ->leftJoin('passageiros', fn ($j) => $j->on('passageiros.id', '=', 't.perfil_id')->where('t.perfil', 'passageiro'))
            ->when($dados['perfil'] ?? null, fn ($q, $perfil) => $q->where('t.perfil', $perfil));

        if ($aba === 'alertas') {
            $consulta->where('t.status', 'aberto')
                ->orderByRaw('t.prazo_ate IS NULL, t.prazo_ate')
                ->select(['t.id', 't.perfil', 't.perfil_id', DB::raw("$nome as nome"), 't.faixa', 't.nota_no_inicio', 't.avaliacoes_na_janela', 't.prazo_ate', 't.created_at']);
        } else {
            $consulta
                ->when($aba === 'restritos', fn ($q) => $q->where('t.status', 'ativa')->orderByRaw('t.fim IS NULL DESC, t.fim'))
                ->when($aba === 'historico', fn ($q) => $q->where('t.status', '!=', 'ativa')->orderByDesc('t.id'))
                ->select([
                    't.id', 't.perfil', 't.perfil_id', DB::raw("$nome as nome"), 't.tipo', 't.degrau', 't.duracao_dias', 't.inicio', 't.fim',
                    't.origem', 't.simulada', 't.status', 't.nota_no_momento', 't.motivo', 't.motivo_liberacao', 't.liberada_em',
                ]);
        }

        if ($busca = trim($dados['search'] ?? '')) {
            $consulta->whereRaw("$nome like ?", ['%'.$busca.'%']);
        }

        return response()->json($consulta->paginate(self::POR_PAGINA));
    }

    /** Penalidade manual; sem `dias` é bloqueio permanente (só passageiro tem bloqueio permanente). */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'perfil' => 'required|in:motorista,passageiro',
            'perfil_id' => 'required|integer',
            'dias' => 'nullable|integer|between:1,365',
            'motivo' => 'required|string|min:5|max:500',
        ]);

        $tabela = $dados['perfil'] === 'motorista' ? 'motoristas' : 'passageiros';

        if (! DB::table($tabela)->where('id', $dados['perfil_id'])->exists()) {
            return response()->json(['message' => 'Cadastro não encontrado.'], 404);
        }

        $dias = isset($dados['dias']) ? (int) $dados['dias'] : null;

        if ($dias === null && $dados['perfil'] === 'motorista') {
            return response()->json(['message' => 'Informe por quantos dias o motorista fica restrito.'], 422);
        }

        $id = $this->restricoes->aplicarManual($dados['perfil'], (int) $dados['perfil_id'], $dias, $dados['motivo'], $this->gestorId());

        return response()->json(['id' => $id], 201);
    }

    public function liberar(Request $request, int $restricao): JsonResponse
    {
        $dados = $request->validate(['motivo' => 'required|string|min:5|max:500']);

        if (! $this->restricoes->liberar($restricao, $dados['motivo'], $this->gestorId())) {
            return response()->json(['message' => 'Esta penalidade não está mais em vigor.'], 409);
        }

        return response()->json(null, 204);
    }

    private function gestorId(): ?int
    {
        $id = auth('gestores')->id();

        return $id === null ? null : (int) $id;
    }
}
