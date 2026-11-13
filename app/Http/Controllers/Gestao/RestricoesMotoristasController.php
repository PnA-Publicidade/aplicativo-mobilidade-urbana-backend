<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Services\RestricaoTaxaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestricoesMotoristasController extends Controller
{
    private const POR_PAGINA = 20;

    public function __construct(private readonly RestricaoTaxaService $restricoes) {}

    /**
     * Abas: `alertas` (prazo correndo), `restritos` (em vigor, inclusive os
     * simulados do modo sombra) e `historico` (terminadas ou liberadas).
     */
    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'aba' => 'nullable|in:alertas,restritos,historico',
            'search' => 'nullable|string|max:100',
        ]);

        $aba = $dados['aba'] ?? 'alertas';
        $nome = 'COALESCE(motoristas.nome, users.name)';

        if ($aba === 'alertas') {
            $consulta = DB::table('motorista_alertas_taxa as a')
                ->join('motoristas', 'motoristas.id', '=', 'a.motorista_id')
                ->leftJoin('users', 'users.id', '=', 'motoristas.user_id')
                ->where('a.status', 'aberto')
                ->orderBy('a.prazo_ate')
                ->select(['a.id', 'a.motorista_id', DB::raw("$nome as nome"), 'a.taxa_no_inicio', 'a.corridas_na_janela', 'a.prazo_ate', 'a.created_at']);
        } else {
            $consulta = DB::table('motorista_restricoes as r')
                ->join('motoristas', 'motoristas.id', '=', 'r.motorista_id')
                ->leftJoin('users', 'users.id', '=', 'motoristas.user_id')
                ->when($aba === 'restritos', fn ($q) => $q->where('r.status', 'ativa')->orderBy('r.fim'))
                ->when($aba === 'historico', fn ($q) => $q->where('r.status', '!=', 'ativa')->orderByDesc('r.fim'))
                ->select([
                    'r.id', 'r.motorista_id', DB::raw("$nome as nome"), 'r.degrau', 'r.duracao_dias', 'r.inicio', 'r.fim',
                    'r.origem', 'r.simulada', 'r.status', 'r.taxa_no_momento', 'r.motivo', 'r.motivo_liberacao', 'r.liberada_em',
                ]);
        }

        if ($busca = trim($dados['search'] ?? '')) {
            $consulta->whereRaw("$nome like ?", ['%'.$busca.'%']);
        }

        return response()->json($consulta->paginate(self::POR_PAGINA));
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'motorista_id' => 'required|integer|exists:motoristas,id',
            'dias' => 'required|integer|between:1,365',
            'motivo' => 'required|string|min:5|max:500',
        ]);

        $id = $this->restricoes->aplicarManual((int) $dados['motorista_id'], (int) $dados['dias'], $dados['motivo'], $this->gestorId());

        return response()->json(['id' => $id], 201);
    }

    public function liberar(Request $request, int $restricao): JsonResponse
    {
        $dados = $request->validate(['motivo' => 'required|string|min:5|max:500']);

        if (! $this->restricoes->liberar($restricao, $dados['motivo'], $this->gestorId())) {
            return response()->json(['message' => 'Esta restrição não está mais em vigor.'], 409);
        }

        return response()->json(null, 204);
    }

    private function gestorId(): ?int
    {
        $id = auth('gestores')->id();

        return $id === null ? null : (int) $id;
    }
}
