<?php

namespace App\Services;

use App\Events\CorridasDisponiveisAlteradas;
use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\CorridaFinanceiro;
use App\Models\CorridaOpcao;
use App\Models\CotacaoCorrida;
use App\Models\Passageiro;
use App\Services\Compartilhada\SalaCompartilhadaService;
use App\Support\Avisar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SolicitarCorridaService
{
    public const STATUS_ATIVOS = [
        'aguardando_pagamento',
        'solicitada',
        'em_busca',
        'aceita',
        'motorista_chegou',
        'em_andamento',
    ];

    public function __construct(
        private readonly EnderecoPorCoordenadaService $enderecoPorCoordenada,
    ) {}

    /**
     * @param  list<string>  $produtosCodigos  categorias aceitas pelo passageiro; a corrida vai para quem atende qualquer uma
     * @param  array{nome: string, telefone: string}|null  $convidado  quem vai viajar, se não for o titular da conta
     * @param  float|null  $valorOferecido  no Negocia, quanto o passageiro oferece pagar
     * @param  bool  $aceiteAutomatico  no Negocia, escolhe sozinho o primeiro motorista que aceitar a oferta
     */
    public function executar(
        Passageiro $passageiro,
        CotacaoCorrida $cotacao,
        array $produtosCodigos,
        ?string $metodoPagamento = null,
        ?array $convidado = null,
        ?float $valorOferecido = null,
        bool $aceiteAutomatico = false
    ): Corrida {
        $categorias = $this->categoriasEscolhidas($cotacao, $produtosCodigos);

        // reserva a mais cara; se aceitar alguém de uma categoria mais barata,
        // a corrida passa a custar a dele e a sobra volta em crédito na liquidação
        $categoria = $categorias[0];
        $negociada = ($categoria['produto']['estrategia_precificacao'] ?? null) === 'negociada';

        if ($negociada) {
            $categoria = NegociacaoCorridaService::categoriaComOferta(
                $categoria,
                $valorOferecido ?? (float) $categoria['valores']['valor_passageiro']
            );
            $categorias = [$categoria];
        }

        $pagamento = app(PagamentoCorridaService::class);

        // valor em aberto de corrida anterior bloqueia qualquer novo pedido
        $pagamento->exigirSemPendencia($passageiro->id);

        // Pix e cartão pagam no pedido, também no Negocia (a oferta do
        // passageiro): os motoristas só veem a corrida depois do pagamento
        $prePago = $pagamento->ehPrePago($metodoPagamento);

        $corrida = DB::transaction(function () use ($cotacao, $categoria, $categorias, $passageiro, $metodoPagamento, $prePago, $pagamento, $convidado, $negociada, $aceiteAutomatico) {
            // trava o passageiro: dois pedidos simultâneos não criam duas
            // corridas ativas nem gastam o mesmo crédito
            Passageiro::whereKey($passageiro->id)->lockForUpdate()->first();

            $travada = CotacaoCorrida::whereKey($cotacao->getKey())->lockForUpdate()->first();

            if ($travada === null || $travada->consumida()) {
                throw new RuntimeException('Esta cotação já foi usada em outra corrida.', 409);
            }

            $emAndamento = Corrida::where('passageiro_id', $passageiro->id)
                ->whereIn('status_corrida', self::STATUS_ATIVOS)
                ->exists();

            if ($emAndamento) {
                throw new RuntimeException('Você já tem uma corrida em andamento.', 409);
            }

            if (app(SalaCompartilhadaService::class)->participacaoAtual($passageiro->id) !== null) {
                throw new RuntimeException('Você está numa sala compartilhada. Saia dela para pedir outra corrida.', 409);
            }

            $corrida = Corrida::create([
                'codigo_corrida' => $this->gerarCodigo(),
                'produto_id' => $categoria['produto']['id'] ?? null,
                'tarifa_id' => $categoria['tarifa_id'] ?? null,
                'passageiro_id' => $passageiro->id,
                'convidado_nome' => $convidado['nome'] ?? null,
                'convidado_telefone' => $convidado['telefone'] ?? null,
                'cidade_id' => $cotacao->cidade_id,
                // pré-pago: invisível aos motoristas até o pagamento ser confirmado
                'status_corrida' => $prePago ? 'aguardando_pagamento' : 'solicitada',
                'status_negociacao' => $negociada ? 'em_negociacao' : null,
                'aceite_automatico' => $negociada && $aceiteAutomatico,
                'tempo_solicitacao' => now(),
                'distancia_total' => $cotacao->distancia_km,
                'valor_estimado_inicial' => $categoria['valores']['valor_passageiro'],
                'metodo_pagamento' => $metodoPagamento,
                'status_pagamento' => $metodoPagamento === null ? null : 'pendente',
            ]);

            $this->gravarDestinos($corrida, $cotacao);
            $this->gravarFinanceiro($corrida, $categoria, $metodoPagamento);
            $this->gravarOpcoes($corrida, $categorias);

            if ($prePago) {
                $valor = (float) $categoria['valores']['valor_passageiro'];
                $usado = $pagamento->aplicarCredito($corrida, $valor);

                // crédito cobriu tudo: já pode procurar motorista
                if ($valor - $usado <= 0.009) {
                    $corrida->update(['status_corrida' => 'solicitada', 'status_pagamento' => 'pago']);
                }
            }

            $travada->update(['consumida_em' => now()]);

            if ($corrida->status_corrida === 'solicitada') {
                Avisar::semQuebrar(new CorridasDisponiveisAlteradas);
            }

            return $corrida;
        });

        if ($corrida->status_corrida === 'aguardando_pagamento') {
            $this->gerarCobranca($corrida, $pagamento);
        }

        return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']);
    }

    /**
     * Gera o Pix ou o checkout do cartão logo após criar a corrida. Se o
     * provedor de pagamento falhar, a corrida é cancelada sem cobrança (e o
     * crédito volta).
     */
    public function gerarCobranca(Corrida $corrida, PagamentoCorridaService $pagamento): void
    {
        try {
            if ($corrida->metodo_pagamento === 'pix') {
                app(CobrancaPixService::class)->paraCorrida($corrida);
            } else {
                app(CobrancaCartaoService::class)->paraCorrida($corrida);
            }
        } catch (RuntimeException $erro) {
            $corrida->update([
                'status_corrida' => 'cancelada',
                'cancelada_em' => now(),
                'cancelado_por' => 'sistema',
                'motivo_cancelamento' => 'Não foi possível gerar o pagamento.',
            ]);
            $pagamento->liquidarSemQuebrar($corrida);

            Log::warning('Cobrança da corrida não gerada.', [
                'corrida_id' => $corrida->id,
                'metodo' => $corrida->metodo_pagamento,
                'erro' => $erro->getMessage(),
            ]);

            // o provedor recusa CPF inválido ou de exemplo: tentar de novo não resolve
            $cpfRecusado = str_contains(mb_strtolower($erro->getMessage()), 'taxid');

            throw new RuntimeException(
                $cpfRecusado
                    ? 'O pagamento pelo app não aceitou o CPF da sua conta. Confira o CPF no seu perfil ou escolha pagar em dinheiro.'
                    : 'Não foi possível gerar o pagamento agora. Tente de novo ou escolha pagar em dinheiro.',
                409,
                $erro
            );
        }
    }

    /**
     * @param  list<string>  $codigos
     * @return non-empty-list<array<string, mixed>> da mais cara para a mais barata
     */
    private function categoriasEscolhidas(CotacaoCorrida $cotacao, array $codigos): array
    {
        $categorias = [];

        foreach (array_values(array_unique($codigos)) as $codigo) {
            $categoria = $cotacao->categoria($codigo);

            if ($categoria === null) {
                throw new RuntimeException('Categoria não faz parte desta cotação.');
            }

            $categorias[] = $categoria;
        }

        if ($categorias === []) {
            throw new RuntimeException('Escolha ao menos uma categoria.');
        }

        if (count($categorias) > 1) {
            foreach ($categorias as $categoria) {
                if (($categoria['produto']['estrategia_precificacao'] ?? null) === 'negociada') {
                    throw new RuntimeException('O Negocia é pedido sozinho, sem outras categorias.');
                }

                if (($categoria['produto']['grupo'] ?? null) === 'entrega') {
                    throw new RuntimeException('A entrega é pedida separada das corridas.');
                }
            }
        }

        usort($categorias, fn (array $a, array $b) => (float) $b['valores']['valor_passageiro'] <=> (float) $a['valores']['valor_passageiro']);

        return $categorias;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $categorias
     */
    private function gravarOpcoes(Corrida $corrida, array $categorias): void
    {
        foreach ($categorias as $categoria) {
            // tarifa sem produto não restringe quem pode atender
            if (($categoria['produto']['id'] ?? null) === null) {
                continue;
            }

            CorridaOpcao::create([
                'corrida_id' => $corrida->id,
                'produto_id' => $categoria['produto']['id'],
                'tarifa_id' => $categoria['tarifa_id'] ?? null,
                'valor_passageiro' => $categoria['valores']['valor_passageiro'],
                'valor_motorista' => $categoria['valores']['valor_motorista'],
                'categoria' => $categoria,
            ]);
        }
    }

    private function gravarDestinos(Corrida $corrida, CotacaoCorrida $cotacao): void
    {
        $enderecos = $cotacao->enderecos;
        $ultimo = count($enderecos) - 1;

        foreach (array_values($enderecos) as $indice => $endereco) {
            $tipo = match (true) {
                $indice === 0 => 'origem',
                $indice === $ultimo => 'destino',
                default => 'parada',
            };
            $texto = (string) ($endereco['formattedAddress'] ?? '');

            // "Localização Atual" não diz ao motorista onde buscar
            if ($this->enderecoPorCoordenada->semEndereco($texto)) {
                $texto = $this->enderecoPorCoordenada->executar(
                    (float) $endereco['latitude'],
                    (float) $endereco['longitude'],
                ) ?? $texto;
            }

            CorridaDestino::create([
                'corrida_id' => $corrida->id,
                'nome_local' => $texto,
                'tipo' => $tipo,
                'ordem' => $indice,
                'endereco' => $texto,
                'latitude' => $endereco['latitude'],
                'longitude' => $endereco['longitude'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $categoria
     */
    private function gravarFinanceiro(Corrida $corrida, array $categoria, ?string $metodoPagamento): void
    {
        CorridaFinanceiro::create([
            'corrida_id' => $corrida->id,
            ...self::valoresDaCategoria($categoria),
            'metodo_pagamento' => $metodoPagamento,
        ]);
    }

    /**
     * Colunas do financeiro que vêm da categoria cotada (também usadas quando
     * a corrida troca de categoria no aceite).
     *
     * @param  array<string, mixed>  $categoria
     * @return array<string, mixed>
     */
    public static function valoresDaCategoria(array $categoria): array
    {
        $composicao = $categoria['composicao'];
        $valores = $categoria['valores'];

        return [
            'valor_bruto' => $composicao['subtotal'],
            'tarifa_base' => $composicao['tarifa_base'],
            'valor_por_km' => $composicao['valor_distancia'],
            'valor_por_minuto' => $composicao['valor_tempo'],
            'valor_por_minuto_espera' => $composicao['valor_por_minuto_espera'] ?? 0,
            'taxa_espera' => 0,
            'valor_sem_dinamica' => $composicao['subtotal'],
            'valor_base_calculado' => $composicao['subtotal'],
            'valor_ajuste_negociado' => $composicao['diferenca_negociada'],
            'valor_pago_passageiro' => $valores['valor_passageiro'],
            'taxa_plataforma_valor' => $valores['taxa_plataforma'],
            'taxa_plataforma_percentual' => $valores['taxa_plataforma_percentual'],
            'valor_motorista' => $valores['valor_motorista'],
            'valor_liquido_motorista' => $valores['valor_motorista'],
            'valor_repassado_plataforma' => $valores['taxa_plataforma'],
        ];
    }

    public function gerarCodigo(): string
    {
        do {
            $codigo = 'C'.now()->format('ymd').strtoupper(Str::random(5));
        } while (Corrida::where('codigo_corrida', $codigo)->exists());

        return $codigo;
    }
}
