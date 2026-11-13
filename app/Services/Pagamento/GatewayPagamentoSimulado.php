<?php

namespace App\Services\Pagamento;

use App\Contracts\GatewayPagamento;
use App\Models\CobrancaCartao;
use App\Models\CobrancaPix;
use App\Models\Corrida;
use App\Models\Passageiro;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Pagamento de mentira: nenhum dinheiro passa por aqui. O Pix tem QR e
 * código só ilustrativos e o cartão é digitado num formulário do próprio app;
 * o passageiro confirma e o pagamento fica PAID. As cobranças saem com
 * dev_mode = true, o que as separa das reais nos relatórios.
 */
class GatewayPagamentoSimulado implements GatewayPagamento
{
    public function simulado(): bool
    {
        return true;
    }

    public function criarPix(Corrida $corrida, int $centavos, Passageiro $cliente): array
    {
        $id = 'pix_simulado_'.Str::lower(Str::random(20));
        $valor = number_format($centavos / 100, 2, ',', '');

        return [
            'id' => $id,
            'status' => 'PENDING',
            'valor_centavos' => $centavos,
            'br_code' => "PIX-SIMULADO-{$corrida->codigo_corrida}-R\${$valor}-SEM-COBRANCA-REAL",
            'br_code_base64' => $this->qrIlustrativo($id),
            'expira_em' => now()->addSeconds((int) config('pagamentos.validade_segundos')),
            'dev_mode' => true,
        ];
    }

    public function statusPix(string $id): string
    {
        return $this->status($id, fn () => CobrancaPix::where('charge_id', $id)->value('status'));
    }

    public function simularPix(string $id): void
    {
        $this->guardar($id, 'PAID');
    }

    public function estornarPix(string $id): void
    {
        $this->guardar($id, 'REFUNDED');
    }

    public function criarCartao(Corrida $corrida, int $centavos): array
    {
        return [
            'produto_id' => null,
            'checkout_id' => 'cartao_simulado_'.Str::lower(Str::random(20)),
            'url' => null,
            'status' => 'PENDING',
            'dev_mode' => true,
        ];
    }

    public function statusCartao(string $id): string
    {
        return $this->status($id, fn () => CobrancaCartao::where('checkout_id', $id)->value('status'));
    }

    public function simularCartao(string $id): void
    {
        $this->guardar($id, 'PAID');
    }

    public function estornarCartao(string $id): void
    {
        $this->guardar($id, 'REFUNDED');
    }

    /**
     * O "provedor" guarda o status no cache; se o cache sumir, vale o que já
     * está gravado na cobrança (um pagamento confirmado nunca volta a pendente).
     *
     * @param  callable(): (string|null)  $doBanco
     */
    private function status(string $id, callable $doBanco): string
    {
        $status = Cache::get($this->chave($id));

        return is_string($status) ? $status : ($doBanco() ?? 'PENDING');
    }

    private function guardar(string $id, string $status): void
    {
        Cache::forever($this->chave($id), $status);
    }

    private function chave(string $id): string
    {
        return "pagamento-simulado:{$id}";
    }

    /**
     * Desenho parecido com um QR Code (não lê em nenhum banco), em PNG base64.
     */
    private function qrIlustrativo(string $semente): string
    {
        $modulos = 29;
        $escala = 8;
        $margem = 4;
        $lado = ($modulos + 2 * $margem) * $escala;
        $imagem = imagecreatetruecolor($lado, $lado);
        $branco = (int) imagecolorallocate($imagem, 255, 255, 255);
        $preto = (int) imagecolorallocate($imagem, 17, 17, 17);
        imagefill($imagem, 0, 0, $branco);

        mt_srand(crc32($semente));
        $quadro = fn (int $x, int $y) => $x < 8 && $y < 8 || $x >= $modulos - 8 && $y < 8 || $x < 8 && $y >= $modulos - 8;

        for ($y = 0; $y < $modulos; $y++) {
            for ($x = 0; $x < $modulos; $x++) {
                if (! $quadro($x, $y) && mt_rand(0, 1) === 1) {
                    $this->modulo($imagem, $x, $y, $margem, $escala, $preto);
                }
            }
        }
        mt_srand();

        // os três quadrados dos cantos, como num QR de verdade
        foreach ([[0, 0], [$modulos - 7, 0], [0, $modulos - 7]] as [$cx, $cy]) {
            for ($y = 0; $y < 7; $y++) {
                for ($x = 0; $x < 7; $x++) {
                    $borda = $x === 0 || $y === 0 || $x === 6 || $y === 6;
                    $miolo = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
                    if ($borda || $miolo) {
                        $this->modulo($imagem, $cx + $x, $cy + $y, $margem, $escala, $preto);
                    }
                }
            }
        }

        ob_start();
        imagepng($imagem);
        $png = (string) ob_get_clean();
        imagedestroy($imagem);

        return base64_encode($png);
    }

    private function modulo(\GdImage $imagem, int $x, int $y, int $margem, int $escala, int $cor): void
    {
        $px = ($x + $margem) * $escala;
        $py = ($y + $margem) * $escala;
        imagefilledrectangle($imagem, $px, $py, $px + $escala - 1, $py + $escala - 1, $cor);
    }
}
