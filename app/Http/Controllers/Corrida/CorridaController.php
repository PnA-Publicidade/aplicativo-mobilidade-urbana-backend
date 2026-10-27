<?php

// CODEX: 4 linhas alteradas; avaliação, embarque e cancelamentos. Remover após validação.

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\CotacaoCorrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\SalaCompartilhada;
use App\Models\User;
use App\Services\AjustarPontoEmbarqueService;
use App\Services\AlterarCorridaService;
use App\Services\AumentarOfertaNegociaService;
use App\Services\CalcularPrecoCorridaService;
use App\Services\Compartilhada\GrupoCompartilhadoService;
use App\Services\Compartilhada\SalaCompartilhadaService;
use App\Services\ContabilizarEsperaCorridaService;
use App\Services\DespachoCorridaService;
use App\Services\EstimarChegadaService;
use App\Services\EstimarRotaService;
use App\Services\ObterNavegacaoService;
use App\Services\ObterTracadoRotaService;
use App\Services\PagamentoCorridaService;
use App\Services\ResolverTarifaService;
use App\Services\SimularCorridaNegociadaService;
use App\Services\SolicitarCorridaService;
use App\Support\Reputacao;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CorridaController extends Controller
{
    private const STATUS_ATIVOS = [
        'aguardando_pagamento',
        'solicitada',
        'em_busca',
        'aceita',
        'motorista_chegou',
        'em_andamento',
    ];

    private const METROS_POR_GRAU_LATITUDE = 111320;

    public function __construct(
        protected EstimarRotaService $estimarRotaService,
        protected SimularCorridaNegociadaService $simularCorridaNegociadaService,
        protected ObterTracadoRotaService $obterTracadoRotaService,
        protected ResolverTarifaService $resolverTarifaService,
        protected CalcularPrecoCorridaService $calcularPrecoCorridaService,
        protected ContabilizarEsperaCorridaService $contabilizarEsperaCorridaService,
        protected SolicitarCorridaService $solicitarCorridaService,
        protected DespachoCorridaService $despachoCorridaService,
        protected EstimarChegadaService $estimarChegadaService
    ) {}

    public function tracadoRota(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pontos' => 'required|array|min:2|max:6',
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

    public function ajustarPontoEmbarque(
        Request $request,
        AjustarPontoEmbarqueService $ajustarPontoEmbarqueService,
    ): JsonResponse {
        $dados = $request->validate([
            'latitude' => 'required|numeric|between:-90,90|not_in:0',
            'longitude' => 'required|numeric|between:-180,180|not_in:0',
        ]);

        return response()->json($ajustarPontoEmbarqueService->executar(
            (float) $dados['latitude'],
            (float) $dados['longitude'],
        ));
    }

    /**
     * Rota passo a passo (manobras) para a navegação dentro do app, entre a
     * posição atual do motorista e o próximo ponto da corrida.
     */
    public function navegacaoRota(
        Request $request,
        ObterNavegacaoService $obterNavegacaoService,
    ): JsonResponse {
        $dados = $request->validate([
            'origem_latitude' => 'required|numeric|between:-90,90',
            'origem_longitude' => 'required|numeric|between:-180,180',
            'destino_latitude' => 'required|numeric|between:-90,90',
            'destino_longitude' => 'required|numeric|between:-180,180',
        ]);

        $rota = $obterNavegacaoService->executar(
            (float) $dados['origem_latitude'],
            (float) $dados['origem_longitude'],
            (float) $dados['destino_latitude'],
            (float) $dados['destino_longitude'],
        );

        if ($rota === null) {
            return response()->json(['message' => 'Não foi possível calcular a rota.'], 422);
        }

        return response()->json($rota);
    }

    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, Corrida>
     */
    /**
     * @return LengthAwarePaginator<int, Corrida>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        $dados = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $pagina = $this->doUsuario($request)
            ->with([
                'produto:id,nome',
                'motorista:id,user_id,foto',
                'motorista.user:id,name',
                'passageiro:id,user_id,foto',
                'passageiro.user:id,name',
                'veiculo:id,modelo,cor,placa',
                'corrida_destinos:id,corrida_id,tipo,ordem,endereco',
                'corrida_financeiro:id,corrida_id,valor_pago_passageiro,tarifa_base,taxa_cancelamento,taxa_plataforma_valor,taxa_plataforma_percentual,valor_motorista,valor_liquido_motorista,metodo_pagamento',
            ])
            ->orderByDesc('id')
            ->paginate($dados['per_page'] ?? 20);

        $pagina->getCollection()->each(fn (Corrida $corrida) => $this->reduzirNomes($corrida));

        return $pagina;
    }

    public function previsaoCancelamento(Request $request, int $corrida): JsonResponse
    {
        $passageiroId = Passageiro::where('user_id', $request->user()->id)->value('id');

        $registro = $passageiroId === null ? null : Corrida::whereKey($corrida)
            ->where('passageiro_id', $passageiroId)
            ->first();

        if ($registro === null) {
            return response()->json(['message' => 'Corrida não encontrada.'], 404);
        }

        return response()->json($this->despachoCorridaService->previsaoCancelamentoPassageiro($registro));
    }

    public function cancelar(Request $request, int $corrida): JsonResponse
    {
        $dados = $request->validate([
            'motivo' => 'nullable|string|max:255',
            'taxa_confirmada' => 'nullable|numeric|min:0',
        ]);

        $passageiroId = Passageiro::where('user_id', $request->user()->id)->value('id');

        try {
            $cancelada = $this->despachoCorridaService->cancelar(
                corridaId: $corrida,
                quem: 'passageiro',
                donoId: $passageiroId === null ? null : (int) $passageiroId,
                motivo: $dados['motivo'] ?? null,
                taxaConfirmada: isset($dados['taxa_confirmada']) ? (float) $dados['taxa_confirmada'] : null
            );
        } catch (RuntimeException $excecao) {
            $status = in_array($excecao->getCode(), [404, 409], true)
                ? (int) $excecao->getCode()
                : 422;

            return response()->json(['message' => $excecao->getMessage()], $status);
        }

        return response()->json($cancelada);
    }

    public function alterarPagamento(Request $request, int $corrida, AlterarCorridaService $alterarCorrida): JsonResponse
    {
        $dados = $request->validate([
            'metodo_pagamento' => 'required|in:dinheiro,cartao,pix',
        ]);

        return $this->alterarComoPassageiro($request, $corrida, $alterarCorrida,
            fn (int $passageiroId) => $alterarCorrida->alterarPagamento($passageiroId, $corrida, $dados['metodo_pagamento']));
    }

    public function pedirNovoDestino(Request $request, int $corrida, AlterarCorridaService $alterarCorrida): JsonResponse
    {
        $dados = $request->validate([
            'tipo' => 'sometimes|in:destino,parada',
            'endereco' => 'required|string|max:255',
            'latitude' => 'required|numeric|between:-90,90|not_in:0',
            'longitude' => 'required|numeric|between:-180,180|not_in:0',
            'itinerario' => 'sometimes|array|min:2|max:'.(Corrida::MAX_PARADAS + 2),
            'itinerario.*.endereco' => 'required_with:itinerario|string|max:255',
            'itinerario.*.latitude' => 'required_with:itinerario|numeric|between:-90,90|not_in:0',
            'itinerario.*.longitude' => 'required_with:itinerario|numeric|between:-180,180|not_in:0',
        ], [
            'itinerario.max' => 'A corrida pode ter no máximo '.Corrida::MAX_PARADAS.' paradas.',
        ]);

        return $this->alterarComoPassageiro($request, $corrida, $alterarCorrida,
            fn (int $passageiroId) => $alterarCorrida->pedirNovoDestino($passageiroId, $corrida, [
                'tipo' => $dados['tipo'] ?? 'destino',
                'endereco' => $dados['endereco'],
                'latitude' => (float) $dados['latitude'],
                'longitude' => (float) $dados['longitude'],
                'itinerario' => isset($dados['itinerario'])
                    ? array_map(fn (array $ponto): array => [
                        'endereco' => $ponto['endereco'],
                        'latitude' => (float) $ponto['latitude'],
                        'longitude' => (float) $ponto['longitude'],
                    ], $dados['itinerario'])
                    : null,
            ]));
    }

    public function desistirDoNovoDestino(Request $request, int $corrida, AlterarCorridaService $alterarCorrida): JsonResponse
    {
        return $this->alterarComoPassageiro($request, $corrida, $alterarCorrida,
            fn (int $passageiroId) => $alterarCorrida->desistirDoNovoDestino($passageiroId, $corrida));
    }

    /**
     * @param  callable(int): Corrida  $alterar
     */
    private function alterarComoPassageiro(Request $request, int $corrida, AlterarCorridaService $alterarCorrida, callable $alterar): JsonResponse
    {
        $passageiroId = Passageiro::where('user_id', $request->user()->id)->value('id');

        if ($passageiroId === null) {
            return response()->json(['message' => 'Corrida não encontrada.'], 404);
        }

        try {
            $atualizada = $alterar((int) $passageiroId);
        } catch (RuntimeException $excecao) {
            $status = in_array($excecao->getCode(), [404, 409, 422, 429], true) ? (int) $excecao->getCode() : 422;

            return response()->json(['message' => $excecao->getMessage()], $status);
        }

        $atualizada->setAttribute('pagamento_alteravel', $alterarCorrida->pagamentoAlteravel($atualizada));
        $atualizada->setAttribute('destino_alteravel', $alterarCorrida->destinoAlteravel($atualizada));
        $atualizada->setAttribute('alteracao_destino', $alterarCorrida->resumo($atualizada));

        return response()->json($atualizada);
    }

    public function minhaCorridaAtual(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'perfil' => 'sometimes|in:passageiro,motorista',
        ]);

        $relacoes = [
            'motorista:id,user_id,foto',
            'motorista.user:id,name,telefone',
            'passageiro:id,user_id,foto',
            'passageiro.user:id,name',
            'veiculo',
            'corrida_destinos',
            'corrida_financeiro',
            'produto:id,nome,grupo,tipo_veiculo',
            'opcoes.produto:id,nome,codigo,grupo,tipo_veiculo',
        ];
        $corrida = $this->doUsuario($request, $dados['perfil'] ?? null)
            ->whereIn('status_corrida', self::STATUS_ATIVOS)
            ->with($relacoes)
            ->orderByDesc('id')
            ->first();

        if ($corrida === null) {
            return response()->json([
                'corrida' => null,
                'encerrada' => $this->resumoDaCanceladaRecente($request, $dados['perfil'] ?? null),
                'sala_compartilhada' => ($dados['perfil'] ?? null) === 'motorista' ? null : $this->salaCompartilhadaAtual($request),
            ]);
        }

        $motoristaId = Motorista::where('user_id', $request->user()->id)->value('id');
        $grupo = app(GrupoCompartilhadoService::class);
        $comoMotorista = $motoristaId !== null && $corrida->motorista_id === (int) $motoristaId;

        // na compartilhada o motorista age na corrida da próxima parada
        if ($comoMotorista && $corrida->sala_compartilhada_id !== null) {
            $daVez = $grupo->corridaDaVez((int) $corrida->sala_compartilhada_id);
            $corrida = $daVez === null ? $corrida : (Corrida::with($relacoes)->find($daVez->id) ?? $corrida);
        }

        $this->reduzirNomes($corrida);
        $ocultarFotoPassageiro = $motoristaId !== null
            && $corrida->motorista_id === (int) $motoristaId
            && ! in_array($corrida->status_corrida, ['motorista_chegou', 'em_andamento'], true);
        if ($ocultarFotoPassageiro) {
            $corrida->passageiro?->setAttribute('foto', null);
        }

        // na compartilhada não há carência: depois do aceite, sair tem taxa
        if ($corrida->status_corrida === 'aceita' && $corrida->tempo_aceite !== null && $corrida->sala_compartilhada_id === null) {
            $corrida->setAttribute(
                'cancelamento_gratis_ate',
                Carbon::parse($corrida->tempo_aceite)
                    ->addSeconds(max(0, (int) config('precificacao.cancelamento_passageiro_carencia_segundos', 180)))
                    ->toIso8601String()
            );
        }

        // Negocia: até quanto o passageiro pode aumentar a oferta
        if ($corrida->status_negociacao === 'em_negociacao' && $corrida->corrida_financeiro !== null) {
            $corrida->setAttribute('oferta_maxima', AumentarOfertaNegociaService::ofertaMaxima($corrida->corrida_financeiro));
        }

        // Negocia no Pix/cartão: o motorista escolhido espera o pagamento até
        // este horário (depois a corrida é cancelada e ele fica livre)
        if ($corrida->status_corrida === 'aguardando_pagamento'
            && $corrida->motorista_id !== null
            && $corrida->tempo_aceite !== null) {
            $corrida->setAttribute(
                'pagamento_expira_em',
                Carbon::parse($corrida->tempo_aceite)
                    ->addSeconds((int) config('precificacao.negocia_pagamento_limite_segundos', 300))
                    ->toIso8601String()
            );
        }

        // o que falta pagar agora: no Negocia, depois de pagar a oferta no
        // pedido, só a diferença da proposta maior que foi escolhida
        if ($corrida->status_corrida === 'aguardando_pagamento') {
            $corrida->setAttribute('valor_a_pagar', app(PagamentoCorridaService::class)->valorDevido($corrida));
        }

        $posicao = $corrida->motorista_id === null
            ? null
            : $this->despachoCorridaService->posicaoDoMotorista($corrida->motorista_id);

        $alterarCorrida = app(AlterarCorridaService::class);
        $corrida->setAttribute('pagamento_alteravel', $alterarCorrida->pagamentoAlteravel($corrida));
        $corrida->setAttribute('destino_alteravel', $alterarCorrida->destinoAlteravel($corrida));

        return response()->json([
            'corrida' => $corrida,
            'alteracao_destino' => $alterarCorrida->resumo($corrida),
            'motorista_posicao' => $posicao,
            'chegada' => $this->estimarChegadaService->paraCorrida($corrida),
            'passageiro' => $this->passageiroParaOMotorista($corrida, $request),
            'motorista_info' => $this->motoristaParaOPassageiro($corrida, $request),
            'espera' => $corrida->status_corrida === 'motorista_chegou'
                ? $this->contabilizarEsperaCorridaService->resumo($corrida)
                : null,
            'compartilhada' => $grupo->resumoDaCorrida($corrida, $comoMotorista),
        ]);
    }

    /**
     * A sala compartilhada em que o passageiro está esperando o grupo fechar.
     *
     * @return array<string, mixed>|null
     */
    private function salaCompartilhadaAtual(Request $request): ?array
    {
        $passageiroId = Passageiro::where('user_id', $request->user()->id)->value('id');

        if ($passageiroId === null) {
            return null;
        }

        $salas = app(SalaCompartilhadaService::class);
        $participacao = $salas->participacaoAtual((int) $passageiroId);

        if ($participacao === null) {
            return null;
        }

        $salas->processar($participacao->sala_id);
        $sala = SalaCompartilhada::find($participacao->sala_id);

        return $sala === null ? null : $salas->resumo($sala, (int) $passageiroId);
    }

    /**
     * O motorista que estava numa corrida recém-cancelada fica sabendo quem
     * cancelou (e a taxa que recebeu), em vez da corrida só sumir da tela.
     *
     * @return array{id: int, cancelado_por: string|null, taxa_cancelamento: float}|null
     */
    private function resumoDaCanceladaRecente(Request $request, ?string $perfil): ?array
    {
        if ($perfil !== 'motorista') {
            return null;
        }

        $cancelada = $this->doUsuario($request, $perfil)
            ->where('status_corrida', 'cancelada')
            ->whereNotNull('motorista_id')
            ->where('updated_at', '>=', now()->subMinutes(3))
            ->with('corrida_financeiro:id,corrida_id,taxa_cancelamento')
            ->orderByDesc('id')
            ->first();

        if ($cancelada === null) {
            return null;
        }

        return [
            'id' => $cancelada->id,
            'cancelado_por' => $cancelada->cancelado_por,
            'taxa_cancelamento' => (float) ($cancelada->corrida_financeiro->taxa_cancelamento ?? 0),
        ];
    }

    /**
     * Só o passageiro dono da corrida enxerga a nota, o telefone e o total
     * de corridas do motorista designado.
     *
     * @return array<string, mixed>|null
     */
    private function motoristaParaOPassageiro(Corrida $corrida, Request $request): ?array
    {
        $passageiroId = Passageiro::where('user_id', $request->user()->id)->value('id');

        if ($passageiroId === null || $corrida->passageiro_id !== $passageiroId) {
            return null;
        }

        $motorista = $corrida->motorista;
        $usuario = $motorista?->user;

        if ($motorista === null || $usuario === null) {
            return null;
        }

        $corridasDoMotorista = Corrida::where('motorista_id', $motorista->id);

        // tipo_usuario guarda quem deu a nota: "passageiro" aqui são as
        // notas que passageiros deram a este motorista, não o contrário
        $nota = AvaliacoesCorrida::where('tipo_usuario', 'passageiro')
            ->whereIn('corrida_id', (clone $corridasDoMotorista)->select('id'))
            ->avg('nota');

        $telefone = $usuario->telefone;
        $finalizadas = (clone $corridasDoMotorista)
            ->where('status_corrida', 'finalizada')
            ->count();

        return [
            'nome' => $this->primeiroNome((string) $usuario->name),
            'foto' => $motorista->foto,
            'telefone' => is_string($telefone) ? $telefone : null,
            'nota' => Reputacao::media($nota, $finalizadas),
            'corridas' => $finalizadas,
        ];
    }

    /**
     * Só o motorista designado enxerga quem é o passageiro e como ligar pra ele.
     *
     * @return array<string, mixed>|null
     */
    private function passageiroParaOMotorista(Corrida $corrida, Request $request): ?array
    {
        $motoristaId = Motorista::where('user_id', $request->user()->id)->value('id');

        if ($motoristaId === null || $corrida->motorista_id !== $motoristaId) {
            return null;
        }

        $passageiro = $corrida->passageiro;
        $usuario = $passageiro?->user;

        if ($passageiro === null || $usuario === null) {
            return null;
        }

        $corridasDoPassageiro = Corrida::where('passageiro_id', $passageiro->id);

        $nota = AvaliacoesCorrida::where('tipo_usuario', 'motorista')
            ->whereIn('corrida_id', (clone $corridasDoPassageiro)->select('id'))
            ->avg('nota');

        $telefone = $passageiro->user()->value('telefone');
        $finalizadas = (clone $corridasDoPassageiro)
            ->where('status_corrida', 'finalizada')
            ->count();
        $convidado = $corrida->convidado_nome !== null;
        $fotoLiberada = ! $convidado
            && in_array($corrida->status_corrida, ['motorista_chegou', 'em_andamento'], true);

        return [
            'nome' => $this->primeiroNome((string) ($convidado ? $corrida->convidado_nome : $usuario->name)),
            'solicitante' => $convidado ? $this->primeiroNome((string) $usuario->name) : null,
            'foto' => $fotoLiberada ? $passageiro->foto : null,
            'foto_oculta' => ! $fotoLiberada,
            'telefone' => $convidado
                ? $corrida->convidado_telefone
                : (is_string($telefone) ? $telefone : null),
            'nota' => Reputacao::media($nota, $finalizadas),
            'corridas' => $finalizadas,
        ];
    }

    private function reduzirNomes(Corrida $corrida): void
    {
        foreach ([$corrida->motorista?->user, $corrida->passageiro?->user] as $usuario) {
            if ($usuario !== null) {
                $usuario->setAttribute('name', $this->primeiroNome((string) $usuario->name));
            }
        }
    }

    private function primeiroNome(string $nome): string
    {
        return preg_split('/\s+/u', trim($nome), 2)[0] ?? '';
    }

    /**
     * @return Builder<Corrida>
     */
    private function doUsuario(Request $request, ?string $perfil = null): Builder
    {
        $usuarioId = $request->user()->id;

        $passageiroId = Passageiro::where('user_id', $usuarioId)->value('id');
        $motoristaId = Motorista::where('user_id', $usuarioId)->value('id');

        return Corrida::query()->where(function (Builder $consulta) use ($passageiroId, $motoristaId, $perfil) {
            $consulta->whereRaw('1 = 0');

            if ($passageiroId !== null && $perfil !== 'motorista') {
                $consulta->orWhere('passageiro_id', $passageiroId);
            }

            if ($motoristaId !== null && $perfil !== 'passageiro') {
                $consulta->orWhere('motorista_id', $motoristaId);
            }
        });
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        if (is_array($request->input('convidado'))) {
            $request->merge(['convidado' => [
                'nome' => trim((string) $request->input('convidado.nome')),
                'telefone' => preg_replace('/\D/', '', (string) $request->input('convidado.telefone')),
            ]]);
        }

        $dados = $request->validate([
            'cotacao_id' => 'required|uuid',
            'produto_codigo' => 'required_without:produtos_codigos|string|max:60',
            // várias categorias de uma vez (ex.: Normal de carro e de moto): vai quem atender primeiro
            'produtos_codigos' => 'sometimes|array|min:1|max:8',
            'produtos_codigos.*' => 'string|max:60|distinct',
            // Negocia: quanto o passageiro oferece
            'valor_oferecido' => 'nullable|numeric|min:1|max:5000',
            // Negocia: escolhe sozinho o primeiro motorista que aceitar a oferta
            'aceite_automatico' => 'nullable|boolean',
            'metodo_pagamento' => 'nullable|in:dinheiro,cartao,pix',
            'convidado' => 'nullable|array',
            'convidado.nome' => 'required_with:convidado|string|min:2|max:60',
            'convidado.telefone' => ['required_with:convidado', 'string', 'regex:/^\d{10,11}$/'],
        ], [
            'convidado.nome.required_with' => 'Informe o nome de quem vai viajar.',
            'convidado.telefone.required_with' => 'Informe o telefone de quem vai viajar.',
            'convidado.telefone.regex' => 'Informe um telefone com DDD.',
        ]);

        $cotacao = CotacaoCorrida::find((string) $dados['cotacao_id']);

        if ($cotacao === null || $cotacao->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Cotação não encontrada.'], 404);
        }

        if ($cotacao->expirada()) {
            return response()->json(['message' => 'Cotação expirada. Refaça a consulta de preços.'], 410);
        }

        $usuario = $request->user();
        abort_unless($usuario instanceof User, 401, 'Não autenticado.');

        try {
            $corrida = $this->solicitarCorridaService->executar(
                usuario: $usuario,
                cotacao: $cotacao,
                produtosCodigos: array_values(array_map('strval', $dados['produtos_codigos'] ?? [$dados['produto_codigo']])),
                metodoPagamento: $dados['metodo_pagamento'] ?? null,
                convidado: $dados['convidado'] ?? null,
                valorOferecido: isset($dados['valor_oferecido']) ? (float) $dados['valor_oferecido'] : null,
                aceiteAutomatico: $request->boolean('aceite_automatico')
            );
        } catch (RuntimeException $excecao) {
            $status = $excecao->getCode() === 409 ? 409 : 422;

            return response()->json(['message' => $excecao->getMessage()], $status);
        }

        return response()->json($corrida, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $corrida): JsonResponse
    {
        $encontrada = $this->doUsuario($request)
            ->whereKey($corrida)
            ->with([
                'motorista:id,user_id,foto',
                'motorista.user:id,name',
                'passageiro:id,user_id,foto',
                'passageiro.user:id,name',
                'veiculo',
                'corrida_destinos',
                'corrida_financeiro',
            ])
            ->first();

        if ($encontrada === null) {
            return response()->json(['message' => 'Corrida não encontrada.'], 404);
        }

        $this->reduzirNomes($encontrada);

        return response()->json($encontrada);
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
        $dados = $request->validate(['endereco' => 'required|string|max:200']);
        $endereco = $dados['endereco'];

        // menos de 3 caracteres quase nunca traz resultado útil — evita
        // gastar requisição da Places API à toa
        if (mb_strlen(trim($endereco)) < 3) {
            return response()->json([]);
        }

        $chaveCache = 'busca-endereco:'.md5(mb_strtolower(trim($endereco)));

        /** @var list<array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}>|null $resultados */
        $resultados = Cache::get($chaveCache);

        if ($resultados === null) {
            $resultados = $this->consultarEnderecos($endereco);
            // vazio guarda pouco: uma falha passageira do Google não pode
            // esconder a rua por horas
            Cache::put($chaveCache, $resultados, $resultados === [] ? now()->addMinutes(10) : now()->addHours(6));
        }

        if (empty($resultados)) {
            return response()->json([]);
        }

        return response()->json($this->ordenarPorProximidade($resultados, $request));
    }

    /**
     * @param  list<array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}>  $resultados
     * @return list<array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}>
     */
    private function ordenarPorProximidade(array $resultados, Request $request): array
    {
        $posicao = $this->posicaoDoUsuario($request);

        if ($posicao === null) {
            return $resultados;
        }

        [$latitude, $longitude] = $posicao;

        $ordenados = $resultados;

        usort(
            $ordenados,
            fn (array $a, array $b) => $this->faixaDeProximidade($a, $latitude, $longitude)
                <=> $this->faixaDeProximidade($b, $latitude, $longitude)
        );

        return $ordenados;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function posicaoDoUsuario(Request $request): ?array
    {
        $latitude = $request->float('latitude');
        $longitude = $request->float('longitude');

        $valida = $latitude !== 0.0
            && $longitude !== 0.0
            && abs($latitude) <= 90
            && abs($longitude) <= 180;

        return $valida ? [$latitude, $longitude] : null;
    }

    /**
     * @param  array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}  $resultado
     */
    private function faixaDeProximidade(array $resultado, float $latitude, float $longitude): int
    {
        if ($resultado['latitude'] === null || $resultado['longitude'] === null) {
            return PHP_INT_MAX;
        }

        $deltaLatitude = ($resultado['latitude'] - $latitude) * self::METROS_POR_GRAU_LATITUDE;

        $deltaLongitude = ($resultado['longitude'] - $longitude)
            * self::METROS_POR_GRAU_LATITUDE
            * max(cos(deg2rad($latitude)), 0.01);

        $quilometros = hypot($deltaLatitude, $deltaLongitude) / 1000;

        $faixa = (float) config('services.google_maps.faixa_proximidade_km');

        return (int) log(1 + $quilometros / max($faixa, 0.1), 2);
    }

    /**
     * Lugares (Places) e, para nome de rua, endereços (Geocoding): a busca de
     * lugares do Google não acha muitas ruas ("Rua Jobu Miró", "Rua X, 123"),
     * que o geocoding de endereço acha.
     *
     * @return list<array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}>
     */
    private function consultarEnderecos(string $endereco): array
    {
        $lugares = $this->consultarPlaces($endereco);
        $enderecos = $lugares === [] || $this->pareceEndereco($endereco)
            ? $this->consultarGeocoding($endereco)
            : [];

        $vistos = [];
        $unicos = [];

        foreach ([...$enderecos, ...$lugares] as $resultado) {
            $chave = mb_strtolower($resultado['name'].'|'.$resultado['formattedAddress']);

            if (! isset($vistos[$chave])) {
                $vistos[$chave] = true;
                $unicos[] = $resultado;
            }
        }

        return $unicos;
    }

    private function pareceEndereco(string $endereco): bool
    {
        return preg_match('/^\s*(r\.?|rua|av\.?|avenida|tv\.?|travessa|al\.?|alameda|estrada|rodovia|pç\.?|praça)\s/iu', $endereco) === 1
            || preg_match('/\d/', $endereco) === 1;
    }

    /**
     * @return list<array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}>
     */
    private function consultarGeocoding(string $endereco): array
    {
        $regiao = $this->caixaDaRegiaoAtendida();

        try {
            $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $endereco,
                'key' => config('services.google_maps.key'),
                'language' => 'pt-BR',
                'region' => 'br',
                'components' => 'country:BR',
                'bounds' => $regiao['low']['latitude'].','.$regiao['low']['longitude'].'|'
                    .$regiao['high']['latitude'].','.$regiao['high']['longitude'],
            ]);
        } catch (ConnectionException) {
            return [];
        }

        $resultados = [];

        foreach ($response->json('results') ?? [] as $resultado) {
            $latitude = $resultado['geometry']['location']['lat'] ?? null;
            $longitude = $resultado['geometry']['location']['lng'] ?? null;
            $endereco = (string) ($resultado['formatted_address'] ?? '');

            // fora da região atendida não serve (o bounds só dá preferência)
            if (! is_numeric($latitude) || ! is_numeric($longitude) || $endereco === ''
                || $latitude < $regiao['low']['latitude'] || $latitude > $regiao['high']['latitude']
                || $longitude < $regiao['low']['longitude'] || $longitude > $regiao['high']['longitude']) {
                continue;
            }

            // "R. Jobu Miró, 123 - Bairro, Cidade - RO, CEP, Brasil": o nome é a
            // rua (com número), o resto vira a linha de baixo
            [$nome, $restante] = array_pad(explode(' - ', $endereco, 2), 2, '');

            $resultados[] = [
                'name' => $nome,
                'formattedAddress' => $restante !== '' ? $restante : $endereco,
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ];
        }

        return array_slice($resultados, 0, 3);
    }

    /**
     * @return list<array{name: string, formattedAddress: string, latitude: float|null, longitude: float|null}>
     */
    private function consultarPlaces(string $endereco): array
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
                'rectangle' => $this->caixaDaRegiaoAtendida(),
            ],
        ]);

        $places = $response->json('places') ?? [];

        return array_values(array_map(
            fn (array $place) => $this->formatarPlace($place),
            $places
        ));
    }

    /**
     * @return array{low: array{latitude: float, longitude: float}, high: array{latitude: float, longitude: float}}
     */
    private function caixaDaRegiaoAtendida(): array
    {
        $regiao = config('services.google_maps.regiao_atendida');

        return [
            'low' => [
                'latitude' => (float) $regiao['latitude_min'],
                'longitude' => (float) $regiao['longitude_min'],
            ],
            'high' => [
                'latitude' => (float) $regiao['latitude_max'],
                'longitude' => (float) $regiao['longitude_max'],
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
        $dados = $request->validate([
            'enderecos' => 'required|array|min:2|max:6',
            'enderecos.*.order' => 'required|integer|min:0',
            'enderecos.*.latitude' => 'required|numeric|between:-90,90',
            'enderecos.*.longitude' => 'required|numeric|between:-180,180',
        ]);

        return response()->json($this->estimarRotaService->executar(enderecos: $dados['enderecos']));
    }

    public function precosCorrida(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'enderecos' => 'required|array|min:2|max:'.(Corrida::MAX_PARADAS + 2),
            'enderecos.*.order' => 'required|integer|min:0',
            'enderecos.*.latitude' => 'required|numeric|between:-90,90',
            'enderecos.*.longitude' => 'required|numeric|between:-180,180',
            'enderecos.*.formattedAddress' => 'nullable|string|max:500',
            'cidade_id' => 'nullable|integer',
            'tempo_espera_min' => 'nullable|numeric|min:0|max:600',
        ], [
            'enderecos.max' => 'A corrida pode ter no máximo '.Corrida::MAX_PARADAS.' paradas.',
        ]);

        $rota = $this->estimarRotaService->executar(enderecos: $dados['enderecos']);

        $distanciaKm = (float) ($rota['distancia_km'] ?? 0);
        $tempoMin = (float) ($rota['tempo_minutos'] ?? 0);

        if ($distanciaKm <= 0) {
            return response()->json(['message' => 'Não foi possível calcular a rota.'], 422);
        }

        $tarifas = $this->resolverTarifaService->paraTodosOsProdutos(
            isset($dados['cidade_id']) ? (int) $dados['cidade_id'] : null,
            now()
        );

        if ($tarifas->isEmpty()) {
            return response()->json(['message' => 'Nenhuma tarifa ativa para este momento.'], 422);
        }

        $categorias = $tarifas
            ->map(fn ($tarifa) => $this->calcularPrecoCorridaService->executar(
                tarifa: $tarifa,
                distanciaKm: $distanciaKm,
                tempoMin: $tempoMin,
                tempoEsperaMin: (float) ($dados['tempo_espera_min'] ?? 0)
            ))
            ->values();

        $cotacao = CotacaoCorrida::create([
            'user_id' => $request->user()->id,
            'cidade_id' => isset($dados['cidade_id']) ? (int) $dados['cidade_id'] : null,
            'distancia_km' => $distanciaKm,
            'tempo_min' => $tempoMin,
            'enderecos' => $dados['enderecos'],
            'categorias' => $categorias->all(),
            'expira_em' => now()->addMinutes(
                (int) config('precificacao.cotacao_validade_minutos')
            ),
        ]);

        return response()->json([
            'cotacao_id' => $cotacao->id,
            'expira_em' => $cotacao->expira_em->toIso8601String(),
            'rota' => $rota,
            'categorias' => $categorias,
        ]);
    }

    public function mostrarCotacao(Request $request, string $cotacao): JsonResponse
    {
        $registro = CotacaoCorrida::find($cotacao);

        if ($registro === null || $registro->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Cotação não encontrada.'], 404);
        }

        if ($registro->expirada()) {
            return response()->json(['message' => 'Cotação expirada.'], 410);
        }

        return response()->json([
            'cotacao_id' => $registro->id,
            'expira_em' => $registro->expira_em->toIso8601String(),
            'rota' => [
                'distancia_km' => $registro->distancia_km,
                'tempo_minutos' => $registro->tempo_min,
            ],
            'categorias' => $registro->categorias,
        ]);
    }

    public function simularCorridaNegociada(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'distancia_km' => [
                'required', 'numeric', 'min:0',
                'max:'.config('precificacao.distancia_maxima_km'),
            ],
            'tempo_min' => [
                'required', 'numeric', 'min:0',
                'max:'.config('precificacao.tempo_maximo_min'),
            ],
            'diferenca_negociada' => 'nullable|numeric',
        ]);

        return response()->json($this->simularCorridaNegociadaService->executar($dados));
    }
}
