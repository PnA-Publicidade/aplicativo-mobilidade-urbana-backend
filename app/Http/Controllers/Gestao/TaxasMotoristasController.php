<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Services\ConfiguracaoPlataformaService;
use App\Services\TaxasMotoristaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TaxasMotoristasController extends Controller
{
    private const POR_PAGINA = 20;

    public function __construct(
        private readonly TaxasMotoristaService $taxas,
        private readonly ConfiguracaoPlataformaService $configuracoes,
    ) {}

    /** Desempenho de cada motorista: as duas taxas e as contagens que as formam. */
    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'search' => 'nullable|string|max:100',
            'abaixo_minimo' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
        ]);

        $minima = $this->configuracoes->taxaFinalizacaoMinima();
        $consulta = Motorista::with('user')->orderBy('nome')->orderBy('id');

        if ($busca = trim($dados['search'] ?? '')) {
            $cpf = preg_replace('/\D/', '', $busca);
            $consulta->where(fn ($q) => $q->where('nome', 'like', '%'.$busca.'%')
                ->orWhere('cpf', 'like', '%'.($cpf !== '' ? $cpf : $busca).'%')
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$busca.'%')));
        }

        if ($request->boolean('abaixo_minimo')) {
            return response()->json($this->abaixoDoMinimo($consulta, $minima, (int) ($dados['page'] ?? 1)));
        }

        $pagina = $consulta->paginate(self::POR_PAGINA);
        $pagina->setCollection($pagina->getCollection()->map(fn (Motorista $m) => $this->linha($m, $minima)));

        return response()->json($pagina);
    }

    /** Eventos que formam as taxas de um motorista, inclusive os desconsiderados e o motivo. */
    public function eventos(Request $request, int $motorista): JsonResponse
    {
        $dados = $request->validate([
            'taxa' => 'nullable|in:aceitacao,finalizacao',
            'somente_desconsiderados' => 'nullable|boolean',
        ]);

        $consulta = DB::table('motorista_taxa_eventos')
            ->join('corridas', 'corridas.id', '=', 'motorista_taxa_eventos.corrida_id')
            ->where('motorista_taxa_eventos.motorista_id', $motorista)
            ->when($dados['taxa'] ?? null, fn ($q, $taxa) => $q->where('motorista_taxa_eventos.taxa', $taxa))
            ->when($request->boolean('somente_desconsiderados'), fn ($q) => $q->whereNotNull('motorista_taxa_eventos.desconsiderado_motivo'))
            ->orderByDesc('motorista_taxa_eventos.ocorrido_em')
            ->orderByDesc('motorista_taxa_eventos.id')
            ->select([
                'motorista_taxa_eventos.id', 'motorista_taxa_eventos.corrida_id', 'corridas.codigo_corrida',
                'motorista_taxa_eventos.taxa', 'motorista_taxa_eventos.resultado', 'motorista_taxa_eventos.motivo',
                'motorista_taxa_eventos.desconsiderado_motivo', 'motorista_taxa_eventos.chamada_em',
                'motorista_taxa_eventos.ocorrido_em',
            ]);

        return response()->json($consulta->paginate(self::POR_PAGINA));
    }

    /**
     * Só quem tem cancelamento ou desistência nos últimos 30 dias pode estar
     * abaixo da mínima; a lista parte desses motoristas.
     *
     * @param  Builder<Motorista>  $consulta
     * @return LengthAwarePaginator<int, mixed>
     */
    private function abaixoDoMinimo(Builder $consulta, int $minima, int $pagina): LengthAwarePaginator
    {
        $candidatos = DB::table('motorista_taxa_eventos')
            ->where('taxa', TaxasMotoristaService::FINALIZACAO)
            ->where('resultado', 'negativo')
            ->whereNull('desconsiderado_motivo')
            ->where('ocorrido_em', '>=', now()->subDays(TaxasMotoristaService::JANELA_FINALIZACAO_DIAS))
            ->distinct()
            ->pluck('motorista_id');

        $linhas = $consulta->whereIn('id', $candidatos)->get()
            ->map(fn (Motorista $m) => $this->linha($m, $minima))
            ->filter(fn (array $linha) => $linha['abaixo_minimo'])
            ->values();

        return new LengthAwarePaginator(
            $linhas->forPage($pagina, self::POR_PAGINA)->values(),
            $linhas->count(),
            self::POR_PAGINA,
            $pagina,
            ['path' => request()->url()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function linha(Motorista $motorista, int $minima): array
    {
        $taxas = $this->taxas->taxas($motorista->id);

        return [
            'id' => $motorista->id,
            'nome' => $motorista->nome ?? $motorista->user?->name,
            'foto' => $motorista->foto,
            ...$taxas,
            'taxa_finalizacao_minima' => $minima,
            'abaixo_minimo' => $taxas['taxa_finalizacao'] < $minima,
        ];
    }
}
