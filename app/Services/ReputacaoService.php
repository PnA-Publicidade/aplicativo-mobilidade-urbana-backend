<?php

namespace App\Services;

use App\Enums\ElogioMotorista;
use App\Enums\TipoDocumentoMotorista;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reputação de motoristas e passageiros.
 *
 * - Toda conta começa com a nota base (5,0), que vale até chegarem as
 *   primeiras avaliações reais; depois a nota é a média das reais.
 * - Avaliação real é a que a pessoa deu de fato: a nota 5 que o passageiro
 *   recebe sozinho quando não avalia (`automatica`) não entra na média.
 * - Quem tem menos corridas finalizadas que o limite configurado é "novo" e
 *   o app mostra o selo Novo usuário.
 */
class ReputacaoService
{
    /** Notas mínimas para um comentário aparecer como nota de agradecimento. */
    private const NOTA_MINIMA_DO_AGRADECIMENTO = 4;

    public static function notaBase(): float
    {
        return (float) config('avaliacoes.nota_base', 5.0);
    }

    public static function corridasParaDeixarDeSerNovo(): int
    {
        return (int) config('avaliacoes.corridas_para_deixar_de_ser_novo', 20);
    }

    public const FAIXA_EXCELENTE = 'excelente';

    public const FAIXA_ATENCAO = 'atencao';

    public const FAIXA_ALTO_RISCO = 'alto_risco';

    /**
     * Faixa da nota, com os limites definidos na gestão (padrão: Excelente
     * acima de 4,85; Atenção de 4,51 a 4,85; Alto risco abaixo de 4,51). A
     * nota vale com duas casas e os limites pertencem à faixa Atenção.
     *
     * @return array{chave: string, titulo: string}
     */
    public function classificar(float $nota): array
    {
        $configuracoes = app(ConfiguracaoPlataformaService::class);
        $centavos = (int) round($nota * 100);

        if ($centavos > (int) round($configuracoes->faixaExcelenteAcima() * 100)) {
            return ['chave' => self::FAIXA_EXCELENTE, 'titulo' => 'Excelente'];
        }

        if ($centavos < (int) round($configuracoes->faixaAltoRiscoAbaixo() * 100)) {
            return ['chave' => self::FAIXA_ALTO_RISCO, 'titulo' => 'Alto risco'];
        }

        return ['chave' => self::FAIXA_ATENCAO, 'titulo' => 'Atenção'];
    }

    /**
     * Os limites das faixas, para o app explicar a classificação.
     *
     * @return array{excelente_acima: float, alto_risco_abaixo: float}
     */
    public function limitesDasFaixas(): array
    {
        $configuracoes = app(ConfiguracaoPlataformaService::class);

        return [
            'excelente_acima' => $configuracoes->faixaExcelenteAcima(),
            'alto_risco_abaixo' => $configuracoes->faixaAltoRiscoAbaixo(),
        ];
    }

    /**
     * @return array{nota: float, avaliacoes: int, nota_base: bool, corridas: int, novo: bool}
     */
    public function doMotorista(int $motoristaId): array
    {
        return $this->dosMotoristas([$motoristaId])[$motoristaId];
    }

    /**
     * @return array{nota: float, avaliacoes: int, nota_base: bool, corridas: int, novo: bool}
     */
    public function doPassageiro(int $passageiroId): array
    {
        return $this->dosPassageiros([$passageiroId])[$passageiroId];
    }

    /**
     * @param  iterable<int|string|null>  $ids
     * @return array<int, array{nota: float, avaliacoes: int, nota_base: bool, corridas: int, novo: bool}>
     */
    public function dosMotoristas(iterable $ids): array
    {
        return $this->emLote('motorista_id', 'passageiro', $ids);
    }

    /**
     * @param  iterable<int|string|null>  $ids
     * @return array<int, array{nota: float, avaliacoes: int, nota_base: bool, corridas: int, novo: bool}>
     */
    public function dosPassageiros(iterable $ids): array
    {
        return $this->emLote('passageiro_id', 'motorista', $ids);
    }

    /** Quantas avaliações recentes entram na média (configurável na gestão). */
    public function janelaDaMedia(): int
    {
        return app(ConfiguracaoPlataformaService::class)->avaliacoesMediaUltimas();
    }

    /**
     * Quantas avaliações reais o motorista recebeu em cada nota, de 5 a 1, nas
     * mesmas últimas avaliações que formam a média.
     *
     * @return array<int, int> nota => quantidade
     */
    public function distribuicaoDoMotorista(int $motoristaId): array
    {
        $ultimas = $this->avaliacoesReaisDoMotorista($motoristaId)
            ->orderByDesc('avaliacoes_corridas.id')
            ->limit($this->janelaDaMedia())
            ->select('avaliacoes_corridas.nota');

        $contagem = DB::query()->fromSub($ultimas, 'ultimas')
            ->selectRaw('nota, COUNT(*) as total')
            ->groupBy('nota')
            ->pluck('total', 'nota');

        $distribuicao = [];

        foreach ([5, 4, 3, 2, 1] as $nota) {
            $distribuicao[$nota] = (int) ($contagem[$nota] ?? 0);
        }

        return $distribuicao;
    }

    /**
     * Quantas vezes cada elogio foi marcado nas avaliações reais.
     *
     * @return list<array{chave: string, titulo: string, descricao: string, quantidade: int}>
     */
    public function elogiosDoMotorista(int $motoristaId): array
    {
        $contagem = [];

        foreach ($this->avaliacoesReaisDoMotorista($motoristaId)->whereNotNull('avaliacoes_corridas.elogios')->pluck('avaliacoes_corridas.elogios') as $elogios) {
            foreach ((array) json_decode((string) $elogios, true) as $chave) {
                $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
            }
        }

        return array_map(fn (ElogioMotorista $elogio): array => [
            'chave' => $elogio->value,
            'titulo' => $elogio->titulo(),
            'descricao' => $elogio->descricao(),
            'quantidade' => (int) ($contagem[$elogio->value] ?? 0),
        ], ElogioMotorista::cases());
    }

    /**
     * Comentários de quem deu nota 4 ou 5, do mais novo para o mais antigo. Só o
     * primeiro nome de quem avaliou aparece.
     *
     * @return LengthAwarePaginator<int, mixed>
     */
    public function notasDeAgradecimento(int $motoristaId, int $porPagina = 10): LengthAwarePaginator
    {
        $pagina = $this->avaliacoesReaisDoMotorista($motoristaId)
            ->join('passageiros', 'passageiros.id', '=', 'corridas.passageiro_id')
            ->where('avaliacoes_corridas.nota', '>=', self::NOTA_MINIMA_DO_AGRADECIMENTO)
            ->whereNotNull('avaliacoes_corridas.comentario')
            ->where('avaliacoes_corridas.comentario', '!=', '')
            ->orderByDesc('avaliacoes_corridas.created_at')
            ->orderByDesc('avaliacoes_corridas.id')
            ->select(['avaliacoes_corridas.id', 'avaliacoes_corridas.nota', 'avaliacoes_corridas.comentario', 'avaliacoes_corridas.elogios', 'avaliacoes_corridas.created_at', 'passageiros.name as autor'])
            ->paginate($porPagina);

        $titulos = collect(ElogioMotorista::cases())->mapWithKeys(fn (ElogioMotorista $e) => [$e->value => $e->titulo()]);

        $pagina->setCollection($pagina->getCollection()->map(fn ($linha): array => [
            'id' => (int) $linha->id,
            'autor' => $this->primeiroNome((string) $linha->autor),
            'nota' => (int) $linha->nota,
            'comentario' => (string) $linha->comentario,
            'elogios' => collect((array) json_decode((string) $linha->elogios, true))
                ->map(fn ($chave) => $titulos[$chave] ?? null)->filter()->values()->all(),
            'quando' => $linha->created_at === null ? null : Carbon::parse($linha->created_at)->toIso8601String(),
        ]));

        return $pagina;
    }

    /**
     * Selos que o motorista já conquistou; de cada tipo vale só o maior nível.
     *
     * @return list<array{chave: string, titulo: string, subtitulo: string, icone: string}>
     */
    public function selosDoMotorista(int $corridas, int $cincoEstrelas, int $mesesNaPlataforma): array
    {
        $selos = [];

        if (($n = $this->maiorNivel($corridas, [100, 500, 1000, 5000, 10000])) !== null) {
            $selos[] = ['chave' => "corridas_$n", 'titulo' => "$n corridas finalizadas", 'subtitulo' => 'Experiência nas ruas', 'icone' => 'car'];
        }

        if (($n = $this->maiorNivel($cincoEstrelas, [10, 50, 100, 500, 1000])) !== null) {
            $selos[] = ['chave' => "cinco_estrelas_$n", 'titulo' => "5 estrelas $n+ vezes", 'subtitulo' => 'Preferido dos passageiros', 'icone' => 'ribbon'];
        }

        if (($n = $this->maiorNivel($mesesNaPlataforma, [6, 12, 24, 36])) !== null) {
            $titulo = $n < 12 ? "$n meses na plataforma" : ($n === 12 ? '1 ano na plataforma' : ($n / 12).' anos na plataforma');
            $selos[] = ['chave' => "tempo_$n", 'titulo' => $titulo, 'subtitulo' => 'Parceiro de confiança', 'icone' => 'medal'];
        }

        return $selos;
    }

    /**
     * O que a plataforma já conferiu do motorista, com base nos documentos aprovados.
     *
     * @return list<array{chave: string, titulo: string, descricao: string, icone: string, ok: bool}>
     */
    public function verificacoesDoMotorista(Motorista $motorista): array
    {
        $documentos = app(DocumentosMotoristaService::class);
        $ultimos = $documentos->ultimos($motorista);
        $aprovado = fn (TipoDocumentoMotorista $tipo): bool => $ultimos->contains(
            fn (MotoristaDocumento $d) => $d->tipo_documento === $tipo && $d->status === 'aprovado' && ! $d->vencido
        );
        $veiculoAprovado = $documentos->veiculos($motorista)->contains(fn ($veiculo) => $veiculo->status === 'aprovado');

        return [
            [
                'chave' => 'antecedentes', 'titulo' => 'Verificação de antecedentes', 'icone' => 'shield-checkmark-outline',
                'descricao' => 'A certidão de antecedentes criminais do motorista foi conferida pela plataforma',
                'ok' => $aprovado(TipoDocumentoMotorista::NADA_CONSTA),
            ],
            [
                'chave' => 'cnh', 'titulo' => 'CNH verificada', 'icone' => 'card-outline',
                'descricao' => 'Todo motorista deve enviar foto de sua carteira de habilitação para se cadastrar na plataforma',
                'ok' => $aprovado(TipoDocumentoMotorista::CNH),
            ],
            [
                'chave' => 'veiculo', 'titulo' => 'CRLV verificado', 'icone' => 'car-outline',
                'descricao' => 'O motorista enviou os documentos do veículo e eles foram aprovados',
                'ok' => $veiculoAprovado,
            ],
            [
                'chave' => 'foto', 'titulo' => 'Foto de perfil', 'icone' => 'image-outline',
                'descricao' => 'Todo motorista deve ter uma foto de perfil atualizada',
                'ok' => filled($motorista->foto),
            ],
        ];
    }

    /** Quantos meses se passaram desde que o motorista foi liberado (ou se cadastrou). */
    public function mesesNaPlataforma(Motorista $motorista, ?CarbonInterface $agora = null): int
    {
        $inicio = $motorista->liberado_em ?? $motorista->created_at;

        return $inicio === null ? 0 : (int) Carbon::parse($inicio)->diffInMonths($agora ?? now());
    }

    /** Todas as avaliações de 5 estrelas já recebidas (base do selo), não só as recentes. */
    public function totalDeCincoEstrelas(int $motoristaId): int
    {
        return $this->avaliacoesReaisDoMotorista($motoristaId)->where('avaliacoes_corridas.nota', 5)->count();
    }

    private function avaliacoesReaisDoMotorista(int $motoristaId): Builder
    {
        // tipo_usuario guarda quem deu a nota: "passageiro" são as notas ao motorista
        return DB::table('avaliacoes_corridas')
            ->join('corridas', 'corridas.id', '=', 'avaliacoes_corridas.corrida_id')
            ->where('avaliacoes_corridas.tipo_usuario', 'passageiro')
            ->where('avaliacoes_corridas.automatica', false)
            ->where('corridas.motorista_id', $motoristaId);
    }

    /**
     * @param  iterable<int|string|null>  $ids
     * @return array<int, array{nota: float, avaliacoes: int, nota_base: bool, corridas: int, novo: bool}>
     */
    private function emLote(string $colunaDaCorrida, string $quemAvaliou, iterable $ids): array
    {
        $ids = collect($ids)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        // a média vale só para as últimas avaliações reais de cada pessoa (janela
        // configurável): numera da mais nova para a mais antiga e corta no limite
        $particao = $colunaDaCorrida === 'motorista_id' ? 'corridas.motorista_id' : 'corridas.passageiro_id';
        $recentes = DB::table('avaliacoes_corridas')
            ->join('corridas', 'corridas.id', '=', 'avaliacoes_corridas.corrida_id')
            ->where('avaliacoes_corridas.tipo_usuario', $quemAvaliou)
            ->where('avaliacoes_corridas.automatica', false)
            ->whereIn($particao, $ids)
            ->select("$particao as dono", 'avaliacoes_corridas.nota')
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY $particao ORDER BY avaliacoes_corridas.id DESC) as posicao");

        // o limite da janela é lido na própria consulta, sem uma ida a mais ao banco
        $medias = DB::query()->fromSub($recentes, 'recentes')
            ->whereRaw(
                'posicao <= COALESCE((SELECT CAST(valor AS UNSIGNED) FROM configuracoes_plataforma WHERE chave = ? LIMIT 1), ?)',
                [ConfiguracaoPlataformaService::AVALIACOES_MEDIA_ULTIMAS, ConfiguracaoPlataformaService::AVALIACOES_MEDIA_ULTIMAS_PADRAO]
            )
            ->select('dono')
            ->selectRaw('AVG(nota) as media, COUNT(*) as total')
            ->groupBy('dono')
            ->get()
            ->keyBy('dono');

        $finalizadas = DB::table('corridas')
            ->whereIn($colunaDaCorrida, $ids)
            ->where('status_corrida', 'finalizada')
            ->select("$colunaDaCorrida as dono")
            ->selectRaw('COUNT(*) as total')
            ->groupBy($colunaDaCorrida)
            ->pluck('total', 'dono');

        $limite = self::corridasParaDeixarDeSerNovo();
        $resultado = [];

        foreach ($ids as $id) {
            $reais = (int) ($medias[$id]->total ?? 0);
            $corridas = (int) ($finalizadas[$id] ?? 0);

            $resultado[$id] = [
                'nota' => $reais > 0 ? round((float) $medias[$id]->media, 2) : self::notaBase(),
                'avaliacoes' => $reais,
                'nota_base' => $reais === 0,
                'corridas' => $corridas,
                'novo' => $corridas < $limite,
            ];
        }

        return $resultado;
    }

    /** @param  list<int>  $niveis */
    private function maiorNivel(int $valor, array $niveis): ?int
    {
        $alcancados = array_filter($niveis, fn (int $nivel) => $valor >= $nivel);

        return $alcancados === [] ? null : max($alcancados);
    }

    private function primeiroNome(string $nome): string
    {
        return preg_split('/\s+/u', trim($nome), 2)[0] ?? '';
    }
}
