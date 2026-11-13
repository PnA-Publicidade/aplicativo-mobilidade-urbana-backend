<?php

namespace App\Services\Compartilhada;

use App\Events\CorridaAtualizada;
use App\Events\CorridasDisponiveisAlteradas;
use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\CorridaFinanceiro;
use App\Models\CotacaoCorrida;
use App\Models\Motorista;
use App\Models\MovimentoCredito;
use App\Models\Passageiro;
use App\Models\SalaCompartilhada;
use App\Models\SalaParticipante;
use App\Models\StatusBusca;
use App\Models\Tarifa;
use App\Models\User;
use App\Models\Veiculo;
use App\Services\CompartilhamentoVeiculoService;
use App\Services\DespachoCorridaService;
use App\Services\NotificarUsuarioService;
use App\Services\PagamentoCorridaService;
use App\Services\SolicitarCorridaService;
use App\Services\TaxasMotoristaService;
use App\Support\Avisar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * O grupo depois que a sala fecha: cada passageiro tem a própria corrida
 * (pagamento, cancelamento, avaliação e histórico seguem como numa corrida
 * normal) e o motorista enxerga o grupo como uma viagem só, com uma oferta,
 * um aceite e as paradas na ordem da espinha.
 */
class GrupoCompartilhadoService
{
    public const ATIVAS = ['aguardando_pagamento', 'solicitada', 'aceita', 'motorista_chegou', 'em_andamento'];

    public function __construct(
        private readonly RotaCompartilhadaService $rotas,
        private readonly PrecoCompartilhadoService $precos,
        private readonly SalaCompartilhadaService $salas,
        private readonly PagamentoCorridaService $pagamento,
        private readonly NotificarUsuarioService $notificar,
    ) {}

    public function fechar(int $salaId): void
    {
        $sala = SalaCompartilhada::with('presentes')->find($salaId);

        if ($sala === null || ! in_array($sala->status, SalaCompartilhada::EM_FORMACAO, true) || $sala->presentes->count() < 2) {
            return;
        }

        $composicao = $sala->presentes->pluck('id')->sort()->values()->all();
        $avaliacao = $this->rotas->avaliar($sala->espinha, $this->salas->passageirosParaRota($sala->presentes));

        $corridas = DB::transaction(function () use ($salaId, $composicao, $avaliacao) {
            $sala = SalaCompartilhada::whereKey($salaId)->lockForUpdate()->first();

            if ($sala === null) {
                return null;
            }

            $vencida = ($sala->status === SalaCompartilhada::FECHANDO && $sala->fecha_em !== null && $sala->fecha_em->lte(now()))
                || ($sala->status === SalaCompartilhada::ABERTA && $sala->expira_em->lte(now()));

            if (! $vencida) {
                return null;
            }

            $presentes = $sala->presentes()->lockForUpdate()->get();

            if ($presentes->count() < 2) {
                return null;
            }

            // a sala mudou desde o cálculo da rota: fica a última rota medida
            $mesmaComposicao = $presentes->pluck('id')->sort()->values()->all() === $composicao;
            $this->salas->aplicar($sala, $presentes, $mesmaComposicao ? $avaliacao : null);

            $presentes = $sala->presentes()->get();
            $tarifa = $sala->tarifa_id === null ? null : Tarifa::find($sala->tarifa_id);
            $rota = (array) $sala->rota;
            $total = $this->precos->valorMotorista(
                $tarifa,
                ((int) ($rota['metros'] ?? 0)) / 1000,
                ((int) ($rota['segundos'] ?? 0)) / 60,
                $presentes->count()
            );
            $partes = $this->precos->repartir($total, $presentes->mapWithKeys(
                fn (SalaParticipante $participante) => [$participante->id => $participante->preco_atual]
            )->all());

            $corridas = $presentes->map(function (SalaParticipante $participante) use ($sala, $tarifa, $partes) {
                $corrida = $this->criarCorrida($sala, $participante, $partes[$participante->id], $tarifa);
                $participante->update(['status' => SalaParticipante::NO_GRUPO, 'corrida_id' => $corrida->id]);

                return $corrida;
            });

            $sala->update([
                'status' => SalaCompartilhada::FECHADA,
                'fechada_em' => now(),
                'fecha_em' => null,
                'rota' => [...$rota, 'valor_motorista' => $total],
            ]);

            return $corridas;
        });

        if ($corridas === null) {
            return;
        }

        foreach ($corridas as $corrida) {
            if ($corrida->status_corrida !== 'aguardando_pagamento') {
                continue;
            }

            try {
                app(SolicitarCorridaService::class)->gerarCobranca($corrida, $this->pagamento);
            } catch (RuntimeException $erro) {
                // sem cobrança, essa pessoa sai e o grupo segue com as outras
                Log::warning('Cobrança da compartilhada não gerada.', ['corrida_id' => $corrida->id, 'erro' => $erro->getMessage()]);
                $this->aoSairDoGrupo($corrida->id);
            }
        }

        $sala = SalaCompartilhada::findOrFail($salaId);
        $this->salas->avisar($sala);
        $this->salas->avisarParticipantes($sala, function (SalaParticipante $participante) {
            $corrida = Corrida::find($participante->corrida_id);

            if ($corrida === null || $corrida->status_corrida === 'cancelada') {
                return null;
            }

            return $corrida->status_corrida === 'aguardando_pagamento'
                ? ['Grupo fechado!', 'Pague R$ '.$this->reais((float) $participante->preco_atual).' para chamarmos o motorista.']
                : ['Grupo fechado!', 'Estamos chamando o motorista para o grupo.'];
        });

        Avisar::semQuebrar(new CorridasDisponiveisAlteradas);
    }

    private function criarCorrida(SalaCompartilhada $sala, SalaParticipante $participante, float $parteMotorista, ?Tarifa $tarifa): Corrida
    {
        $prePago = $this->pagamento->ehPrePago($participante->metodo_pagamento);
        $preco = (float) $participante->preco_atual;

        $corrida = Corrida::create([
            'codigo_corrida' => app(SolicitarCorridaService::class)->gerarCodigo(),
            'produto_id' => $sala->produto_id,
            'tarifa_id' => $sala->tarifa_id,
            'passageiro_id' => $participante->passageiro_id,
            'cidade_id' => $sala->cidade_id,
            'sala_compartilhada_id' => $sala->id,
            'status_corrida' => $prePago ? 'aguardando_pagamento' : 'solicitada',
            'tempo_solicitacao' => now(),
            'distancia_total' => $participante->solo_km,
            'valor_estimado_inicial' => $preco,
            'metodo_pagamento' => $participante->metodo_pagamento,
            'status_pagamento' => 'pendente',
        ]);

        foreach (['origem' => 'embarque', 'destino' => 'desembarque'] as $tipo => $ponto) {
            CorridaDestino::create([
                'corrida_id' => $corrida->id,
                'nome_local' => $participante->{$ponto.'_endereco'},
                'tipo' => $tipo,
                'ordem' => $tipo === 'origem' ? 0 : 1,
                'endereco' => $participante->{$ponto.'_endereco'},
                'latitude' => $participante->{$ponto.'_latitude'},
                'longitude' => $participante->{$ponto.'_longitude'},
            ]);
        }

        CorridaFinanceiro::create([
            'corrida_id' => $corrida->id,
            ...$this->valoresFinanceiros($preco, $parteMotorista),
            'tarifa_base' => (float) ($tarifa->tarifa_base ?? 0),
            'valor_por_km' => (float) ($tarifa->valor_por_km ?? 0),
            'valor_por_minuto' => (float) ($tarifa->valor_por_minuto ?? 0),
            'valor_por_minuto_espera' => (float) ($tarifa->valor_por_minuto_espera ?? 0),
            'taxa_espera' => 0,
            'valor_ajuste_negociado' => 0,
            'metodo_pagamento' => $participante->metodo_pagamento,
        ]);

        if ($prePago && $preco - $this->pagamento->aplicarCredito($corrida, $preco) <= 0.009) {
            $corrida->update(['status_corrida' => 'solicitada', 'status_pagamento' => 'pago']);
        }

        return $corrida;
    }

    /**
     * @return array<string, float>
     */
    private function valoresFinanceiros(float $preco, float $parteMotorista): array
    {
        $taxa = round($preco - $parteMotorista, 2);

        return [
            'valor_bruto' => $parteMotorista,
            'valor_sem_dinamica' => $parteMotorista,
            'valor_base_calculado' => $parteMotorista,
            'valor_pago_passageiro' => $preco,
            'taxa_plataforma_valor' => $taxa,
            'taxa_plataforma_percentual' => $preco > 0 ? round($taxa / $preco * 100, 2) : 0.0,
            'valor_motorista' => $parteMotorista,
            'valor_liquido_motorista' => $parteMotorista,
        ];
    }

    /**
     * Corridas do grupo que ainda contam, na ordem de embarque.
     *
     * @return Collection<int, Corrida>
     */
    public function corridasAtivas(int $salaId): Collection
    {
        $ordem = SalaParticipante::where('sala_id', $salaId)->pluck('ordem_embarque', 'corrida_id');

        return Corrida::where('sala_compartilhada_id', $salaId)
            ->whereIn('status_corrida', self::ATIVAS)
            ->with(['corrida_destinos', 'corrida_financeiro'])
            ->get()
            ->sortBy(fn (Corrida $corrida) => $ordem[$corrida->id] ?? 99)
            ->values();
    }

    /**
     * Paradas da viagem do grupo na ordem da espinha, com o que já foi feito.
     *
     * @return list<array<string, mixed>>
     */
    public function paradas(int $salaId): array
    {
        $participantes = SalaParticipante::where('sala_id', $salaId)
            ->where('status', SalaParticipante::NO_GRUPO)
            ->with(['corrida.corrida_financeiro', 'passageiro.user'])
            ->get();

        $paradas = [];

        foreach ($participantes as $participante) {
            $corrida = $participante->corrida;

            if ($corrida === null || $participante->ordem_embarque === null || $participante->ordem_desembarque === null) {
                continue;
            }

            $status = $corrida->status_corrida;
            $cancelada = $status === 'cancelada';
            $nome = $corrida->convidado_nome ?? (string) $participante->passageiro?->user?->name;

            foreach (['embarque', 'desembarque'] as $acao) {
                $paradas[] = [
                    'corrida_id' => $corrida->id,
                    'acao' => $acao,
                    'ordem' => $acao === 'embarque' ? $participante->ordem_embarque : $participante->ordem_desembarque,
                    'passageiro' => preg_split('/\s+/u', trim($nome), 2)[0] ?? '',
                    'endereco' => $participante->{$acao.'_endereco'},
                    'latitude' => (float) $participante->{$acao.'_latitude'},
                    'longitude' => (float) $participante->{$acao.'_longitude'},
                    'metodo_pagamento' => $corrida->metodo_pagamento,
                    // em dinheiro, o motorista cobra quando a pessoa desce
                    'valor_passageiro' => round((float) ($corrida->corrida_financeiro->valor_pago_passageiro ?? 0), 2),
                    'status_corrida' => $status,
                    'cancelada' => $cancelada,
                    'feita' => $cancelada || $status === 'finalizada'
                        || ($acao === 'embarque' && $status === 'em_andamento'),
                ];
            }
        }

        usort($paradas, fn (array $a, array $b) => $a['ordem'] <=> $b['ordem']);

        return $paradas;
    }

    /**
     * A corrida da próxima parada pendente: é nela que o motorista age
     * (cheguei, embarcou, finalizar), como numa corrida normal.
     */
    public function corridaDaVez(int $salaId): ?Corrida
    {
        foreach ($this->paradas($salaId) as $parada) {
            if (! $parada['feita']) {
                return Corrida::find((int) $parada['corrida_id']);
            }
        }

        return null;
    }

    /**
     * Grupo pronto para o despacho (ninguém pagando) e desde quando.
     */
    public function despachoDesde(int $salaId): ?Carbon
    {
        $corridas = Corrida::where('sala_compartilhada_id', $salaId)->whereIn('status_corrida', self::ATIVAS)->get();

        if ($corridas->isEmpty() || $corridas->contains('status_corrida', 'aguardando_pagamento')) {
            return null;
        }

        $ultima = $corridas->max('tempo_solicitacao');

        return $ultima === null ? null : Carbon::parse($ultima);
    }

    /**
     * Degrau da escada de despacho: raio ampliado e bônus ao motorista
     * conforme o grupo espera.
     *
     * @return array{raio_km: float|null, bonus: float, segundos: int}
     */
    public function degrau(int $salaId): array
    {
        $desde = $this->despachoDesde($salaId);
        $segundos = $desde === null ? 0 : max(0, now()->getTimestamp() - $desde->getTimestamp());
        $degrau = ['raio_km' => null, 'bonus' => 0.0, 'segundos' => $segundos];

        foreach ((array) config('compartilhada.escada', []) as $etapa) {
            if ($segundos >= (int) $etapa['apos_segundos']) {
                $degrau = ['raio_km' => (float) $etapa['raio_km'], 'bonus' => (float) $etapa['bonus'], 'segundos' => $segundos];
            }
        }

        return $degrau;
    }

    /**
     * Uma oferta por grupo, montada sobre a corrida de quem embarca primeiro.
     *
     * @param  Collection<int, Corrida>  $corridas  as corridas do grupo à espera de motorista
     * @param  array<int, array{passageiro_nota: float|null, passageiro_corridas: int}>  $reputacoes
     * @return non-empty-array<string, mixed>|null
     */
    public function oferta(int $salaId, Collection $corridas, StatusBusca $status, Motorista $motorista, ?Veiculo $veiculo, array $reputacoes, float $raioTarifa): ?array
    {
        $ativas = $this->corridasAtivas($salaId);

        if ($ativas->isEmpty() || $ativas->contains('status_corrida', 'aguardando_pagamento')
            || $ativas->count() !== $corridas->count()
            || ! $motorista->aceita_compartilhada
            || $this->suspenso($motorista)
            || ($veiculo !== null && (int) ($veiculo->assentos ?? 4) < $ativas->count())) {
            return null;
        }

        $lider = $ativas->first();
        $origem = $lider->corrida_destinos->firstWhere('tipo', 'origem');

        if ($origem === null || $status->latitude === null || $status->longitude === null) {
            return null;
        }

        $distancia = $this->rotas->distanciaKm(
            ['latitude' => (float) $status->latitude, 'longitude' => (float) $status->longitude],
            ['latitude' => (float) $origem->latitude, 'longitude' => (float) $origem->longitude]
        );
        $degrau = $this->degrau($salaId);

        if ($distancia > max($raioTarifa, $degrau['raio_km'] ?? 0)) {
            return null;
        }

        $paradas = array_values(array_filter($this->paradas($salaId), fn (array $parada) => ! $parada['cancelada']));
        $ultima = count($paradas) - 1;
        $valor = $this->valorDoGrupo($ativas, $degrau['bonus']);
        $sala = SalaCompartilhada::find($salaId);
        $metodos = $ativas->pluck('metodo_pagamento')->unique()->values();

        return [
            'corrida_id' => $lider->id,
            'codigo_corrida' => $lider->codigo_corrida,
            'distancia_ate_origem_km' => round($distancia, 2),
            'distancia_corrida_km' => round(((int) ($sala?->rota['metros'] ?? 0)) / 1000, 2),
            'valor_motorista' => $valor,
            'categoria' => 'Compartilhada',
            'prioritaria' => false,
            'metodo_pagamento' => $metodos->count() === 1 ? $metodos->first() : 'varios',
            'negociavel' => false,
            'minha_proposta' => null,
            'origem' => $origem->endereco,
            'destino' => $paradas[$ultima]['endereco'] ?? null,
            'paradas' => max(0, count($paradas) - 2),
            'trajeto' => array_map(fn (array $parada, int $indice) => [
                'tipo' => $indice === 0 ? 'origem' : ($indice === $ultima ? 'destino' : 'parada'),
                'latitude' => $parada['latitude'],
                'longitude' => $parada['longitude'],
            ], $paradas, array_keys($paradas)),
            'para_outra_pessoa' => false,
            'solicitada_em' => $lider->tempo_solicitacao,
            ...($reputacoes[$lider->passageiro_id] ?? ['passageiro_nota' => null, 'passageiro_corridas' => 0]),
            // o card mostra que vale mais: "R$ 34,80 · 3 embarques · compartilhada"
            'compartilhada' => [
                'passageiros' => $ativas->count(),
                'embarques' => $ativas->count(),
                'bonus_percentual' => (int) round($degrau['bonus'] * 100),
                'pagamentos' => $ativas->pluck('metodo_pagamento')->all(),
            ],
        ];
    }

    /**
     * @param  Collection<int, Corrida>  $corridas
     */
    private function valorDoGrupo(Collection $corridas, float $bonus): float
    {
        $soma = (float) $corridas->sum(fn (Corrida $corrida) => (float) ($corrida->corrida_financeiro->valor_motorista ?? 0));

        return round($soma * (1 + $bonus), 2);
    }

    /**
     * Raio de busca do grupo, para a validação do aceite seguir a mesma escada da oferta.
     */
    public function raioKm(int $salaId, float $raioTarifa): float
    {
        return max($raioTarifa, $this->degrau($salaId)['raio_km'] ?? 0);
    }

    /**
     * Aceite de um grupo: o motorista fica com todas as corridas de uma vez.
     * Chamar dentro da transação do despacho, com a corrida líder travada.
     */
    public function aceitar(DespachoCorridaService $despacho, Motorista $motorista, Corrida $lider): Corrida
    {
        $salaId = (int) $lider->sala_compartilhada_id;
        $corridas = Corrida::where('sala_compartilhada_id', $salaId)
            ->whereIn('status_corrida', self::ATIVAS)
            ->lockForUpdate()
            ->get();

        if ($corridas->contains('status_corrida', 'aguardando_pagamento')) {
            throw new RuntimeException('O grupo ainda está pagando. Aguarde a próxima chamada.', 409);
        }

        if ($corridas->contains(fn (Corrida $corrida) => $corrida->motorista_id !== null || $corrida->status_corrida !== 'solicitada')) {
            throw new RuntimeException('Esta corrida já foi aceita por outro motorista.', 409);
        }

        if (! $motorista->aceita_compartilhada) {
            throw new RuntimeException('Ative "aceito compartilhadas" para pegar esta corrida.', 409);
        }

        if ($this->suspenso($motorista)) {
            throw new RuntimeException('Suas compartilhadas voltam '.$this->quando($motorista->compartilhada_suspensa_ate).'.', 409);
        }

        [$status, $veiculo] = $despacho->validarMotoristaParaCorrida($motorista, $lider);

        if ($veiculo !== null && (int) ($veiculo->assentos ?? 4) < $corridas->count()) {
            throw new RuntimeException('Seu veículo não tem lugares para este grupo.', 409);
        }

        $this->repartirValorDoMotorista($salaId, $corridas, $this->degrau($salaId)['bonus']);
        $corridas->load('corrida_destinos');

        foreach ($corridas as $corrida) {
            $corrida->update([
                'motorista_id' => $motorista->id,
                'veiculo_id' => $veiculo?->id,
                'status_corrida' => 'aceita',
                'tempo_aceite' => now(),
                'distancia_motorista_aceite_km' => $despacho->distanciaAteOEmbarque($corrida, $status),
            ]);
        }

        StatusBusca::where('motorista_id', $motorista->id)->update(['disponivel' => false, 'visto_em' => now()]);
        $despacho->cancelarPropostasPendentes($motorista->id, $lider->id);
        app(TaxasMotoristaService::class)->registrarAceite($lider, $motorista->id);

        foreach ($corridas as $corrida) {
            $antes = $this->quantosEmbarcamAntes($corrida);
            $this->notificarPassageiro($corrida, 'Motorista a caminho', $antes > 0
                ? "O motorista aceitou o grupo e vai buscar {$antes} ".($antes === 1 ? 'pessoa' : 'pessoas').' antes de você.'
                : 'O motorista aceitou o grupo e está indo até você.');
            Avisar::semQuebrar(new CorridaAtualizada($corrida->id, 'aceita'));
        }

        Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

        return $lider->fresh(['corrida_destinos', 'corrida_financeiro']);
    }

    /**
     * Reparte de novo o valor do grupo (com o bônus da escada, que sai da
     * parte da plataforma, nunca do passageiro). Parte sempre do valor base da
     * sala, então aceitar de novo depois de uma desistência não acumula bônus.
     *
     * @param  Collection<int, Corrida>  $corridas
     */
    private function repartirValorDoMotorista(int $salaId, Collection $corridas, float $bonus): void
    {
        $base = (float) (SalaCompartilhada::find($salaId)?->rota['valor_motorista'] ?? 0);
        $precos = $corridas->mapWithKeys(fn (Corrida $corrida) => [
            $corrida->id => (float) (CorridaFinanceiro::where('corrida_id', $corrida->id)->value('valor_pago_passageiro') ?? 0),
        ])->all();

        if ($base <= 0 || $precos === []) {
            return;
        }

        foreach ($this->precos->repartir(round($base * (1 + $bonus), 2), $precos) as $corridaId => $parte) {
            CorridaFinanceiro::where('corrida_id', $corridaId)->update($this->valoresFinanceiros($precos[$corridaId], $parte));
        }
    }

    /**
     * O motorista desistiu do grupo antes de alguém embarcar: o grupo volta
     * ao despacho no degrau da escada em que estava, com os preços travados.
     * Chamar dentro da transação do cancelamento.
     */
    public function devolverAoDespacho(Corrida $corrida, int $motoristaId): Corrida
    {
        if ($corrida->motorista_id !== $motoristaId) {
            throw new RuntimeException('Corrida não encontrada.', 404);
        }

        $salaId = (int) $corrida->sala_compartilhada_id;
        $corridas = Corrida::where('sala_compartilhada_id', $salaId)
            ->where('motorista_id', $motoristaId)
            ->whereIn('status_corrida', ['aceita', 'motorista_chegou', 'em_andamento'])
            ->lockForUpdate()
            ->get();

        if ($corridas->contains('status_corrida', 'em_andamento')) {
            throw new RuntimeException('Há passageiros do grupo no carro. Termine a viagem com eles.', 409);
        }

        foreach ($corridas as $doGrupo) {
            // o tempo com este motorista não conta na escada
            $segurou = $doGrupo->tempo_aceite === null ? 0 : max(0, now()->getTimestamp() - Carbon::parse($doGrupo->tempo_aceite)->getTimestamp());
            $doGrupo->update([
                'status_corrida' => 'solicitada',
                'motorista_id' => null,
                'veiculo_id' => null,
                'tempo_aceite' => null,
                'tempo_chegada_origem' => null,
                'distancia_motorista_aceite_km' => null,
                'tempo_solicitacao' => $doGrupo->tempo_solicitacao === null ? now() : Carbon::parse($doGrupo->tempo_solicitacao)->addSeconds($segurou),
            ]);
        }

        $this->repartirValorDoMotorista($salaId, $corridas, 0.0);

        // desistir depois de aceitar pesa na taxa de finalização, uma vez por desistência
        app(TaxasMotoristaService::class)->registrarDesistenciaDeGrupo($corrida->id, $motoristaId);

        // e suspende as chamadas de grupo dele por um tempo; as normais seguem
        $suspensaAte = now()->addHours((int) config('compartilhada.suspensao_por_desistencia_horas', 24));
        Motorista::whereKey($motoristaId)->update(['compartilhada_suspensa_ate' => $suspensaAte]);
        $motoristaUserId = Motorista::whereKey($motoristaId)->value('user_id');
        if ($motoristaUserId !== null) {
            $this->notificar->executar((int) $motoristaUserId, 'motorista', 'Compartilhadas suspensas',
                'Você desistiu de um grupo. Volta a receber compartilhadas '.$this->quando($suspensaAte).'.');
        }

        app(CompartilhamentoVeiculoService::class)->retomarDisponibilidade($motoristaId);

        foreach ($corridas as $doGrupo) {
            $this->notificarPassageiro($doGrupo, 'O motorista desistiu', 'Já estamos chamando outro motorista para o grupo. Seu valor não muda.');
            Avisar::semQuebrar(new CorridaAtualizada($doGrupo->id, 'solicitada'));
        }

        Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

        return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']) ?? $corrida;
    }

    public function suspenso(Motorista $motorista): bool
    {
        return $motorista->compartilhada_suspensa_ate !== null && $motorista->compartilhada_suspensa_ate->isFuture();
    }

    /**
     * "amanhã às 15:20", no fuso de quem lê o aviso.
     */
    public function quando(?Carbon $momento): string
    {
        if ($momento === null) {
            return 'em breve';
        }

        $local = $momento->copy()->setTimezone((string) config('compartilhada.fuso_para_avisos', 'America/Porto_Velho'));
        $hoje = now()->setTimezone($local->getTimezone());
        $dia = match (true) {
            $local->isSameDay($hoje) => 'hoje',
            $local->isSameDay($hoje->copy()->addDay()) => 'amanhã',
            default => 'em '.$local->format('d/m'),
        };

        return "{$dia} às ".$local->format('H:i');
    }

    /**
     * Paradas do grupo que ainda faltam antes de uma parada desta corrida: o
     * tempo até o passageiro passa por elas.
     *
     * @param  'embarque'|'desembarque'  $acao
     * @return list<array{latitude: float, longitude: float, endereco: string}>
     */
    public function paradasAntes(Corrida $corrida, string $acao): array
    {
        if ($corrida->sala_compartilhada_id === null) {
            return [];
        }

        $antes = [];

        foreach ($this->paradas((int) $corrida->sala_compartilhada_id) as $parada) {
            if ($parada['corrida_id'] === $corrida->id && $parada['acao'] === $acao) {
                return $antes;
            }

            if (! $parada['feita']) {
                $antes[] = ['latitude' => $parada['latitude'], 'longitude' => $parada['longitude'], 'endereco' => (string) $parada['endereco']];
            }
        }

        return $antes;
    }

    public function quantosEmbarcamAntes(Corrida $corrida): int
    {
        if ($corrida->sala_compartilhada_id === null) {
            return 0;
        }

        $antes = 0;

        foreach ($this->paradas((int) $corrida->sala_compartilhada_id) as $parada) {
            if ($parada['corrida_id'] === $corrida->id) {
                return $antes;
            }

            if ($parada['acao'] === 'embarque' && ! $parada['feita']) {
                $antes++;
            }
        }

        return $antes;
    }

    /**
     * O grupo para quem está numa das corridas.
     *
     * @return array<string, mixed>|null
     */
    public function resumoDaCorrida(Corrida $corrida, bool $paraMotorista): ?array
    {
        if ($corrida->sala_compartilhada_id === null) {
            return null;
        }

        $salaId = (int) $corrida->sala_compartilhada_id;
        $paradas = $this->paradas($salaId);
        $ativas = collect($paradas)->where('cancelada', false)->pluck('corrida_id')->unique();
        $degrau = $this->degrau($salaId);
        $desde = $this->despachoDesde($salaId);
        $semMotorista = $corrida->status_corrida === 'solicitada' && $corrida->motorista_id === null;

        $resumo = [
            'sala_id' => $salaId,
            'passageiros' => $ativas->count(),
            'embarcam_antes_de_voce' => $this->quantosEmbarcamAntes($corrida),
            'aguardando_pagamento_do_grupo' => Corrida::where('sala_compartilhada_id', $salaId)->where('status_corrida', 'aguardando_pagamento')->count(),
            'buscando_ha_segundos' => $semMotorista && $desde !== null ? $degrau['segundos'] : null,
            // depois de 1 min sem motorista, cada um pode seguir sozinho
            'pode_seguir_sozinho' => $semMotorista && $desde !== null
                && $degrau['segundos'] >= (int) config('compartilhada.oferecer_sozinho_apos_segundos', 60),
            'preco_sozinho' => $this->precoSolo($corrida),
        ];

        if (! $paraMotorista) {
            return $resumo;
        }

        return [
            ...$resumo,
            'paradas' => $paradas,
            'valor_total_motorista' => round((float) Corrida::where('sala_compartilhada_id', $salaId)
                ->where('motorista_id', $corrida->motorista_id)
                ->with('corrida_financeiro')
                ->get()
                ->sum(fn (Corrida $outra) => $outra->status_corrida === 'cancelada'
                    ? (float) ($outra->corrida_financeiro->taxa_cancelamento ?? 0)
                    : (float) ($outra->corrida_financeiro->valor_motorista ?? 0)), 2),
            'espera_embarque_segundos' => (int) config('compartilhada.espera_embarque_segundos', 120),
        ];
    }

    public function motoristaAindaOcupado(int $motoristaId): bool
    {
        return Corrida::where('motorista_id', $motoristaId)
            ->whereIn('status_corrida', ['aguardando_pagamento', 'aceita', 'motorista_chegou', 'em_andamento'])
            ->exists();
    }

    /**
     * Alguém do grupo saiu (cancelou, não pagou, não apareceu). Antes do
     * aceite, a rota e a parte do motorista são refeitas para quem ficou; com
     * uma pessoa só, ela segue como corrida normal. Depois do aceite a rota não
     * muda: o carro só pula o ponto de quem saiu.
     */
    public function aoSairDoGrupo(int $corridaId): void
    {
        $corrida = Corrida::find($corridaId);

        if ($corrida === null || $corrida->sala_compartilhada_id === null || $corrida->status_corrida !== 'cancelada') {
            return;
        }

        $salaId = (int) $corrida->sala_compartilhada_id;
        $restantes = $this->corridasAtivas($salaId);

        if ($restantes->isEmpty() || $restantes->contains(fn (Corrida $outra) => $outra->motorista_id !== null)) {
            return;
        }

        if ($restantes->count() === 1) {
            $this->seguirComoNormal($restantes->first(), manterPreco: true);

            return;
        }

        $this->refazerParteDoMotorista($salaId, $restantes);
    }

    /**
     * @param  Collection<int, Corrida>  $restantes
     */
    private function refazerParteDoMotorista(int $salaId, Collection $restantes): void
    {
        $sala = SalaCompartilhada::find($salaId);
        $participantes = SalaParticipante::where('sala_id', $salaId)
            ->whereIn('corrida_id', $restantes->pluck('id'))
            ->get();

        if ($sala === null || $participantes->isEmpty()) {
            return;
        }

        $avaliacao = $this->rotas->avaliar($sala->espinha, $this->salas->passageirosParaRota($participantes));
        $rota = $avaliacao === null ? (array) $sala->rota : [
            ...(array) $sala->rota,
            'segundos' => $avaliacao['segundos'],
            'metros' => $avaliacao['metros'],
            'no_carro' => $avaliacao['no_carro'],
        ];
        $tarifa = $sala->tarifa_id === null ? null : Tarifa::find($sala->tarifa_id);
        $total = $this->precos->valorMotorista($tarifa, ((int) ($rota['metros'] ?? 0)) / 1000, ((int) ($rota['segundos'] ?? 0)) / 60, $restantes->count());
        $precos = $restantes->mapWithKeys(fn (Corrida $corrida) => [$corrida->id => (float) ($corrida->corrida_financeiro->valor_pago_passageiro ?? 0)])->all();

        DB::transaction(function () use ($sala, $rota, $total, $precos) {
            foreach ($this->precos->repartir($total, $precos) as $corridaId => $parte) {
                CorridaFinanceiro::where('corrida_id', $corridaId)->update($this->valoresFinanceiros($precos[$corridaId], $parte));
            }

            $sala->update(['rota' => [...$rota, 'valor_motorista' => $total]]);
        });

        Avisar::semQuebrar(new CorridasDisponiveisAlteradas);
    }

    /**
     * Tira a corrida do grupo e a deixa na fila normal. Ao dissolver (ou ao
     * escolher seguir sozinho) o preço volta ao solo; quem sobrou sozinho
     * porque os outros saíram mantém o preço que já tinha.
     */
    public function seguirComoNormal(Corrida $corrida, bool $manterPreco): Corrida
    {
        $solo = $this->categoriaSolo($corrida);

        DB::transaction(function () use ($corrida, $solo, $manterPreco) {
            $travada = Corrida::whereKey($corrida->id)->lockForUpdate()->first();

            if ($travada === null || $travada->motorista_id !== null || ! in_array($travada->status_corrida, ['solicitada', 'aguardando_pagamento'], true)) {
                return;
            }

            if ($solo !== null) {
                $preco = $manterPreco
                    ? (float) (CorridaFinanceiro::where('corrida_id', $travada->id)->value('valor_pago_passageiro') ?? 0)
                    : (float) $solo['valores']['valor_passageiro'];
                CorridaFinanceiro::where('corrida_id', $travada->id)->update([
                    ...SolicitarCorridaService::valoresDaCategoria($solo),
                    ...$this->valoresFinanceiros($preco, min($preco, (float) $solo['valores']['valor_motorista'])),
                ]);
                $travada->update(['valor_estimado_inicial' => $preco]);
            }

            // a busca recomeça do raio normal, como um pedido novo
            $travada->update(['sala_compartilhada_id' => null, 'tempo_solicitacao' => now()]);
        });

        Avisar::semQuebrar(new CorridaAtualizada($corrida->id, (string) $corrida->fresh()?->status_corrida));
        Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

        return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']) ?? $corrida;
    }

    /**
     * Passageiro que, depois de 1 min sem motorista, prefere seguir sozinho.
     */
    public function seguirSozinho(User $usuario, int $corridaId): Corrida
    {
        $passageiroId = Passageiro::where('user_id', $usuario->id)->value('id');
        $corrida = Corrida::whereKey($corridaId)->where('passageiro_id', $passageiroId)->first();

        if ($corrida === null || $corrida->sala_compartilhada_id === null) {
            throw new RuntimeException('Corrida não encontrada.', 404);
        }

        $resumo = $this->resumoDaCorrida($corrida, false);

        if (! ($resumo['pode_seguir_sozinho'] ?? false)) {
            throw new RuntimeException('Ainda estamos procurando motorista para o grupo.', 409);
        }

        $salaId = (int) $corrida->sala_compartilhada_id;
        $normal = $this->seguirComoNormal($corrida, manterPreco: false);
        $restantes = $this->corridasAtivas($salaId);

        if ($restantes->count() === 1) {
            $this->seguirComoNormal($restantes->first(), manterPreco: true);
        } elseif ($restantes->count() > 1) {
            $this->refazerParteDoMotorista($salaId, $restantes);
        }

        return $normal;
    }

    /**
     * Fim da escada: ninguém aceitou o grupo. Cada um sai já com uma corrida
     * normal na fila, pelo preço solo, nunca de volta à tela em branco.
     */
    public function dissolverSemMotorista(): int
    {
        $limite = (int) config('compartilhada.dissolver_apos_segundos', 120);
        $salas = Corrida::whereNotNull('sala_compartilhada_id')
            ->where('status_corrida', 'solicitada')
            ->whereNull('motorista_id')
            ->distinct()
            ->pluck('sala_compartilhada_id');
        $dissolvidas = 0;

        foreach ($salas as $salaId) {
            $desde = $this->despachoDesde((int) $salaId);

            if ($desde === null || $desde->gt(now()->subSeconds($limite))) {
                continue;
            }

            $corridas = $this->corridasAtivas((int) $salaId);

            if ($corridas->contains(fn (Corrida $corrida) => $corrida->motorista_id !== null)) {
                continue;
            }

            foreach ($corridas as $corrida) {
                $normal = $this->seguirComoNormal($corrida, manterPreco: false);
                $this->notificarPassageiro($normal, 'Não achamos carro para a compartilhada',
                    'Você já está na fila normal por R$ '.$this->reais((float) ($normal->corrida_financeiro->valor_pago_passageiro ?? 0)).'. Se não quiser, cancele sem custo.');
            }

            SalaCompartilhada::whereKey($salaId)->update(['status' => SalaCompartilhada::DISSOLVIDA]);
            $dissolvidas++;
        }

        return $dissolvidas;
    }

    /**
     * Quem não apareceu paga a própria parte; a fatia da plataforma nessa
     * multa vira crédito para quem esperou no carro.
     */
    public function repartirMultaComOsOutros(Corrida $faltou): void
    {
        if ($faltou->sala_compartilhada_id === null) {
            return;
        }

        $financeiro = CorridaFinanceiro::where('corrida_id', $faltou->id)->first();
        $fatia = round((float) ($financeiro->valor_pago_passageiro ?? 0) - (float) ($financeiro->valor_motorista ?? 0), 2);
        $outros = Corrida::where('sala_compartilhada_id', $faltou->sala_compartilhada_id)
            ->whereKeyNot($faltou->id)
            ->whereIn('status_corrida', ['aceita', 'motorista_chegou', 'em_andamento'])
            ->get();

        if ($fatia <= 0 || $outros->isEmpty()) {
            return;
        }

        $parte = floor($fatia / $outros->count() * 100) / 100;

        foreach ($outros as $outra) {
            MovimentoCredito::create([
                'passageiro_id' => $outra->passageiro_id,
                'corrida_id' => $outra->id,
                'valor' => $parte,
                'descricao' => 'Desconto: uma pessoa do grupo não apareceu',
            ]);
            $this->notificarPassageiro($outra, 'Uma pessoa não apareceu',
                'Você ganhou R$ '.$this->reais($parte).' de crédito pela espera.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function categoriaSolo(Corrida $corrida): ?array
    {
        $cotacaoId = SalaParticipante::where('corrida_id', $corrida->id)->value('cotacao_id');
        $cotacao = $cotacaoId === null ? null : CotacaoCorrida::find((string) $cotacaoId);

        return $cotacao?->categoria((string) config('compartilhada.produto'));
    }

    private function precoSolo(Corrida $corrida): ?float
    {
        $categoria = $this->categoriaSolo($corrida);

        return $categoria === null ? null : (float) $categoria['valores']['valor_passageiro'];
    }

    private function notificarPassageiro(Corrida $corrida, string $titulo, string $mensagem): void
    {
        $userId = Passageiro::whereKey($corrida->passageiro_id)->value('user_id');

        if ($userId !== null) {
            $this->notificar->executar((int) $userId, 'passageiro', $titulo, $mensagem, [
                'corrida_id' => (string) $corrida->id,
                'status' => (string) $corrida->status_corrida,
            ]);
        }
    }

    private function reais(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}
