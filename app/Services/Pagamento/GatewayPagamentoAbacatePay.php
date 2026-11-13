<?php

namespace App\Services\Pagamento;

use App\Contracts\GatewayPagamento;
use App\Models\Corrida;
use App\Models\Passageiro;
use App\Services\AbacatePayClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Pix transparente e checkout de cartão da AbacatePay. A chave define o
 * ambiente: abc_dev_ gera cobranças de teste (devMode).
 */
class GatewayPagamentoAbacatePay implements GatewayPagamento
{
    public function __construct(private readonly AbacatePayClient $abacatePay) {}

    public function simulado(): bool
    {
        return false;
    }

    public function criarPix(Corrida $corrida, int $centavos, Passageiro $cliente): array
    {
        $dados = $this->abacatePay->post('/v2/transparents/create', [
            'method' => 'PIX',
            'data' => [
                'amount' => $centavos,
                'description' => "Corrida {$corrida->codigo_corrida}",
                'expiresIn' => (int) config('pagamentos.validade_segundos'),
                'customer' => [
                    'name' => (string) $cliente->name,
                    'email' => (string) $cliente->email,
                    'cellphone' => preg_replace('/\D/', '', (string) $cliente->telefone),
                    'taxId' => preg_replace('/\D/', '', (string) $cliente->cpf),
                ],
                'metadata' => ['corrida_id' => $corrida->id],
            ],
        ]);

        return [
            'id' => (string) $dados['id'],
            'status' => (string) $dados['status'],
            'valor_centavos' => (int) $dados['amount'],
            'br_code' => (string) $dados['brCode'],
            'br_code_base64' => (string) $dados['brCodeBase64'],
            'expira_em' => Carbon::parse($dados['expiresAt']),
            'dev_mode' => (bool) ($dados['devMode'] ?? false),
        ];
    }

    public function statusPix(string $id): string
    {
        $dados = $this->abacatePay->get('/v2/transparents/check', ['id' => $id]);

        // a resposta precisa ser da mesma cobrança consultada
        if (($dados['id'] ?? null) !== $id) {
            throw new RuntimeException('A AbacatePay devolveu uma cobrança diferente da consultada.', 502);
        }

        return (string) $dados['status'];
    }

    public function simularPix(string $id): void
    {
        if (! config('abacatepay.simulacao_habilitada')) {
            throw new RuntimeException('Simulação de pagamento indisponível neste ambiente.', 409);
        }

        // a AbacatePay exige o id na query string, além do corpo
        $this->abacatePay->post('/v2/transparents/simulate-payment?'.http_build_query(['id' => $id]), ['id' => $id]);
    }

    public function estornarPix(string $id): void
    {
        $this->abacatePay->estornarPix($id);
    }

    public function criarCartao(Corrida $corrida, int $centavos): array
    {
        $produto = $this->abacatePay->post('/v2/products/create', [
            'externalId' => 'corrida-'.$corrida->id.'-'.$centavos.'-'.now()->timestamp,
            'name' => 'Corrida '.$corrida->codigo_corrida,
            'price' => $centavos,
            'currency' => 'BRL',
            'description' => 'Corrida '.$corrida->codigo_corrida,
        ]);

        $checkout = $this->abacatePay->post('/v2/checkouts/create', [
            'items' => [['id' => $produto['id'], 'quantity' => 1]],
            'methods' => ['CARD'],
            'returnUrl' => (string) config('abacatepay.url_retorno'),
            'completionUrl' => (string) config('abacatepay.url_retorno'),
            'metadata' => ['corrida_id' => $corrida->id],
        ]);

        return [
            'produto_id' => (string) $produto['id'],
            'checkout_id' => (string) $checkout['id'],
            'url' => (string) $checkout['url'],
            'status' => (string) $checkout['status'],
            'dev_mode' => (bool) ($checkout['devMode'] ?? false),
        ];
    }

    public function statusCartao(string $id): string
    {
        $dados = $this->abacatePay->get('/v2/checkouts/get', ['id' => $id]);

        if (($dados['id'] ?? null) !== $id) {
            throw new RuntimeException('A AbacatePay devolveu um checkout diferente do consultado.', 502);
        }

        return (string) $dados['status'];
    }

    public function simularCartao(string $id): void
    {
        // o checkout hospedado não tem simulação: o cartão de teste é digitado lá
        throw new RuntimeException('Simulação de pagamento indisponível neste ambiente.', 409);
    }

    public function estornarCartao(string $id): void
    {
        $this->abacatePay->estornarCheckout($id);
    }
}
