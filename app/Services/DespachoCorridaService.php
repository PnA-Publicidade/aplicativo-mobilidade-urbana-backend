<?php

// CODEX: 116 linhas alteradas; avaliação, embarque e cancelamentos. Remover após validação.

namespace App\Services;

use App\Events\CorridaAtualizada;
use App\Events\CorridasDisponiveisAlteradas;
use App\Events\MotoristaMoveu;
use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\CorridaFinanceiro;
use App\Models\CorridaNegociacoes;
use App\Models\CorridaOpcao;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\Passageiro;
use App\Models\ProdutosCorrida;
use App\Models\StatusBusca;
use App\Models\Tarifa;
use App\Models\Veiculo;
use App\Services\Compartilhada\GrupoCompartilhadoService;
use App\Support\Avisar;
use App\Support\Reputacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DespachoCorridaService
{
    private const RAIO_TERRA_KM = 6371;

    private const STATUS_ATIVOS_MOTORISTA = [
        // Negocia no Pix/cartão: escolhido, o motorista espera o pagamento
        'aguardando_pagamento',
        'aceita',
        'motorista_chegou',
        'em_andamento',
    ];

    public function __construct(
        private readonly ContabilizarEsperaCorridaService $contabilizarEsperaCorridaService,
        private readonly NotificarUsuarioService $notificarUsuario,
        private readonly PagamentoCorridaService $pagamento,
    ) {}

    public function atualizarDisponibilidade(
        Motorista $motorista,
        bool $disponivel,
        ?float $latitude,
        ?float $longitude,
        ?int $veiculoId
    ): StatusBusca {
        return app(CompartilhamentoVeiculoService::class)->atualizarDisponibilidade($motorista, $disponivel, $latitude, $longitude, $veiculoId);
    }

    /**
     * @return array<string, mixed>
     */
    public function situacaoDe(Motorista $motorista): array
    {
        $status = StatusBusca::where('motorista_id', $motorista->id)->first();

        $corrida = Corrida::where('motorista_id', $motorista->id)
            ->whereIn('status_corrida', self::STATUS_ATIVOS_MOTORISTA)
            ->orderByDesc('id')
            ->first();

        if ($corrida?->sala_compartilhada_id !== null) {
            $corrida = app(GrupoCompartilhadoService::class)->corridaDaVez((int) $corrida->sala_compartilhada_id) ?? $corrida;
        }

        if ($corrida === null && $this->onlineExpirou($status)) {
            $status?->update(['disponivel' => false]);
        }

        return [
            'disponivel' => (bool) ($status->disponivel ?? false),
            'posicao' => $status === null || $status->latitude === null || $status->longitude === null
                ? null
                : [
                    'latitude' => (float) $status->latitude,
                    'longitude' => (float) $status->longitude,
                ],
            'corrida' => $corrida === null ? null : [
                'id' => $corrida->id,
                'codigo_corrida' => $corrida->codigo_corrida,
                'status_corrida' => $corrida->status_corrida,
            ],
        ];
    }

    public function atualizarPosicao(
        Motorista $motorista,
        float $latitude,
        float $longitude
    ): StatusBusca {
        $status = app(CompartilhamentoVeiculoService::class)->atualizarPosicao($motorista, $latitude, $longitude);

        $corridaIds = Corrida::where('motorista_id', $motorista->id)
            ->whereIn('status_corrida', self::STATUS_ATIVOS_MOTORISTA)
            ->pluck('id');

        // na compartilhada o motorista está em várias corridas ao mesmo tempo
        foreach ($corridaIds as $corridaId) {
            Avisar::semQuebrar(new MotoristaMoveu((int) $corridaId, $latitude, $longitude, (string) $status->visto_em?->toIso8601String()));
        }

        return $status;
    }

    /**
     * @return array{latitude: float, longitude: float, visto_em: string}|null
     */
    public function posicaoDoMotorista(int $motoristaId): ?array
    {
        $status = StatusBusca::where('motorista_id', $motoristaId)->first();

        if ($status === null || $status->latitude === null || $status->longitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $status->latitude,
            'longitude' => (float) $status->longitude,
            'visto_em' => (string) $status->visto_em?->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int, non-empty-array<string, mixed>>
     */
    public function ofertasPara(Motorista $motorista): Collection
    {
        $status = StatusBusca::where('motorista_id', $motorista->id)->first();

        if ($status === null || ! $status->disponivel) {
            throw new RuntimeException('Você precisa estar disponível para ver corridas.', 409);
        }

        if ($this->onlineExpirou($status)) {
            $status->update(['disponivel' => false]);
            throw new RuntimeException('Sua sessão online expirou. Conecte-se novamente.', 409);
        }

        if ($status->latitude === null || $status->longitude === null) {
            throw new RuntimeException('Posição do motorista desconhecida.', 422);
        }

        $veiculo = $this->veiculoEmUso($motorista, $status);

        // quem também é passageiro não recebe a própria corrida
        $meuPassageiroId = Passageiro::where('user_id', $motorista->user_id)->value('id');

        $corridas = Corrida::where('status_corrida', 'solicitada')
            ->whereNull('motorista_id')
            ->when($meuPassageiroId !== null, fn (Builder $consulta) => $consulta->where('passageiro_id', '!=', $meuPassageiroId))
            ->where(fn (Builder $consulta) => $this->queOVeiculoAtende($consulta, $veiculo))
            ->with(['corrida_destinos', 'corrida_financeiro', 'opcoes.produto', 'produto'])
            ->orderBy('tempo_solicitacao')
            ->get();

        // no Negocia o motorista vê se já mandou proposta e de quanto
        if ($corridas->contains(fn (Corrida $corrida) => $corrida->status_negociacao === 'em_negociacao')) {
            $corridas->load(['negociacoes' => fn ($proposta) => $proposta->where('motorista_id', $motorista->id)]);
        }

        $reputacoes = $this->reputacoesDosPassageiros($corridas);
        $raios = $this->raiosDasTarifas($corridas);
        $grupo = app(GrupoCompartilhadoService::class);

        // a compartilhada chega como uma chamada só, montada sobre o grupo
        $ofertasDosGrupos = $corridas
            ->whereNotNull('sala_compartilhada_id')
            ->groupBy('sala_compartilhada_id')
            ->map(fn ($doGrupo, $salaId) => $grupo->oferta((int) $salaId, $doGrupo, $status, $motorista, $veiculo, $reputacoes, $this->raioInicialKm($doGrupo->first(), $raios)));

        // Expresso (prioritário) primeiro; depois a mais perto
        $ofertas = $corridas
            ->whereNull('sala_compartilhada_id')
            ->toBase()
            ->map(fn (Corrida $corrida) => $this->montarOferta($corrida, $status, $reputacoes, $raios, $veiculo))
            ->values()
            ->merge($ofertasDosGrupos->values())
            ->filter()
            ->sortBy([
                fn (array $a, array $b) => $b['prioritaria'] <=> $a['prioritaria'],
                fn (array $a, array $b) => $a['distancia_ate_origem_km'] <=> $b['distancia_ate_origem_km'],
            ])
            ->values();

        // base da taxa de aceitação: cada corrida conta uma vez por motorista
        if ($ofertas->isNotEmpty()) {
            DB::table('ofertas_motorista')->insertOrIgnore($ofertas->map(fn (array $oferta) => [
                'motorista_id' => $motorista->id,
                'corrida_id' => $oferta['corrida_id'],
                'ofertada_em' => now(),
            ])->all());
        }

        return $ofertas;
    }

    /**
     * Recusar (ou deixar a chamada tocar até o fim) só pesa na taxa de
     * aceitação se a corrida ainda estava esperando motorista.
     */
    public function recusar(Motorista $motorista, int $corridaId): void
    {
        $recusadas = DB::table('ofertas_motorista')
            ->where('motorista_id', $motorista->id)
            ->where('corrida_id', $corridaId)
            ->whereNull('recusada_em')
            ->whereExists(fn ($consulta) => $consulta
                ->from('corridas')
                ->whereColumn('corridas.id', 'ofertas_motorista.corrida_id')
                ->where('corridas.status_corrida', 'solicitada')
                ->whereNull('corridas.motorista_id'))
            ->update(['recusada_em' => now()]);

        if ($recusadas > 0) {
            app(TaxasMotoristaService::class)->registrarRecusa($motorista->id, $corridaId);
        }
    }

    /**
     * A chamada apareceu na tela do motorista. Só a que tocou entra na conta
     * da taxa de aceitação.
     */
    public function registrarToque(Motorista $motorista, int $corridaId): void
    {
        app(TaxasMotoristaService::class)->registrarToque($motorista->id, $corridaId);
    }

    public function aceitar(Motorista $motorista, int $corridaId): Corrida
    {
        return DB::transaction(function () use ($motorista, $corridaId) {
            $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();

            if ($corrida === null) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if ($corrida->status_corrida !== 'solicitada' || $corrida->motorista_id !== null) {
                throw new RuntimeException('Esta corrida já foi aceita por outro motorista.', 409);
            }

            // no Negocia é o passageiro quem escolhe entre as propostas
            if ($corrida->status_negociacao === 'em_negociacao') {
                throw new RuntimeException('Nesta corrida o passageiro escolhe o motorista. Envie sua proposta.', 409);
            }

            if ($corrida->sala_compartilhada_id !== null) {
                return app(GrupoCompartilhadoService::class)->aceitar($this, $motorista, $corrida);
            }

            [$status, $veiculo, $opcao] = $this->validarMotoristaParaCorrida($motorista, $corrida);

            // pedido com várias categorias fica com a do motorista que aceitou
            if ($opcao !== null && $opcao->produto_id !== $corrida->produto_id) {
                $corrida->update([
                    'produto_id' => $opcao->produto_id,
                    'tarifa_id' => $opcao->tarifa_id,
                    'valor_estimado_inicial' => $opcao->valor_passageiro,
                ]);
                CorridaFinanceiro::where('corrida_id', $corrida->id)
                    ->update(SolicitarCorridaService::valoresDaCategoria($opcao->categoria));
            }

            $corrida->update([
                'motorista_id' => $motorista->id,
                'veiculo_id' => $veiculo?->id,
                'status_corrida' => 'aceita',
                'tempo_aceite' => now(),
                'distancia_motorista_aceite_km' => $this->distanciaAteOEmbarque($corrida, $status),
            ]);

            StatusBusca::where('motorista_id', $motorista->id)
                ->update(['disponivel' => false, 'visto_em' => now()]);

            $this->cancelarPropostasPendentes($motorista->id, $corrida->id);

            app(TaxasMotoristaService::class)->registrarAceite($corrida, $motorista->id);

            $this->notificarPassageiro($corrida, 'Motorista a caminho', 'Seu motorista aceitou a corrida e está indo até o embarque.');

            Avisar::semQuebrar(new CorridaAtualizada($corrida->id, 'aceita'));
            Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

            return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']);
        });
    }

    /**
     * Push e notificação no app do passageiro sobre a própria corrida. O texto
     * leva só o carro e a placa, que o passageiro precisa para embarcar.
     */
    private function notificarPassageiro(Corrida $corrida, string $titulo, string $mensagem): void
    {
        $userId = Passageiro::whereKey($corrida->passageiro_id)->value('user_id');

        if ($userId !== null) {
            $this->notificarUsuario->executar((int) $userId, 'passageiro', $titulo, $mensagem, [
                'corrida_id' => (string) $corrida->id,
                'status' => (string) $corrida->status_corrida,
            ]);
        }
    }

    private function carroDaCorrida(Corrida $corrida): string
    {
        $veiculo = $corrida->veiculo_id ? Veiculo::find($corrida->veiculo_id) : null;

        if ($veiculo === null) {
            return 'o carro do motorista';
        }

        $descricao = trim(implode(' ', array_filter([$veiculo->modelo, $veiculo->cor])));

        return trim(($descricao !== '' ? $descricao : 'o carro').($veiculo->placa ? ", placa {$veiculo->placa}" : ''));
    }

    /**
     * Quem pegou uma corrida (normal, ou escolhido no Negocia) não atende mais
     * as outras: as propostas dele que esperavam passageiro são canceladas e
     * somem da tela desses passageiros.
     */
    public function cancelarPropostasPendentes(int $motoristaId, int $corridaAtendida): void
    {
        $corridas = CorridaNegociacoes::where('motorista_id', $motoristaId)
            ->where('corrida_id', '!=', $corridaAtendida)
            ->where('status', 'pendente')
            ->pluck('corrida_id');

        if ($corridas->isEmpty()) {
            return;
        }

        CorridaNegociacoes::where('motorista_id', $motoristaId)
            ->whereIn('corrida_id', $corridas)
            ->where('status', 'pendente')
            ->update(['status' => 'cancelada']);

        foreach ($corridas as $corridaId) {
            Avisar::semQuebrar(new CorridaAtualizada((int) $corridaId, 'solicitada'));
        }
    }

    /**
     * Confere se o motorista pode pegar a corrida agora: online, livre, no
     * raio de busca e com veículo da categoria pedida. Chamar dentro da
     * transação, com a corrida travada.
     *
     * @return array{0: StatusBusca, 1: Veiculo|null, 2: CorridaOpcao|null}
     */
    public function validarMotoristaParaCorrida(Motorista $motorista, Corrida $corrida): array
    {
        if ($corrida->passageiro()->value('user_id') === $motorista->user_id) {
            throw new RuntimeException('Você não pode atender a sua própria corrida.', 409);
        }

        // restrição por taxa de finalização: nem aceitar nem propor
        app(RestricaoTaxaService::class)->exigirSemRestricao($motorista);

        $ocupado = Corrida::where('motorista_id', $motorista->id)
            ->whereKeyNot($corrida->id)
            ->whereIn('status_corrida', self::STATUS_ATIVOS_MOTORISTA)
            ->exists();

        if ($ocupado) {
            throw new RuntimeException('Você já está em uma corrida.', 409);
        }

        $status = StatusBusca::where('motorista_id', $motorista->id)
            ->lockForUpdate()
            ->first();

        if ($status === null || ! $status->disponivel) {
            throw new RuntimeException('Você precisa estar disponível para aceitar corridas.', 409);
        }

        if ($this->onlineExpirou($status)) {
            $status->update(['disponivel' => false]);
            throw new RuntimeException('Sua sessão online expirou. Conecte-se novamente.', 409);
        }

        // o veículo só é conferido depois de saber que o motorista está online:
        // offline, o motivo certo é "conecte-se", não o veículo
        $selecionado = $status->veiculo_id ?? $this->veiculoPadrao($motorista);
        app(CompartilhamentoVeiculoService::class)->validarParaCorrida($motorista, $selecionado);

        if ($status->latitude === null || $status->longitude === null) {
            throw new RuntimeException('Posição do motorista desconhecida.', 422);
        }

        $corrida->load(['corrida_destinos', 'produto', 'opcoes.produto']);
        $raios = $this->raiosDasTarifas(collect([$corrida]));

        if (! $this->estaNoRaioAtual($corrida, $status, $raios)) {
            throw new RuntimeException('Esta corrida ainda não está disponível na sua região.', 409);
        }

        $veiculo = $this->veiculoEmUso($motorista, $status);
        $opcao = $this->opcaoParaOVeiculo($corrida, $veiculo);

        if (! $this->veiculoAtendeACorrida($corrida, $veiculo, $opcao)) {
            throw new RuntimeException('Seu veículo não atende a categoria pedida nesta corrida.', 409);
        }

        return [$status, $veiculo, $opcao];
    }

    public function distanciaAteOEmbarque(Corrida $corrida, StatusBusca $status): ?float
    {
        $origem = $corrida->corrida_destinos->firstWhere('tipo', 'origem');

        if ($origem === null || $status->latitude === null || $status->longitude === null) {
            return null;
        }

        return round($this->distanciaKm(
            (float) $status->latitude,
            (float) $status->longitude,
            (float) $origem->latitude,
            (float) $origem->longitude
        ), 3);
    }

    /**
     * @var array<string, array{de: string, para: string, carimbo: list<string>}>
     */
    private const TRANSICOES = [
        'cheguei' => [
            'de' => 'aceita',
            'para' => 'motorista_chegou',
            'carimbo' => ['tempo_chegada_origem'],
        ],
        'iniciar' => [
            'de' => 'motorista_chegou',
            'para' => 'em_andamento',
            'carimbo' => ['tempo_embarque', 'tempo_inicio'],
        ],
        'finalizar' => [
            'de' => 'em_andamento',
            'para' => 'finalizada',
            'carimbo' => ['tempo_final'],
        ],
    ];

    private const CANCELAVEL_POR = [
        'passageiro' => ['aguardando_pagamento', 'solicitada', 'em_busca', 'aceita', 'motorista_chegou'],
        'motorista' => ['aceita', 'motorista_chegou'],
    ];

    public function transicionar(Motorista $motorista, int $corridaId, string $acao): Corrida
    {
        $regra = self::TRANSICOES[$acao] ?? null;

        if ($regra === null) {
            throw new RuntimeException('Ação desconhecida.', 422);
        }

        $atualizada = DB::transaction(function () use ($motorista, $corridaId, $regra) {
            $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();

            if ($corrida === null || $corrida->motorista_id !== $motorista->id) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if ($corrida->status_corrida !== $regra['de']) {
                throw new RuntimeException(
                    "A corrida está em '{$corrida->status_corrida}' e esta ação exige '{$regra['de']}'.",
                    409
                );
            }

            if ($regra['para'] === 'motorista_chegou') {
                $this->validarChegadaNoEmbarque($corrida, $motorista->id);
            }

            $mudanca = ['status_corrida' => $regra['para']];

            foreach ($regra['carimbo'] as $campo) {
                $mudanca[$campo] = now();
            }

            $corrida->update($mudanca);

            if ($regra['para'] === 'em_andamento') {
                $this->contabilizarEsperaCorridaService->contabilizar($corrida);
                $this->notificarPassageiro($corrida, 'Corrida iniciada', 'Boa viagem! Acompanhe o trajeto pelo app.');
            }

            if ($regra['para'] === 'motorista_chegou') {
                $this->notificarPassageiro($corrida, 'Seu motorista chegou', 'Encontre '.$this->carroDaCorrida($corrida).' no embarque.');
            }

            if ($regra['para'] === 'finalizada') {
                app(TaxasMotoristaService::class)->registrarFinalizada($corrida);

                $grupoSegue = $corrida->sala_compartilhada_id !== null
                    && app(GrupoCompartilhadoService::class)->motoristaAindaOcupado((int) $motorista->id);

                if (! $grupoSegue) {
                    app(CompartilhamentoVeiculoService::class)->retomarDisponibilidade((int) $motorista->id);
                }

                $this->notificarFinalizacao($corrida, $motorista->user_id, ! $grupoSegue);
            }

            Avisar::semQuebrar(new CorridaAtualizada($corrida->id, $regra['para']));

            return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']);
        });

        // fora da transação: pode chamar a AbacatePay (estorno)
        if ($atualizada->status_corrida === 'finalizada') {
            $this->pagamento->liquidarSemQuebrar($atualizada);
        }

        return $atualizada->fresh(['corrida_destinos', 'corrida_financeiro']);
    }

    /**
     * Marca a próxima parada pendente (menor ordem) como concluída. A
     * navegação do motorista e o acompanhamento do passageiro passam a
     * apontar para o ponto seguinte.
     */
    public function confirmarParada(Motorista $motorista, int $corridaId): Corrida
    {
        return DB::transaction(function () use ($motorista, $corridaId) {
            $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();

            if ($corrida === null || $corrida->motorista_id !== $motorista->id) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if ($corrida->status_corrida !== 'em_andamento') {
                throw new RuntimeException('As paradas são confirmadas durante a viagem.', 409);
            }

            $parada = $corrida->corrida_destinos()
                ->where('tipo', 'parada')
                ->whereNull('concluida_em')
                ->orderBy('ordem')
                ->first();

            if ($parada === null) {
                throw new RuntimeException('Não há parada pendente nesta corrida.', 409);
            }

            $parada->update(['concluida_em' => now()]);

            Avisar::semQuebrar(new CorridaAtualizada($corrida->id, $corrida->status_corrida));

            return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']);
        });
    }

    private function notificarFinalizacao(Corrida $corrida, int $userIdMotorista, bool $avisarMotorista = true): void
    {
        $corrida->loadMissing(['corrida_financeiro', 'passageiro']);
        $ganho = number_format((float) $corrida->corrida_financeiro?->valor_liquido_motorista, 2, ',', '.');
        $pago = number_format((float) $corrida->corrida_financeiro?->valor_pago_passageiro, 2, ',', '.');

        if ($corrida->sala_compartilhada_id !== null) {
            $total = app(GrupoCompartilhadoService::class)->resumoDaCorrida($corrida, true)['valor_total_motorista'] ?? 0;
            $ganho = number_format((float) $total, 2, ',', '.');
        }

        if ($avisarMotorista) {
            $this->notificarUsuario->executar($userIdMotorista, 'motorista', 'Corrida finalizada', $corrida->sala_compartilhada_id !== null
                ? "Viagem compartilhada concluída. Você recebe R$ {$ganho}."
                : "Você recebe R$ {$ganho} por esta corrida.");
        }

        if ($corrida->passageiro?->user_id !== null) {
            $this->notificarUsuario->executar($corrida->passageiro->user_id, 'passageiro', 'Viagem concluída', "Valor da corrida: R$ {$pago}.");
        }
    }

    public function cancelar(int $corridaId, string $quem, ?int $donoId, ?string $motivo, ?string $tipo = null, ?float $taxaConfirmada = null): Corrida
    {
        $permitidos = self::CANCELAVEL_POR[$quem] ?? [];

        $cancelada = DB::transaction(function () use ($corridaId, $quem, $donoId, $motivo, $tipo, $permitidos, $taxaConfirmada) {
            $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();

            $campo = $quem === 'motorista' ? 'motorista_id' : 'passageiro_id';

            if ($corrida === null || $donoId === null || $corrida->{$campo} !== $donoId) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if (! in_array($corrida->status_corrida, $permitidos, true)) {
                $mensagem = match ($corrida->status_corrida) {
                    'em_andamento' => 'Sua viagem já começou e não pode mais ser cancelada.',
                    'finalizada' => 'Esta corrida já foi concluída e não pode mais ser cancelada.',
                    'cancelada' => 'Esta corrida já foi cancelada.',
                    default => 'Esta corrida não pode ser cancelada neste momento.',
                };

                throw new RuntimeException($mensagem, 409);
            }

            if ($tipo !== null && $tipo !== 'nao_comparecimento') {
                throw new RuntimeException('Tipo de cancelamento inválido.', 422);
            }

            // motorista que desiste de um grupo devolve o grupo ao despacho
            if ($quem === 'motorista' && $tipo === null && $corrida->sala_compartilhada_id !== null) {
                return app(GrupoCompartilhadoService::class)->devolverAoDespacho($corrida, (int) $donoId);
            }

            if ($tipo === 'nao_comparecimento') {
                // no grupo a espera é menor: os outros passageiros estão no carro
                $espera = $corrida->sala_compartilhada_id !== null
                    ? (int) config('compartilhada.espera_embarque_segundos', 120)
                    : 180;

                if ($quem !== 'motorista' || $corrida->status_corrida !== 'motorista_chegou'
                    || $corrida->tempo_chegada_origem === null
                    || Carbon::parse($corrida->tempo_chegada_origem)->diffInSeconds(now()) < $espera) {
                    throw new RuntimeException($espera === 180
                        ? 'A taxa de não comparecimento exige três minutos de espera no embarque.'
                        : 'A taxa de não comparecimento exige dois minutos de espera no embarque.', 409);
                }

                $origem = $corrida->corrida_destinos()->where('tipo', 'origem')->first();
                $posicao = StatusBusca::where('motorista_id', $donoId)->first();

                if ($origem === null || $posicao === null || $posicao->latitude === null
                    || $posicao->longitude === null || $posicao->visto_em === null
                    || $posicao->visto_em->lt(now()->subMinutes(2))
                    || $this->distanciaKm((float) $posicao->latitude, (float) $posicao->longitude,
                        (float) $origem->latitude, (float) $origem->longitude) > 0.5) {
                    throw new RuntimeException('Você precisa estar a até 500 m do embarque para registrar a ausência.', 409);
                }

                $this->aplicarTaxaAusencia($corrida);
            }

            $tipoRegistrado = $tipo;

            if ($quem === 'passageiro') {
                $previsao = $this->previsaoCancelamentoPassageiro($corrida);

                if ($previsao['cobra']) {
                    if ($taxaConfirmada === null || $previsao['taxa'] > $taxaConfirmada + 0.01) {
                        throw new RuntimeException(
                            'O valor do cancelamento mudou. Confira o novo valor antes de confirmar.',
                            409
                        );
                    }

                    $this->aplicarTaxaCancelamento($corrida, $previsao['taxa']);
                    $tipoRegistrado = 'cancelamento_com_taxa';
                }
            }

            $motoristaId = $corrida->motorista_id;

            $corrida->update([
                'status_corrida' => 'cancelada',
                'cancelada_em' => now(),
                'cancelado_por' => $quem,
                'motivo_cancelamento' => $motivo,
                'tipo_cancelamento' => $tipoRegistrado,
            ]);

            if ($tipoRegistrado === 'nao_comparecimento') {
                app(GrupoCompartilhadoService::class)->repartirMultaComOsOutros($corrida);
            }

            // taxas: cancelar depois do aceite pesa na finalização; o passageiro
            // que cancela logo após a chamada tocar livra o motorista da recusa
            if ($quem === 'motorista' && $motoristaId !== null && $tipoRegistrado !== 'nao_comparecimento') {
                app(TaxasMotoristaService::class)->registrarCancelamentoDoMotorista($corrida, (int) $motoristaId);
            }

            if ($quem === 'passageiro') {
                app(TaxasMotoristaService::class)->desconsiderarPorCancelamentoDoPassageiro($corrida);
            }

            // no grupo o carro só pula o ponto de quem saiu e segue com os outros
            $grupoSegue = $motoristaId !== null && $corrida->sala_compartilhada_id !== null
                && app(GrupoCompartilhadoService::class)->motoristaAindaOcupado((int) $motoristaId);

            if ($motoristaId !== null && ! $grupoSegue) {
                app(CompartilhamentoVeiculoService::class)->retomarDisponibilidade((int) $motoristaId);
            }

            // quem cancelou já sabe; o outro lado fica sabendo pelo celular
            if ($quem !== 'passageiro') {
                $this->notificarPassageiro($corrida, 'Corrida cancelada', $quem === 'motorista'
                    ? 'O motorista cancelou a corrida. Você pode pedir de novo pelo app.'
                    : 'Sua corrida foi cancelada. Você pode pedir de novo pelo app.');
            }

            $motoristaUserId = $motoristaId !== null ? Motorista::whereKey($motoristaId)->value('user_id') : null;
            if ($quem !== 'motorista' && $motoristaUserId !== null) {
                $this->notificarUsuario->executar((int) $motoristaUserId, 'motorista', 'Corrida cancelada', match (true) {
                    $grupoSegue => 'Um passageiro do grupo saiu. Siga para a próxima parada.',
                    $quem === 'passageiro' => 'O passageiro cancelou a corrida. Você voltou a receber chamadas.',
                    default => 'A corrida foi cancelada. Você voltou a receber chamadas.',
                }, [
                    'corrida_id' => (string) $corrida->id,
                    'status' => 'cancelada',
                ]);
            }

            Avisar::semQuebrar(new CorridaAtualizada($corrida->id, 'cancelada'));
            Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

            return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']);
        });

        if ($cancelada->status_corrida !== 'cancelada') {
            return $cancelada;
        }

        // estorno, crédito ou pendência, fora da transação
        $this->pagamento->liquidarSemQuebrar($cancelada);

        // antes do aceite, o grupo é refeito para quem ficou
        if ($cancelada->sala_compartilhada_id !== null && $cancelada->motorista_id === null) {
            app(GrupoCompartilhadoService::class)->aoSairDoGrupo($cancelada->id);
        }

        return $cancelada->fresh(['corrida_destinos', 'corrida_financeiro']);
    }

    public function cancelarEsperasExpiradas(): int
    {
        $limiteNormal = max(1, (int) config('precificacao.espera_cancelamento_automatico_segundos', 720));
        $limiteGrupo = max(1, (int) config('compartilhada.espera_cancelamento_automatico_segundos', 300));
        $ids = Corrida::query()
            ->where('status_corrida', 'motorista_chegou')
            ->whereNotNull('tempo_chegada_origem')
            ->where(fn (Builder $vencida) => $vencida
                ->where(fn (Builder $normal) => $normal->whereNull('sala_compartilhada_id')
                    ->where('tempo_chegada_origem', '<=', now()->subSeconds($limiteNormal)))
                ->orWhere(fn (Builder $grupo) => $grupo->whereNotNull('sala_compartilhada_id')
                    ->where('tempo_chegada_origem', '<=', now()->subSeconds($limiteGrupo))))
            ->pluck('id');

        $canceladas = 0;

        foreach ($ids as $corridaId) {
            try {
                $cancelada = DB::transaction(function () use ($corridaId, $limiteNormal, $limiteGrupo) {
                    $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();
                    $limite = $corrida?->sala_compartilhada_id !== null ? $limiteGrupo : $limiteNormal;

                    if ($corrida === null || $corrida->status_corrida !== 'motorista_chegou'
                        || $corrida->tempo_chegada_origem === null
                        || Carbon::parse($corrida->tempo_chegada_origem)->gt(now()->subSeconds($limite))) {
                        return false;
                    }

                    $this->aplicarTaxaAusencia($corrida);
                    $corrida->update([
                        'status_corrida' => 'cancelada',
                        'cancelada_em' => now(),
                        'cancelado_por' => 'sistema',
                        'motivo_cancelamento' => 'Tempo máximo de espera no embarque atingido.',
                        'tipo_cancelamento' => 'nao_comparecimento',
                    ]);
                    app(GrupoCompartilhadoService::class)->repartirMultaComOsOutros($corrida);

                    $grupoSegue = $corrida->motorista_id !== null && $corrida->sala_compartilhada_id !== null
                        && app(GrupoCompartilhadoService::class)->motoristaAindaOcupado((int) $corrida->motorista_id);

                    if ($corrida->motorista_id !== null && ! $grupoSegue) {
                        app(CompartilhamentoVeiculoService::class)->retomarDisponibilidade((int) $corrida->motorista_id);
                    }

                    Avisar::semQuebrar(new CorridaAtualizada($corrida->id, 'cancelada'));
                    Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

                    return true;
                });
            } catch (RuntimeException $e) {
                Log::warning('Não foi possível cancelar automaticamente uma espera expirada.', [
                    'corrida_id' => $corridaId,
                    'motivo' => $e->getMessage(),
                ]);
                $cancelada = false;
            }

            if ($cancelada) {
                $expirada = Corrida::find((int) $corridaId);
                if ($expirada !== null) {
                    $this->pagamento->liquidarSemQuebrar($expirada);
                }
            }

            $canceladas += (int) $cancelada;
        }

        return $canceladas;
    }

    private function aplicarTaxaAusencia(Corrida $corrida): void
    {
        $financeiro = $corrida->corrida_financeiro()->first();
        if ($financeiro === null) {
            throw new RuntimeException('Dados financeiros da corrida indisponíveis.', 409);
        }

        // na compartilhada quem não aparece paga a própria parte: o motorista
        // recebe o combinado e a fatia da plataforma vira crédito para os outros
        if ($corrida->sala_compartilhada_id !== null) {
            $financeiro->update(['taxa_cancelamento' => (float) $financeiro->valor_pago_passageiro]);

            return;
        }

        $tarifaBase = (float) ($financeiro->tarifa_base ?? 0);
        if ($tarifaBase <= 0 && $corrida->tarifa_id !== null) {
            $tarifaBase = (float) Tarifa::whereKey($corrida->tarifa_id)->value('tarifa_base');
        }
        // categoria sem tarifa base (ex.: Negocia) encerra por ausência sem
        // taxa: recusar aqui deixava o motorista preso no embarque e travava
        // o cancelamento automático da espera
        $taxa = round(max(0, $tarifaBase), 2);

        $financeiro->update([
            'valor_bruto' => $taxa,
            'valor_sem_dinamica' => $taxa,
            'valor_base_calculado' => $taxa,
            'valor_pago_passageiro' => $taxa,
            'valor_motorista' => $taxa,
            'valor_liquido_motorista' => $taxa,
            'taxa_plataforma_valor' => 0,
            'taxa_plataforma_percentual' => 0,
            'taxa_espera' => 0,
            'taxa_cancelamento' => $taxa,
        ]);
    }

    /**
     * @param  array<int, array{passageiro_nota: float|null, passageiro_corridas: int}>  $reputacoes
     * @param  array<int, mixed>  $raios
     * @return non-empty-array<string, mixed>|null
     */
    private function montarOferta(Corrida $corrida, StatusBusca $status, array $reputacoes, array $raios, ?Veiculo $veiculo): ?array
    {
        $origem = $corrida->corrida_destinos->firstWhere('tipo', 'origem');

        if ($origem === null) {
            return null;
        }

        $distancia = $this->distanciaKm(
            (float) $status->latitude,
            (float) $status->longitude,
            (float) $origem->latitude,
            (float) $origem->longitude
        );

        if ($distancia > $this->raioAtualKm($corrida, $raios)) {
            return null;
        }

        $destino = $corrida->corrida_destinos->firstWhere('tipo', 'destino');
        // com várias categorias no pedido, o motorista vê o valor da dele
        $opcao = $this->opcaoParaOVeiculo($corrida, $veiculo);

        return [
            'corrida_id' => $corrida->id,
            'codigo_corrida' => $corrida->codigo_corrida,
            'distancia_ate_origem_km' => round($distancia, 2),
            'distancia_corrida_km' => (float) $corrida->distancia_total,
            'valor_motorista' => $opcao->valor_motorista ?? (float) ($corrida->corrida_financeiro->valor_motorista ?? 0),
            'categoria' => $opcao?->produto?->nome,
            'prioritaria' => (bool) ($opcao->produto->prioritario ?? $corrida->produto->prioritario ?? false),
            'metodo_pagamento' => $corrida->metodo_pagamento,
            'negociavel' => $corrida->status_negociacao === 'em_negociacao',
            'minha_proposta' => $this->minhaProposta($corrida),
            'origem' => $origem->endereco,
            'destino' => $destino?->endereco,
            'paradas' => $corrida->corrida_destinos->where('tipo', 'parada')->count(),
            // o app desenha o trajeto no mapa enquanto a chamada toca
            'trajeto' => $corrida->corrida_destinos
                ->sortBy('ordem')
                ->map(fn ($ponto) => [
                    'tipo' => $ponto->tipo,
                    'latitude' => (float) $ponto->latitude,
                    'longitude' => (float) $ponto->longitude,
                ])
                ->values()
                ->all(),
            'para_outra_pessoa' => $corrida->convidado_nome !== null,
            'solicitada_em' => $corrida->tempo_solicitacao,
            ...($reputacoes[$corrida->passageiro_id] ?? [
                'passageiro_nota' => null,
                'passageiro_corridas' => 0,
            ]),
        ];
    }

    /**
     * Nota e total de corridas são obtidos em duas consultas para todo o lote.
     *
     * @param  Collection<int, Corrida>  $corridas
     * @return array<int, array{passageiro_nota: float|null, passageiro_corridas: int}>
     */
    private function reputacoesDosPassageiros(Collection $corridas): array
    {
        $passageiroIds = $corridas->pluck('passageiro_id')->filter()->unique()->values();
        if ($passageiroIds->isEmpty()) {
            return [];
        }

        $medias = AvaliacoesCorrida::query()
            ->join('corridas', 'corridas.id', '=', 'avaliacoes_corridas.corrida_id')
            ->where('avaliacoes_corridas.tipo_usuario', 'motorista')
            ->whereIn('corridas.passageiro_id', $passageiroIds)
            ->selectRaw('corridas.passageiro_id, AVG(avaliacoes_corridas.nota) AS media')
            ->groupBy('corridas.passageiro_id')
            ->pluck('media', 'passageiro_id');

        $totais = Corrida::query()
            ->whereIn('passageiro_id', $passageiroIds)
            ->where('status_corrida', 'finalizada')
            ->selectRaw('passageiro_id, COUNT(*) AS total')
            ->groupBy('passageiro_id')
            ->pluck('total', 'passageiro_id');

        return $passageiroIds
            ->mapWithKeys(fn ($id) => [(int) $id => [
                'passageiro_nota' => Reputacao::media($medias[$id] ?? null, (int) ($totais[$id] ?? 0)),
                'passageiro_corridas' => (int) ($totais[$id] ?? 0),
            ]])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $raios
     */
    private function raioAtualKm(Corrida $corrida, array $raios): float
    {
        $raioInicial = $this->raioInicialKm($corrida, $raios);

        if ($corrida->sala_compartilhada_id !== null) {
            return app(GrupoCompartilhadoService::class)->raioKm((int) $corrida->sala_compartilhada_id, $raioInicial);
        }

        $intervalo = max(1, (int) config('precificacao.intervalo_expansao_raio_segundos'));
        $incremento = max(0.0, (float) config('precificacao.incremento_raio_busca_km'));
        $raioMaximo = max(
            $raioInicial,
            (float) config('precificacao.raio_busca_maximo_km')
        );
        $etapasConcluidas = intdiv($this->segundosEmBusca($corrida), $intervalo);

        return min($raioMaximo, $raioInicial + ($etapasConcluidas * $incremento));
    }

    /**
     * @param  array<int, mixed>  $raios
     */
    private function raioInicialKm(Corrida $corrida, array $raios): float
    {
        $raioTarifa = (float) ($raios[$corrida->tarifa_id] ?? 0);

        return $raioTarifa > 0 ? $raioTarifa : (float) config('precificacao.raio_busca_padrao_km');
    }

    private function segundosEmBusca(Corrida $corrida): int
    {
        if ($corrida->tempo_solicitacao === null) {
            return 0;
        }

        $solicitadaEm = Carbon::parse((string) $corrida->tempo_solicitacao);

        return max(0, now()->getTimestamp() - $solicitadaEm->getTimestamp());
    }

    /**
     * @param  array<int, mixed>  $raios
     */
    private function estaNoRaioAtual(Corrida $corrida, StatusBusca $status, array $raios): bool
    {
        $origem = $corrida->corrida_destinos->firstWhere('tipo', 'origem');

        if ($origem === null || $status->latitude === null || $status->longitude === null) {
            return false;
        }

        $distancia = $this->distanciaKm(
            (float) $status->latitude,
            (float) $status->longitude,
            (float) $origem->latitude,
            (float) $origem->longitude
        );

        return $distancia <= $this->raioAtualKm($corrida, $raios);
    }

    /**
     * @param  Collection<int, Corrida>  $corridas
     * @return array<int, mixed>
     */
    private function raiosDasTarifas(Collection $corridas): array
    {
        $tarifaIds = $corridas->pluck('tarifa_id')->filter()->unique();

        if ($tarifaIds->isEmpty()) {
            return [];
        }

        return Tarifa::whereIn('id', $tarifaIds)
            ->pluck('raio_busca_motorista_km', 'id')
            ->all();
    }

    /**
     * @return array{valor_motorista: float|null, status: string, expira_em: string|null}|null
     */
    private function minhaProposta(Corrida $corrida): ?array
    {
        if (! $corrida->relationLoaded('negociacoes')) {
            return null;
        }

        $proposta = $corrida->negociacoes->first();

        return $proposta === null ? null : [
            'valor_motorista' => $proposta->valor_motorista,
            'status' => $proposta->valendo() ? 'pendente' : ($proposta->status === 'pendente' ? 'expirada' : $proposta->status),
            'expira_em' => $proposta->expira_em?->toIso8601String(),
            // o app desenha a barra do tempo com o prazo inteiro, sem chutar
            'validade_segundos' => NegociacaoCorridaService::validadeDaPropostaSegundos(),
        ];
    }

    private function veiculoPadrao(Motorista $motorista): ?int
    {
        return MotoristaVeiculo::where('motorista_id', $motorista->id)
            ->value('veiculo_id');
    }

    private function veiculoEmUso(Motorista $motorista, StatusBusca $status): ?Veiculo
    {
        // o veículo marcado na disponibilidade só vale se for do motorista
        $doMotorista = $status->veiculo_id !== null && MotoristaVeiculo::where('motorista_id', $motorista->id)
            ->where('veiculo_id', $status->veiculo_id)
            ->exists();
        $veiculoId = $doMotorista ? $status->veiculo_id : $this->veiculoPadrao($motorista);

        return $veiculoId === null ? null : Veiculo::find($veiculoId);
    }

    /**
     * Só chega ao motorista a corrida pedida numa categoria que o veículo dele
     * atende (moto não recebe Pop; Elétrico só carro elétrico). Corrida sem
     * categoria vai para todos.
     *
     * @param  Builder<Corrida>  $consulta
     */
    private function queOVeiculoAtende(Builder $consulta, ?Veiculo $veiculo): void
    {
        $produtos = $veiculo === null
            ? []
            : ProdutosCorrida::atendidosPor($veiculo)->pluck('id')->all();

        $consulta
            ->whereNull('produto_id')
            ->orWhereHas('opcoes', fn (Builder $opcao) => $opcao->whereIn('produto_id', $produtos))
            ->orWhere(fn (Builder $semOpcoes) => $semOpcoes
                ->whereDoesntHave('opcoes')
                ->whereIn('produto_id', $produtos));
    }

    /**
     * Entre as categorias aceitas no pedido, a que o veículo atende. A mais
     * específica vence (um carro elétrico com Pop e Elétrico no pedido fica
     * com Elétrico); empate fica com a de maior valor para o motorista.
     */
    private function opcaoParaOVeiculo(Corrida $corrida, ?Veiculo $veiculo): ?CorridaOpcao
    {
        if ($veiculo === null) {
            return null;
        }

        return $corrida->opcoes
            ->filter(fn (CorridaOpcao $opcao) => $opcao->produto?->atendidoPor($veiculo) ?? false)
            ->sortByDesc(fn (CorridaOpcao $opcao) => [
                $opcao->produto?->requisito_veiculo !== null,
                $opcao->valor_motorista,
            ])
            ->first();
    }

    private function veiculoAtendeACorrida(Corrida $corrida, ?Veiculo $veiculo, ?CorridaOpcao $opcao): bool
    {
        if ($corrida->opcoes->isNotEmpty()) {
            return $opcao !== null;
        }

        if ($corrida->produto === null) {
            return true;
        }

        return $veiculo !== null && $corrida->produto->atendidoPor($veiculo);
    }

    private function onlineExpirou(?StatusBusca $status): bool
    {
        if ($status === null || ! $status->disponivel || $status->visto_em === null) {
            return false;
        }

        $limite = max((int) config('precificacao.motorista_online_expira_segundos', 90), 30);

        return $status->visto_em->lt(now()->subSeconds($limite));
    }

    /**
     * @return array{cobra: bool, taxa: float, km_percorridos: float, motivo: string}
     */
    public function previsaoCancelamentoPassageiro(Corrida $corrida): array
    {
        $gratis = fn (string $motivo) => [
            'cobra' => false,
            'taxa' => 0.0,
            'km_percorridos' => 0.0,
            'motivo' => $motivo,
        ];

        $status = $corrida->status_corrida;

        if (in_array($status, ['aguardando_pagamento', 'solicitada', 'em_busca'], true)) {
            return $gratis('Nenhum motorista aceitou sua corrida ainda.');
        }

        // depois que o motorista aceitou o grupo, sair tem taxa: ela cobre a
        // parte do motorista, e ninguém paga pelo cancelamento alheio
        if ($corrida->sala_compartilhada_id !== null && in_array($status, ['aceita', 'motorista_chegou'], true)) {
            $financeiro = $corrida->corrida_financeiro()->first();
            $taxa = round(min((float) ($financeiro->valor_motorista ?? 0), (float) ($financeiro->valor_pago_passageiro ?? 0)), 2);

            return $taxa <= 0 ? $gratis('Não há taxa para este cancelamento.') : [
                'cobra' => true,
                'taxa' => $taxa,
                'km_percorridos' => 0.0,
                'motivo' => 'O motorista já aceitou o grupo. A taxa cobre a parte dele na sua viagem.',
            ];
        }

        if (! in_array($status, ['aceita', 'motorista_chegou'], true)) {
            return $gratis('Esta corrida não pode ser cancelada agora.');
        }

        $distanciaAceite = (float) ($corrida->distancia_motorista_aceite_km ?? 0);

        if ($status === 'motorista_chegou') {
            $km = $distanciaAceite;
        } else {
            $carencia = max(0, (int) config('precificacao.cancelamento_passageiro_carencia_segundos', 180));

            if ($corrida->tempo_aceite !== null
                && Carbon::parse($corrida->tempo_aceite)->diffInSeconds(now()) < $carencia) {
                return $gratis('Cancelamento gratuito logo após o aceite do motorista.');
            }

            $km = $this->kmPercorridosAteEmbarque($corrida, $distanciaAceite);
            $minimo = max(0.0, (float) config('precificacao.cancelamento_passageiro_distancia_minima_km', 1.0));

            if ($km < $minimo) {
                return $gratis('O motorista ainda não avançou o bastante até você.');
            }
        }

        $taxa = $this->valorTaxaCancelamento($corrida, $km);

        if ($taxa <= 0) {
            return $gratis('Não há taxa para este cancelamento.');
        }

        return [
            'cobra' => true,
            'taxa' => $taxa,
            'km_percorridos' => round($km, 2),
            'motivo' => $status === 'motorista_chegou'
                ? 'O motorista já chegou ao ponto de embarque.'
                : 'O motorista já percorreu parte do caminho até você.',
        ];
    }

    private function kmPercorridosAteEmbarque(Corrida $corrida, float $distanciaAceite): float
    {
        if ($distanciaAceite <= 0 || $corrida->motorista_id === null) {
            return 0.0;
        }

        $origem = $corrida->corrida_destinos()->where('tipo', 'origem')->first();
        $posicao = StatusBusca::where('motorista_id', $corrida->motorista_id)->first();

        if ($origem === null || $posicao === null
            || $posicao->latitude === null || $posicao->longitude === null) {
            return 0.0;
        }

        $distanciaAtual = $this->distanciaKm(
            (float) $posicao->latitude,
            (float) $posicao->longitude,
            (float) $origem->latitude,
            (float) $origem->longitude
        );

        return max(0.0, $distanciaAceite - $distanciaAtual);
    }

    private function valorTaxaCancelamento(Corrida $corrida, float $km): float
    {
        $financeiro = $corrida->corrida_financeiro()->first();

        if ($financeiro === null) {
            return 0.0;
        }

        $valor = max(
            (float) ($financeiro->tarifa_base ?? 0),
            $km * (float) ($financeiro->valor_por_km ?? 0)
        );

        $teto = (float) ($financeiro->valor_pago_passageiro ?? 0);

        if ($teto > 0) {
            $valor = min($valor, $teto);
        }

        return round(max(0.0, $valor), 2);
    }

    private function aplicarTaxaCancelamento(Corrida $corrida, float $taxa): void
    {
        $financeiro = $corrida->corrida_financeiro()->first();

        if ($financeiro === null) {
            throw new RuntimeException('Dados financeiros da corrida indisponíveis.', 409);
        }

        $financeiro->update([
            'valor_bruto' => $taxa,
            'valor_sem_dinamica' => $taxa,
            'valor_base_calculado' => $taxa,
            'valor_pago_passageiro' => $taxa,
            'valor_motorista' => $taxa,
            'valor_liquido_motorista' => $taxa,
            'taxa_plataforma_valor' => 0,
            'taxa_plataforma_percentual' => 0,
            'taxa_espera' => 0,
            'taxa_cancelamento' => $taxa,
        ]);
    }

    private function validarChegadaNoEmbarque(Corrida $corrida, int $motoristaId): void
    {
        $origem = $corrida->corrida_destinos()->where('tipo', 'origem')->first();
        $posicao = StatusBusca::where('motorista_id', $motoristaId)->lockForUpdate()->first();
        $validadeSegundos = max(
            30,
            (int) config('precificacao.posicao_chegada_validade_segundos', 120)
        );
        $distanciaMaximaKm = max(
            0.05,
            (float) config('precificacao.distancia_maxima_chegada_km', 0.5)
        );

        if ($origem === null || $posicao === null || $posicao->latitude === null
            || $posicao->longitude === null || $posicao->visto_em === null
            || $posicao->visto_em->lt(now()->subSeconds($validadeSegundos))
            || $this->distanciaKm(
                (float) $posicao->latitude,
                (float) $posicao->longitude,
                (float) $origem->latitude,
                (float) $origem->longitude
            ) > $distanciaMaximaKm) {
            throw new RuntimeException(
                'Ative o GPS, aproxime-se do ponto de embarque e tente informar a chegada novamente.',
                409
            );
        }
    }

    private function distanciaKm(float $latA, float $lonA, float $latB, float $lonB): float
    {
        $dLat = deg2rad($latB - $latA);
        $dLon = deg2rad($lonB - $lonA);

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLon / 2) ** 2;

        return self::RAIO_TERRA_KM * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }
}
