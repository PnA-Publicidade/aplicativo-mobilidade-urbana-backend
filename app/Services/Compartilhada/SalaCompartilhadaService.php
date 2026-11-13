<?php

namespace App\Services\Compartilhada;

use App\Events\SalaCompartilhadaAtualizada;
use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\CotacaoCorrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\SalaCompartilhada;
use App\Models\SalaParticipante;
use App\Models\StatusBusca;
use App\Models\Tarifa;
use App\Services\EnderecoPorCoordenadaService;
use App\Services\NotificarUsuarioService;
use App\Services\PagamentoCorridaService;
use App\Services\SolicitarCorridaService;
use App\Support\Avisar;
use App\Support\Reputacao;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A sala da viagem compartilhada, antes de existir corrida: quem abre espera
 * até 10 min, quem pede uma corrida normal compatível recebe a oferta de
 * entrar, o preço de cada um cai a cada entrada e o grupo fecha quando todos
 * votam "pronto" (com 10 s para voltar atrás) ou quando o tempo acaba.
 */
class SalaCompartilhadaService
{
    public function __construct(
        private readonly RotaCompartilhadaService $rotas,
        private readonly PrecoCompartilhadoService $precos,
        private readonly NotificarUsuarioService $notificar,
        private readonly PagamentoCorridaService $pagamento,
        private readonly EnderecoPorCoordenadaService $enderecoPorCoordenada,
    ) {}

    public function participacaoAtual(int $passageiroId): ?SalaParticipante
    {
        return SalaParticipante::where('passageiro_id', $passageiroId)
            ->where('status', SalaParticipante::NA_SALA)
            ->whereHas('sala', fn ($sala) => $sala->whereIn('status', [...SalaCompartilhada::EM_FORMACAO, SalaCompartilhada::EXPIRADA]))
            ->with('sala')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Se o passageiro pode abrir sala com esta cotação e quanto pagaria.
     *
     * @return array{disponivel: bool, motivo: string|null, preco_solo: float|null, precos_quem_abre: array<int, float>, sala_minutos: int, capacidade: int, produto_codigo: string, exibir: bool}
     */
    public function disponibilidade(Passageiro $passageiro, CotacaoCorrida $cotacao): array
    {
        $capacidade = (int) config('compartilhada.capacidade', 4);
        $resposta = [
            'disponivel' => false,
            'motivo' => null,
            'preco_solo' => null,
            'precos_quem_abre' => [],
            'sala_minutos' => (int) config('compartilhada.sala_minutos', 10),
            'capacidade' => $capacidade,
            // categoria cujo pedido normal recebe a oferta de entrar numa sala
            'produto_codigo' => (string) config('compartilhada.produto'),
            // a linha só aparece quando a corrida pode ser compartilhada;
            // sem requisito cumprido, aparece com o motivo
            'exibir' => false,
        ];
        $categoria = $cotacao->categoria((string) config('compartilhada.produto'));

        if ($categoria === null) {
            return [...$resposta, 'motivo' => 'A compartilhada não atende esta corrida.'];
        }

        if (count($cotacao->enderecos) !== 2) {
            return [...$resposta, 'motivo' => 'Corrida com parada no caminho não pode ser compartilhada.'];
        }

        $solo = (float) $categoria['valores']['valor_passageiro'];
        $precos = [];
        for ($pessoas = 2; $pessoas <= $capacidade; $pessoas++) {
            $precos[$pessoas] = round($solo * $this->precos->fracao($pessoas, true), 2);
        }

        $origem = $this->pontosDaCotacao($cotacao)[0];
        $motivo = $this->motivoParaNaoAbrir($passageiro)
            ?? ($this->motoristasPerto($origem, $categoria['tarifa_id'] ?? null) === 0
                ? 'Nenhum motorista perto de você aceita compartilhada agora.'
                : null);

        return [
            ...$resposta,
            'disponivel' => $motivo === null,
            'motivo' => $motivo,
            'preco_solo' => $solo,
            'precos_quem_abre' => $precos,
            'exibir' => true,
        ];
    }

    public function motivoParaNaoAbrir(Passageiro $passageiro): ?string
    {
        $minimo = (int) config('compartilhada.abrir_minimo_corridas', 20);
        $feitas = Corrida::where('passageiro_id', $passageiro->id)->where('status_corrida', 'finalizada')->count();

        if ($feitas < $minimo) {
            return "Abrir sala compartilhada libera depois de {$minimo} corridas. Você fez {$feitas}.";
        }

        $nota = $this->notaDoPassageiro($passageiro->id);
        $notaMinima = (float) config('compartilhada.abrir_nota_minima', 4.0);

        if ($nota !== null && $nota < $notaMinima) {
            return 'Abrir sala compartilhada exige nota '.number_format($notaMinima, 1, ',', '').' ou maior.';
        }

        return null;
    }

    /**
     * Salas públicas abertas que combinam com o trajeto desta cotação, com o
     * preço e o desvio que a pessoa teria se entrasse.
     *
     * @return list<array<string, mixed>>
     */
    public function ofertas(Passageiro $passageiro, CotacaoCorrida $cotacao): array
    {
        $categoria = $cotacao->categoria((string) config('compartilhada.produto'));

        if ($categoria === null || count($cotacao->enderecos) !== 2 || $this->participacaoAtual($passageiro->id) !== null) {
            return [];
        }

        $novo = null;
        $ofertas = [];

        $salas = SalaCompartilhada::where('status', SalaCompartilhada::ABERTA)
            ->where('tipo', 'publica')
            ->where('produto_id', $categoria['produto']['id'] ?? null)
            ->where('expira_em', '>', now()->addSeconds(20))
            ->with(['presentes.passageiro'])
            ->get();

        [$embarque, $desembarque] = $this->pontosDaCotacao($cotacao);

        foreach ($salas as $sala) {
            $presentes = $sala->presentes;

            if ($presentes->count() >= $sala->capacidade
                || $presentes->contains('passageiro_id', $passageiro->id)
                || ! $this->passaNosPreFiltros($sala, $presentes, $embarque, $desembarque)) {
                continue;
            }

            // a rota solo só é calculada quando alguma sala passa nos pré-filtros
            $novo ??= $this->dadosDoPassageiro($passageiro, $cotacao, 'dinheiro');
            $avaliacao = $this->simularEntrada($sala, $presentes, $novo);

            if ($avaliacao === null) {
                continue;
            }

            $ofertas[] = $this->montarOferta($sala, $presentes, $novo, $avaliacao);

            if (count($ofertas) === 3) {
                break;
            }
        }

        usort($ofertas, fn (array $a, array $b) => $a['meu_preco'] <=> $b['meu_preco']);

        return $ofertas;
    }

    /**
     * @param  'publica'|'convite'  $tipo
     */
    public function abrir(Passageiro $passageiro, CotacaoCorrida $cotacao, string $tipo, string $metodo): SalaCompartilhada
    {
        $this->pagamento->exigirSemPendencia($passageiro->id);

        $disponibilidade = $this->disponibilidade($passageiro, $cotacao);

        if (! $disponibilidade['disponivel']) {
            throw new RuntimeException((string) $disponibilidade['motivo'], 409);
        }

        $dados = $this->dadosDoPassageiro($passageiro, $cotacao, $metodo);

        $sala = DB::transaction(function () use ($passageiro, $cotacao, $tipo, $dados) {
            Passageiro::whereKey($passageiro->id)->lockForUpdate()->first();
            $this->exigirLivre($passageiro->id);
            $this->consumirCotacao($cotacao);

            $sala = SalaCompartilhada::create([
                'codigo' => $this->gerarCodigo(),
                'tipo' => $tipo,
                'status' => SalaCompartilhada::ABERTA,
                'criador_passageiro_id' => $passageiro->id,
                'produto_id' => $dados['produto_id'],
                'tarifa_id' => $dados['tarifa_id'],
                'cidade_id' => $cotacao->cidade_id,
                'capacidade' => (int) config('compartilhada.capacidade', 4),
                'espinha' => $dados['espinha'],
                'expira_em' => now()->addMinutes((int) config('compartilhada.sala_minutos', 10)),
            ]);

            $participante = $this->criarParticipante($sala, $dados, true);
            $participante->update(['ordem_embarque' => 0, 'ordem_desembarque' => 1]);
            $sala->update(['rota' => [
                'segundos' => $dados['solo_segundos'],
                'metros' => (int) round($dados['solo_km'] * 1000),
                'no_carro' => [$participante->id => $dados['solo_segundos']],
            ]]);

            return $sala;
        });

        $this->avisar($sala);

        return $sala;
    }

    public function entrarPorCodigo(Passageiro $passageiro, string $codigo, CotacaoCorrida $cotacao, string $metodo): SalaCompartilhada
    {
        $sala = SalaCompartilhada::where('codigo', mb_strtoupper(trim($codigo)))->first();

        if ($sala === null) {
            throw new RuntimeException('Código de sala não encontrado.', 404);
        }

        return $this->entrar($passageiro, $sala->id, $cotacao, $metodo, $sala->codigo);
    }

    public function entrar(Passageiro $passageiro, int $salaId, CotacaoCorrida $cotacao, string $metodo, ?string $codigo = null): SalaCompartilhada
    {
        $this->pagamento->exigirSemPendencia($passageiro->id);

        $sala = SalaCompartilhada::with('presentes')->find($salaId);

        if ($sala === null) {
            throw new RuntimeException('Sala não encontrada.', 404);
        }

        if ($sala->tipo === 'convite' && ($codigo === null || ! hash_equals($sala->codigo, mb_strtoupper($codigo)))) {
            throw new RuntimeException('Esta sala é só para convidados.', 403);
        }

        $this->exigirAberta($sala);

        if ($cotacao->categoria((string) config('compartilhada.produto')) === null) {
            throw new RuntimeException('A compartilhada não atende esta corrida.', 422);
        }

        $novo = $this->dadosDoPassageiro($passageiro, $cotacao, $metodo);
        [$embarque, $desembarque] = [$novo['embarque'], $novo['desembarque']];
        $presentes = $sala->presentes;

        $avaliacao = $this->passaNosPreFiltros($sala, $presentes, $embarque, $desembarque)
            ? $this->simularEntrada($sala, $presentes, $novo)
            : null;

        if ($avaliacao === null) {
            throw new RuntimeException('Esta sala não combina com o seu trajeto.', 409);
        }

        $composicao = $presentes->pluck('id')->sort()->values()->all();

        $sala = DB::transaction(function () use ($sala, $passageiro, $cotacao, $novo, $avaliacao, $composicao) {
            $travada = SalaCompartilhada::whereKey($sala->id)->lockForUpdate()->firstOrFail();
            $this->exigirAberta($travada);

            $presentes = $travada->presentes()->lockForUpdate()->get();

            // alguém entrou ou saiu enquanto a rota era calculada
            if ($presentes->pluck('id')->sort()->values()->all() !== $composicao) {
                throw new RuntimeException('A sala acabou de mudar. Confira de novo.', 409);
            }

            if ($presentes->count() >= $travada->capacidade) {
                throw new RuntimeException('A sala lotou.', 409);
            }

            Passageiro::whereKey($passageiro->id)->lockForUpdate()->first();
            $this->exigirLivre($passageiro->id);
            $this->consumirCotacao($cotacao);

            $participante = $this->criarParticipante($travada, $novo, false);
            $this->aplicar($travada, $presentes->push($participante), $this->trocarChave($avaliacao, 'novo', $participante->id));

            return $travada;
        });

        $this->avisar($sala);
        $this->avisarParticipantes($sala, fn (SalaParticipante $outro) => $outro->passageiro_id === $passageiro->id ? null : [
            'Mais uma pessoa na sua sala',
            'Seu valor agora é R$ '.$this->reais($outro->preco_atual).'.',
        ]);

        return $sala;
    }

    public function pronto(Passageiro $passageiro, int $salaId, bool $pronto): SalaCompartilhada
    {
        $passageiroId = $passageiro->id;

        $sala = DB::transaction(function () use ($salaId, $passageiroId, $pronto) {
            $sala = SalaCompartilhada::whereKey($salaId)->lockForUpdate()->first();

            if ($sala === null || ! in_array($sala->status, SalaCompartilhada::EM_FORMACAO, true)) {
                throw new RuntimeException('Esta sala não está mais aberta.', 409);
            }

            $presentes = $sala->presentes()->lockForUpdate()->get();
            $eu = $presentes->firstWhere('passageiro_id', $passageiroId);

            if ($eu === null) {
                throw new RuntimeException('Você não está nesta sala.', 404);
            }

            if ($pronto && $presentes->count() < 2) {
                throw new RuntimeException('Espere alguém entrar na sala ou peça sozinho.', 409);
            }

            // o voto trava o teto: o preço ainda pode cair, mas não sobe mais
            $eu->update($pronto
                ? ['pronto_em' => $eu->pronto_em ?? now(), 'teto' => $eu->teto ?? $eu->preco_atual]
                : ['pronto_em' => null, 'teto' => null]);

            $this->conferirFechamento($sala, $presentes);

            return $sala;
        });

        $this->avisar($sala);

        return $sala;
    }

    public function sair(Passageiro $passageiro, int $salaId): void
    {
        $passageiroId = $passageiro->id;

        $sala = DB::transaction(function () use ($salaId, $passageiroId) {
            $sala = SalaCompartilhada::whereKey($salaId)->lockForUpdate()->first();

            if ($sala === null || ! in_array($sala->status, [...SalaCompartilhada::EM_FORMACAO, SalaCompartilhada::EXPIRADA], true)) {
                throw new RuntimeException('O grupo já fechou. Para desistir, cancele a corrida.', 409);
            }

            $presentes = $sala->presentes()->lockForUpdate()->get();
            $eu = $presentes->firstWhere('passageiro_id', $passageiroId);

            if ($eu === null) {
                throw new RuntimeException('Você não está nesta sala.', 404);
            }

            $eu->update(['status' => SalaParticipante::SAIU, 'saiu_em' => now(), 'pronto_em' => null, 'teto' => null]);
            $restantes = $presentes->reject(fn (SalaParticipante $participante) => $participante->id === $eu->id)->values();

            if ($restantes->isEmpty()) {
                $sala->update(['status' => SalaCompartilhada::CANCELADA, 'fecha_em' => null]);

                return $sala;
            }

            // o corredor sobrevive: a espinha é a rota, não quem abriu; a rota
            // só encurta e ninguém é recalculado para fora
            $this->aplicar($sala, $restantes, null);
            $this->conferirFechamento($sala, $restantes);

            return $sala;
        });

        if ($sala->status !== SalaCompartilhada::CANCELADA) {
            $this->recalcularRota($sala->id);
            $this->avisarParticipantes($sala, fn (SalaParticipante $outro) => [
                'Uma pessoa saiu da sua sala',
                $outro->pronto_em !== null
                    ? 'Seu valor continua R$ '.$this->reais($outro->preco_atual).'.'
                    : 'Seu valor agora é R$ '.$this->reais($outro->preco_atual).'.',
            ]);
        }

        $this->avisar($sala);
    }

    /**
     * Quem ficou sozinho (ou ainda está sozinho na sala) vira corrida normal
     * com um toque, sem refazer o pedido.
     */
    public function pedirSozinho(Passageiro $passageiro, int $salaId): Corrida
    {
        $passageiroId = $passageiro->id;

        [$sala, $cotacao, $metodo, $codigo] = DB::transaction(function () use ($salaId, $passageiroId) {
            $sala = SalaCompartilhada::whereKey($salaId)->lockForUpdate()->first();

            if ($sala === null || ! in_array($sala->status, [...SalaCompartilhada::EM_FORMACAO, SalaCompartilhada::EXPIRADA], true)) {
                throw new RuntimeException('Esta sala não está mais aberta.', 409);
            }

            $presentes = $sala->presentes()->lockForUpdate()->get();
            $eu = $presentes->firstWhere('passageiro_id', $passageiroId);

            if ($eu === null) {
                throw new RuntimeException('Você não está nesta sala.', 404);
            }

            if ($presentes->count() > 1) {
                throw new RuntimeException('Há outras pessoas na sala. Para pedir sozinho, saia da sala.', 409);
            }

            $original = CotacaoCorrida::find($eu->cotacao_id);

            if ($original === null) {
                throw new RuntimeException('Não encontramos o preço desta corrida. Peça de novo.', 409);
            }

            $eu->update(['status' => SalaParticipante::SAIU, 'saiu_em' => now(), 'pronto_em' => null, 'teto' => null]);
            $sala->update(['status' => SalaCompartilhada::CANCELADA, 'fecha_em' => null]);

            // o preço é o mesmo cotado ao entrar na sala
            $cotacao = CotacaoCorrida::create([
                'passageiro_id' => $original->passageiro_id,
                'cidade_id' => $original->cidade_id,
                'distancia_km' => $original->distancia_km,
                'tempo_min' => $original->tempo_min,
                'enderecos' => $original->enderecos,
                'categorias' => $original->categorias,
                'expira_em' => now()->addMinutes((int) config('precificacao.cotacao_validade_minutos', 10)),
            ]);

            return [$sala, $cotacao, $eu->metodo_pagamento, (string) config('compartilhada.produto')];
        });

        $this->avisar($sala);

        return app(SolicitarCorridaService::class)->executar(
            passageiro: $passageiro,
            cotacao: $cotacao,
            produtosCodigos: [$codigo],
            metodoPagamento: $metodo,
        );
    }

    /**
     * Fecha os grupos prontos, expira as salas vencidas e encerra as salas
     * expiradas que ninguém converteu. Roda pelo agendador e também a cada
     * consulta da sala, para a tela não depender do relógio do agendador.
     */
    public function processar(?int $somenteSalaId = null): void
    {
        $consulta = fn () => SalaCompartilhada::query()->when($somenteSalaId !== null, fn ($sala) => $sala->whereKey($somenteSalaId));

        $consulta()->where('status', SalaCompartilhada::FECHANDO)->where('fecha_em', '<=', now())
            ->pluck('id')->each(fn ($id) => app(GrupoCompartilhadoService::class)->fechar((int) $id));

        $consulta()->where('status', SalaCompartilhada::ABERTA)->where('expira_em', '<=', now())
            ->pluck('id')->each(fn ($id) => $this->expirar((int) $id));

        $limite = now()->subSeconds((int) config('compartilhada.expirada_aguarda_segundos', 300));
        $consulta()->where('status', SalaCompartilhada::EXPIRADA)->where('expira_em', '<=', $limite)
            ->pluck('id')->each(fn ($id) => $this->encerrarExpirada((int) $id));
    }

    private function expirar(int $salaId): void
    {
        $presentes = SalaParticipante::where('sala_id', $salaId)->where('status', SalaParticipante::NA_SALA)->count();

        // com duas ou mais pessoas, o fim do tempo fecha o grupo com quem estiver
        if ($presentes >= 2) {
            app(GrupoCompartilhadoService::class)->fechar($salaId);

            return;
        }

        $sala = DB::transaction(function () use ($salaId) {
            $sala = SalaCompartilhada::whereKey($salaId)->lockForUpdate()->first();

            if ($sala === null || $sala->status !== SalaCompartilhada::ABERTA || $sala->expira_em->isFuture()) {
                return null;
            }

            $sozinhos = $sala->presentes()->count();
            $sala->update(['status' => $sozinhos === 1 ? SalaCompartilhada::EXPIRADA : SalaCompartilhada::CANCELADA]);

            return $sala;
        });

        if ($sala === null) {
            return;
        }

        $this->avisar($sala);

        if ($sala->status === SalaCompartilhada::EXPIRADA) {
            $this->avisarParticipantes($sala, fn (SalaParticipante $eu) => [
                'Ninguém entrou na sua sala',
                'Quer pedir sozinho por R$ '.$this->reais($eu->preco_solo).'? Toque para confirmar.',
            ]);
        }
    }

    private function encerrarExpirada(int $salaId): void
    {
        $sala = DB::transaction(function () use ($salaId) {
            $sala = SalaCompartilhada::whereKey($salaId)->lockForUpdate()->first();

            if ($sala === null || $sala->status !== SalaCompartilhada::EXPIRADA) {
                return null;
            }

            $sala->presentes()->update(['status' => SalaParticipante::SAIU, 'saiu_em' => now()]);
            $sala->update(['status' => SalaCompartilhada::CANCELADA]);

            return $sala;
        });

        if ($sala !== null) {
            $this->avisar($sala);
        }
    }

    /**
     * Refaz a rota com quem está na sala (depois de alguém sair): atualiza os
     * desvios e a duração usada no valor do motorista. Os preços não dependem
     * dela e já estão certos.
     */
    public function recalcularRota(int $salaId): void
    {
        $sala = SalaCompartilhada::with('presentes')->find($salaId);

        if ($sala === null || ! in_array($sala->status, SalaCompartilhada::EM_FORMACAO, true) || $sala->presentes->isEmpty()) {
            return;
        }

        $composicao = $sala->presentes->pluck('id')->sort()->values()->all();
        $avaliacao = $this->rotas->avaliar($sala->espinha, $this->passageirosParaRota($sala->presentes));

        if ($avaliacao === null) {
            return;
        }

        DB::transaction(function () use ($salaId, $composicao, $avaliacao) {
            $sala = SalaCompartilhada::whereKey($salaId)->lockForUpdate()->first();

            if ($sala === null || ! in_array($sala->status, SalaCompartilhada::EM_FORMACAO, true)) {
                return;
            }

            $presentes = $sala->presentes()->lockForUpdate()->get();

            if ($presentes->pluck('id')->sort()->values()->all() === $composicao) {
                $this->aplicar($sala, $presentes, $avaliacao);
            }
        });
    }

    /**
     * Estado da sala para quem está nela.
     *
     * @return array<string, mixed>
     */
    public function resumo(SalaCompartilhada $sala, int $passageiroId): array
    {
        $sala->loadMissing(['participantes.passageiro']);
        $grupoFechado = $sala->status === SalaCompartilhada::FECHADA;
        $presentes = $sala->participantes
            ->filter(fn (SalaParticipante $participante) => $participante->status !== SalaParticipante::SAIU)
            ->sortBy('entrou_em')
            ->values();
        $eu = $sala->participantes->where('passageiro_id', $passageiroId)->sortByDesc('id')->first();
        $notas = $this->notasVisiveisDosPassageiros($presentes->pluck('passageiro_id')->all());
        $pessoas = $presentes->count();
        // mesma regra do preço: o desconto maior é de quem abriu; se ele saiu,
        // de quem desce por último
        $criadorPresente = $presentes->contains('criador', true);
        $ultimoSouEu = $eu !== null && ($criadorPresente
            ? $eu->criador
            : $eu->ordem_desembarque !== null && $eu->ordem_desembarque === $presentes->max('ordem_desembarque'));
        $proximo = $eu === null || $pessoas >= $sala->capacidade
            ? null
            : min(
                round($eu->preco_solo * $this->precos->fracao($pessoas + 1, $ultimoSouEu), 2),
                $eu->teto ?? PHP_FLOAT_MAX
            );
        $esperaMaxima = $eu === null ? 0 : max(0, $sala->expira_em->getTimestamp() - $eu->entrou_em->getTimestamp());
        $desvioMaximo = $eu === null ? 0 : (int) min(
            $eu->solo_segundos * ((float) config('compartilhada.desvio_maximo_percentual', 35) / 100),
            max(0, (int) config('compartilhada.teto_total_segundos', 1500) - $esperaMaxima)
        );

        return [
            'id' => $sala->id,
            'codigo' => $sala->codigo,
            'tipo' => $sala->tipo,
            'status' => $sala->status,
            'capacidade' => $sala->capacidade,
            'expira_em' => $sala->expira_em->toIso8601String(),
            'fecha_em' => $sala->fecha_em?->toIso8601String(),
            'agora' => now()->toIso8601String(),
            'eu' => $eu === null ? null : [
                'participante_id' => $eu->id,
                'criador' => $eu->criador,
                'status' => $eu->status,
                'preco_solo' => $eu->preco_solo,
                'preco_atual' => $eu->preco_atual,
                'teto' => $eu->teto,
                'pronto' => $eu->pronto_em !== null,
                'desvio_minutos' => (int) ceil($eu->desvio_segundos / 60),
                'economia' => round(max(0, $eu->preco_solo - $eu->preco_atual), 2),
                'metodo_pagamento' => $eu->metodo_pagamento,
                'corrida_id' => $eu->corrida_id,
                'proximo_preco' => $proximo,
                'conta' => [
                    'espera_ate_minutos' => (int) ceil(max(0, $sala->expira_em->getTimestamp() - now()->getTimestamp()) / 60),
                    'viagem_ate_minutos_a_mais' => (int) ceil($desvioMaximo / 60),
                    'economia' => round(max(0, $eu->preco_solo - $eu->preco_atual), 2),
                ],
            ],
            'participantes' => $presentes->map(fn (SalaParticipante $participante) => [
                'id' => $participante->id,
                'nome' => $this->primeiroNome((string) $participante->passageiro?->name),
                // a foto dos outros só sai do servidor depois que o grupo
                // fecha: borrar no app não protege quem chama a API direto
                'foto' => $grupoFechado || $participante->passageiro_id === $passageiroId
                    ? $participante->passageiro?->foto
                    : null,
                'foto_borrada' => ! $grupoFechado,
                'nota' => $notas[$participante->passageiro_id] ?? null,
                'pronto' => $participante->pronto_em !== null,
                'criador' => $participante->criador,
                'eu' => $participante->passageiro_id === $passageiroId,
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, SalaParticipante>  $presentes
     */
    private function conferirFechamento(SalaCompartilhada $sala, Collection $presentes): void
    {
        $todosProntos = $presentes->count() >= 2
            && $presentes->every(fn (SalaParticipante $participante) => $participante->pronto_em !== null);

        if ($todosProntos && $sala->status === SalaCompartilhada::ABERTA) {
            $sala->update([
                'status' => SalaCompartilhada::FECHANDO,
                'fecha_em' => now()->addSeconds((int) config('compartilhada.janela_pronto_segundos', 10)),
            ]);
        } elseif (! $todosProntos && $sala->status === SalaCompartilhada::FECHANDO) {
            $sala->update(['status' => SalaCompartilhada::ABERTA, 'fecha_em' => null]);
        }
    }

    /**
     * Ordem e preços (sem custo de API) e, quando houver, a rota medida.
     *
     * @param  Collection<int, SalaParticipante>  $presentes
     * @param  array<string, mixed>|null  $avaliacao  sequencia, segundos, metros e no_carro de RotaCompartilhadaService::avaliar
     */
    public function aplicar(SalaCompartilhada $sala, Collection $presentes, ?array $avaliacao): void
    {
        /** @var list<array{chave: int|string, acao: string}> $sequencia */
        $sequencia = $avaliacao['sequencia'] ?? $this->rotas->ordenar($sala->espinha, $this->passageirosParaRota($presentes)) ?? [];
        $posicoes = [];

        foreach ($sequencia as $indice => $ponto) {
            $posicoes[$ponto['chave']][$ponto['acao']] = $indice;
        }

        $criador = $presentes->firstWhere('criador', true);
        $ultimo = $criador !== null ? $criador->id : collect($posicoes)->sortByDesc('desembarque')->keys()->first();
        $precos = $this->precos->precos(
            $presentes->mapWithKeys(fn (SalaParticipante $participante) => [$participante->id => [
                'preco_solo' => $participante->preco_solo,
                'teto' => $participante->teto,
            ]])->all(),
            $ultimo
        );

        foreach ($presentes as $participante) {
            $mudanca = [
                'preco_atual' => $precos[$participante->id],
                'ordem_embarque' => $posicoes[$participante->id]['embarque'] ?? null,
                'ordem_desembarque' => $posicoes[$participante->id]['desembarque'] ?? null,
            ];

            if ($avaliacao !== null) {
                $mudanca['desvio_segundos'] = max(0, ($avaliacao['no_carro'][$participante->id] ?? $participante->solo_segundos) - $participante->solo_segundos);
            }

            $participante->update($mudanca);
        }

        if ($avaliacao !== null) {
            $sala->update(['rota' => [
                'segundos' => $avaliacao['segundos'],
                'metros' => $avaliacao['metros'],
                'no_carro' => $avaliacao['no_carro'],
            ]]);
        }
    }

    /**
     * @param  Collection<int, SalaParticipante>  $presentes
     * @param  array<string, mixed>  $novo
     * @return array{sequencia: list<array{chave: int|string, acao: 'embarque'|'desembarque', latitude: float, longitude: float}>, segundos: int, metros: int, no_carro: array<int|string, int>, pontos: list<array{latitude: float, longitude: float}>}|null
     */
    private function simularEntrada(SalaCompartilhada $sala, Collection $presentes, array $novo): ?array
    {
        $avaliacao = $this->rotas->avaliar($sala->espinha, $this->passageirosParaRota($presentes, $novo));

        if ($avaliacao === null || ! $this->cabe($sala, $presentes, $novo, $avaliacao)) {
            return null;
        }

        return $avaliacao;
    }

    /**
     * Os três limites de desvio valem juntos: o absoluto protege viagem longa,
     * o percentual protege viagem curta e o teto total pega quem esperou muito
     * na sala e ainda pegou desvio.
     *
     * @param  Collection<int, SalaParticipante>  $presentes
     * @param  array<string, mixed>  $novo
     * @param  array{no_carro: array<int|string, int>}  $depois
     */
    private function cabe(SalaCompartilhada $sala, Collection $presentes, array $novo, array $depois): bool
    {
        $porEntrada = (int) config('compartilhada.desvio_maximo_por_entrada_segundos', 480);
        $percentual = 1 + (float) config('compartilhada.desvio_maximo_percentual', 35) / 100;
        $teto = (int) config('compartilhada.teto_total_segundos', 1500);
        $antes = (array) ($sala->rota['no_carro'] ?? []);

        foreach ($presentes as $participante) {
            $agora = $depois['no_carro'][$participante->id] ?? null;

            if ($agora === null) {
                return false;
            }

            $antesDele = (int) ($antes[$participante->id] ?? $participante->solo_segundos + $participante->desvio_segundos);
            $espera = max(0, $sala->expira_em->getTimestamp() - $participante->entrou_em->getTimestamp());

            if ($agora - $antesDele > $porEntrada
                || $agora > $participante->solo_segundos * $percentual
                || $espera + max(0, $agora - $participante->solo_segundos) > $teto) {
                return false;
            }
        }

        $meu = $depois['no_carro']['novo'] ?? null;
        $minhaEspera = max(0, $sala->expira_em->getTimestamp() - now()->getTimestamp());

        return $meu !== null
            && $meu <= $novo['solo_segundos'] * $percentual
            && $minhaEspera + max(0, $meu - $novo['solo_segundos']) <= $teto;
    }

    /**
     * Pré-filtros grátis, antes de gastar uma consulta de rota: embarque a até
     * 1,5 km de alguém da sala e os dois pontos perto da espinha, no sentido dela.
     *
     * @param  Collection<int, SalaParticipante>  $presentes
     * @param  array{latitude: float, longitude: float}  $embarque
     * @param  array{latitude: float, longitude: float}  $desembarque
     */
    private function passaNosPreFiltros(SalaCompartilhada $sala, Collection $presentes, array $embarque, array $desembarque): bool
    {
        $raio = (float) config('compartilhada.raio_embarque_km', 1.5);
        $perto = $presentes->contains(fn (SalaParticipante $participante) => $this->rotas->distanciaKm(
            ['latitude' => $participante->embarque_latitude, 'longitude' => $participante->embarque_longitude],
            $embarque
        ) <= $raio);

        if (! $perto) {
            return false;
        }

        $maximo = (float) config('compartilhada.distancia_maxima_da_rota_km', 3.0);
        $noEmbarque = $this->rotas->projetar($sala->espinha, $embarque['latitude'], $embarque['longitude']);
        $noDesembarque = $this->rotas->projetar($sala->espinha, $desembarque['latitude'], $desembarque['longitude']);

        return $noEmbarque['distancia'] <= $maximo
            && $noDesembarque['distancia'] <= $maximo
            && $noDesembarque['posicao'] > $noEmbarque['posicao'];
    }

    /**
     * @param  Collection<int, SalaParticipante>  $presentes
     * @param  array<string, mixed>  $novo
     * @param  array<string, mixed>  $avaliacao
     * @return array<string, mixed>
     */
    private function montarOferta(SalaCompartilhada $sala, Collection $presentes, array $novo, array $avaliacao): array
    {
        $pessoas = $presentes->count() + 1;
        $criadorFica = $presentes->contains('criador', true);
        $meuPreco = round($novo['preco_solo'] * $this->precos->fracao($pessoas, false), 2);
        $desvio = max(0, (int) $avaliacao['no_carro']['novo'] - $novo['solo_segundos']);
        $notas = $this->notasVisiveisDosPassageiros($presentes->pluck('passageiro_id')->all());

        return [
            'sala_id' => $sala->id,
            'pessoas_na_sala' => $presentes->count(),
            'capacidade' => $sala->capacidade,
            'meu_preco' => $meuPreco,
            'preco_solo' => $novo['preco_solo'],
            'economia' => round($novo['preco_solo'] - $meuPreco, 2),
            'desvio_minutos' => (int) ceil($desvio / 60),
            'espera_ate_minutos' => (int) ceil(max(0, $sala->expira_em->getTimestamp() - now()->getTimestamp()) / 60),
            'expira_em' => $sala->expira_em->toIso8601String(),
            // antes de aceitar: primeiro nome, foto borrada e nota; nunca o destino
            'participantes' => $presentes->map(fn (SalaParticipante $participante) => [
                'nome' => $this->primeiroNome((string) $participante->passageiro?->name),
                // antes de aceitar, só primeiro nome e nota: a foto não sai do servidor
                'foto' => null,
                'foto_borrada' => true,
                'nota' => $notas[$participante->passageiro_id] ?? null,
            ])->values()->all(),
            'quem_abriu_continua' => $criadorFica,
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function criarParticipante(SalaCompartilhada $sala, array $dados, bool $criador): SalaParticipante
    {
        return SalaParticipante::create([
            'sala_id' => $sala->id,
            'passageiro_id' => $dados['passageiro_id'],
            'criador' => $criador,
            'status' => SalaParticipante::NA_SALA,
            'embarque_endereco' => $dados['embarque']['endereco'],
            'embarque_latitude' => $dados['embarque']['latitude'],
            'embarque_longitude' => $dados['embarque']['longitude'],
            'desembarque_endereco' => $dados['desembarque']['endereco'],
            'desembarque_latitude' => $dados['desembarque']['latitude'],
            'desembarque_longitude' => $dados['desembarque']['longitude'],
            'cotacao_id' => $dados['cotacao_id'],
            'preco_solo' => $dados['preco_solo'],
            'solo_segundos' => $dados['solo_segundos'],
            'solo_km' => $dados['solo_km'],
            'preco_atual' => $dados['preco_solo'],
            'metodo_pagamento' => $dados['metodo_pagamento'],
            'entrou_em' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosDoPassageiro(Passageiro $passageiro, CotacaoCorrida $cotacao, string $metodo): array
    {
        $categoria = $cotacao->categoria((string) config('compartilhada.produto'));

        if ($categoria === null) {
            throw new RuntimeException('A compartilhada não atende esta corrida.', 422);
        }

        if ($cotacao->expirada()) {
            throw new RuntimeException('Cotação expirada. Refaça a consulta de preços.', 410);
        }

        [$embarque, $desembarque] = $this->pontosDaCotacao($cotacao);
        $solo = $this->rotas->trajeto([
            ['latitude' => $embarque['latitude'], 'longitude' => $embarque['longitude']],
            ['latitude' => $desembarque['latitude'], 'longitude' => $desembarque['longitude']],
        ]);

        if ($solo === null) {
            throw new RuntimeException('Não foi possível calcular a rota agora. Tente de novo.', 422);
        }

        if ($this->enderecoPorCoordenada->semEndereco($embarque['endereco'])) {
            $embarque['endereco'] = $this->enderecoPorCoordenada->executar($embarque['latitude'], $embarque['longitude']) ?? $embarque['endereco'];
        }

        return [
            'passageiro_id' => $passageiro->id,
            'embarque' => $embarque,
            'desembarque' => $desembarque,
            'cotacao_id' => $cotacao->id,
            'preco_solo' => (float) $categoria['valores']['valor_passageiro'],
            'solo_segundos' => (int) array_sum(array_column($solo['trechos'], 'segundos')),
            'solo_km' => round(array_sum(array_column($solo['trechos'], 'metros')) / 1000, 3),
            'metodo_pagamento' => $metodo,
            'espinha' => $solo['pontos'],
            'tarifa_id' => $categoria['tarifa_id'] ?? null,
            'produto_id' => $categoria['produto']['id'] ?? null,
        ];
    }

    /**
     * @return array{0: array{latitude: float, longitude: float, endereco: string}, 1: array{latitude: float, longitude: float, endereco: string}}
     */
    private function pontosDaCotacao(CotacaoCorrida $cotacao): array
    {
        $enderecos = array_values($cotacao->enderecos);
        usort($enderecos, fn (array $a, array $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        if (count($enderecos) !== 2) {
            throw new RuntimeException('Corrida com parada no caminho não pode ser compartilhada.', 422);
        }

        $ponto = fn (array $endereco) => [
            'latitude' => (float) $endereco['latitude'],
            'longitude' => (float) $endereco['longitude'],
            'endereco' => mb_substr((string) ($endereco['formattedAddress'] ?? ''), 0, 250),
        ];

        return [$ponto($enderecos[0]), $ponto($enderecos[1])];
    }

    /**
     * @param  Collection<int, SalaParticipante>  $presentes
     * @param  array<string, mixed>|null  $novo
     * @return array<int|string, array{embarque: array{latitude: float, longitude: float}, desembarque: array{latitude: float, longitude: float}}>
     */
    public function passageirosParaRota(Collection $presentes, ?array $novo = null): array
    {
        $lista = [];

        foreach ($presentes as $participante) {
            $lista[$participante->id] = [
                'embarque' => ['latitude' => $participante->embarque_latitude, 'longitude' => $participante->embarque_longitude],
                'desembarque' => ['latitude' => $participante->desembarque_latitude, 'longitude' => $participante->desembarque_longitude],
            ];
        }

        if ($novo !== null) {
            $lista['novo'] = [
                'embarque' => ['latitude' => $novo['embarque']['latitude'], 'longitude' => $novo['embarque']['longitude']],
                'desembarque' => ['latitude' => $novo['desembarque']['latitude'], 'longitude' => $novo['desembarque']['longitude']],
            ];
        }

        return $lista;
    }

    /**
     * @param  array<string, mixed>  $avaliacao
     * @return array<string, mixed>
     */
    private function trocarChave(array $avaliacao, string $de, int $para): array
    {
        $avaliacao['sequencia'] = array_map(
            fn (array $ponto) => $ponto['chave'] === $de ? [...$ponto, 'chave' => $para] : $ponto,
            $avaliacao['sequencia']
        );
        $noCarro = $avaliacao['no_carro'];
        $noCarro[$para] = $noCarro[$de];
        unset($noCarro[$de]);
        $avaliacao['no_carro'] = $noCarro;

        return $avaliacao;
    }

    private function exigirAberta(SalaCompartilhada $sala): void
    {
        if ($sala->status === SalaCompartilhada::FECHANDO) {
            throw new RuntimeException('O grupo desta sala está fechando.', 409);
        }

        if ($sala->status !== SalaCompartilhada::ABERTA || $sala->expira_em->isPast()) {
            throw new RuntimeException('Esta sala não está mais aberta.', 409);
        }
    }

    private function exigirLivre(int $passageiroId): void
    {
        $emCorrida = Corrida::where('passageiro_id', $passageiroId)
            ->whereIn('status_corrida', SolicitarCorridaService::STATUS_ATIVOS)
            ->exists();

        if ($emCorrida) {
            throw new RuntimeException('Você já tem uma corrida em andamento.', 409);
        }

        if ($this->participacaoAtual($passageiroId) !== null) {
            throw new RuntimeException('Você já está numa sala compartilhada.', 409);
        }
    }

    private function consumirCotacao(CotacaoCorrida $cotacao): void
    {
        $travada = CotacaoCorrida::whereKey($cotacao->getKey())->lockForUpdate()->first();

        if ($travada === null || $travada->consumida()) {
            throw new RuntimeException('Esta cotação já foi usada em outra corrida.', 409);
        }

        $travada->update(['consumida_em' => now()]);
    }

    /**
     * @param  array{latitude: float, longitude: float}  $origem
     */
    private function motoristasPerto(array $origem, mixed $tarifaId): int
    {
        $raio = (float) (Tarifa::whereKey($tarifaId)->value('raio_busca_motorista_km') ?: config('precificacao.raio_busca_padrao_km', 5));
        $visto = now()->subSeconds(max((int) config('precificacao.motorista_online_expira_segundos', 90), 30));

        return StatusBusca::where('disponivel', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('visto_em', '>=', $visto)
            ->whereIn('motorista_id', Motorista::where('aceita_compartilhada', true)->select('id'))
            ->get(['latitude', 'longitude'])
            ->filter(fn (StatusBusca $status) => $this->rotas->distanciaKm(
                ['latitude' => (float) $status->latitude, 'longitude' => (float) $status->longitude],
                $origem
            ) <= $raio)
            ->count();
    }

    private function notaDoPassageiro(int $passageiroId): ?float
    {
        return $this->notasDosPassageiros([$passageiroId])[$passageiroId] ?? null;
    }

    /**
     * Nota que os outros enxergam: só de quem já tem o mínimo de corridas.
     *
     * @param  array<int, mixed>  $passageiroIds
     * @return array<int, float>
     */
    private function notasVisiveisDosPassageiros(array $passageiroIds): array
    {
        $notas = $this->notasDosPassageiros($passageiroIds);

        if ($notas === []) {
            return [];
        }

        $totais = Corrida::whereIn('passageiro_id', array_keys($notas))
            ->where('status_corrida', 'finalizada')
            ->selectRaw('passageiro_id, COUNT(*) AS total')
            ->groupBy('passageiro_id')
            ->pluck('total', 'passageiro_id');

        $visiveis = [];
        foreach ($notas as $passageiroId => $nota) {
            $media = Reputacao::media($nota, (int) ($totais[$passageiroId] ?? 0));
            if ($media !== null) {
                $visiveis[$passageiroId] = $media;
            }
        }

        return $visiveis;
    }

    /**
     * Nota que os motoristas deram a cada passageiro.
     *
     * @param  array<int, mixed>  $passageiroIds
     * @return array<int, float>
     */
    private function notasDosPassageiros(array $passageiroIds): array
    {
        $passageiroIds = array_values(array_map('intval', $passageiroIds));

        if ($passageiroIds === []) {
            return [];
        }

        return AvaliacoesCorrida::query()
            ->join('corridas', 'corridas.id', '=', 'avaliacoes_corridas.corrida_id')
            ->where('avaliacoes_corridas.tipo_usuario', 'motorista')
            ->whereIn('corridas.passageiro_id', $passageiroIds)
            ->selectRaw('corridas.passageiro_id, AVG(avaliacoes_corridas.nota) AS media')
            ->groupBy('corridas.passageiro_id')
            ->pluck('media', 'passageiro_id')
            ->map(fn ($media) => round((float) $media, 2))
            ->all();
    }

    /**
     * @param  callable(SalaParticipante): (array{0: string, 1: string}|null)  $mensagem
     */
    public function avisarParticipantes(SalaCompartilhada $sala, callable $mensagem): void
    {
        $presentes = SalaParticipante::where('sala_id', $sala->id)
            ->where('status', '!=', SalaParticipante::SAIU)
            ->get();

        foreach ($presentes as $participante) {
            $texto = $mensagem($participante);
            if ($texto !== null) {
                $this->notificar->executar((int) $participante->passageiro_id, 'passageiro', $texto[0], $texto[1], [
                    'sala_id' => (string) $sala->id,
                    'tipo' => 'sala_compartilhada',
                ]);
            }
        }
    }

    public function avisar(SalaCompartilhada $sala): void
    {
        Avisar::semQuebrar(new SalaCompartilhadaAtualizada($sala->id, $sala->status));
    }

    private function gerarCodigo(): string
    {
        do {
            // sem 0/O e 1/I, que se confundem ao ditar o código
            $codigo = collect(range(1, 6))->map(fn () => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0, 31)])->implode('');
        } while (SalaCompartilhada::where('codigo', $codigo)->exists());

        return $codigo;
    }

    private function primeiroNome(string $nome): string
    {
        return preg_split('/\s+/u', trim($nome), 2)[0] ?? '';
    }

    private function reais(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}
