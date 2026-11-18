<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Regras de quem recebe o cartão de chamada, editáveis na gestão. Ficam em
 * `configuracoes_plataforma` (chaves `despacho_*`); sem ajuste vale o padrão.
 *
 * A pontuação de cada motorista é a média ponderada de cinco fatores (0 a 1):
 * proximidade do embarque, nota, taxa de aceitação, taxa de finalização e a
 * demanda do lugar onde ele está (motorista em região quente puxa a chamada).
 * Os pesos não precisam somar 100: contam só em relação uns aos outros.
 */
class RegrasDespachoService
{
    /** Padrão e limites de cada regra: [padrão, mínimo, máximo]. */
    public const REGRAS = [
        'fila_ativa' => [1, 0, 1],
        // por quanto tempo o cartão toca para um motorista antes de passar ao próximo
        'tempo_cartao_segundos' => [20, 5, 60],
        // depois de recusar ou deixar passar, o motorista descansa antes de receber outra
        'pausa_apos_recusa_segundos' => [30, 0, 600],
        // quando todos da fila já foram chamados, espera antes de recomeçar
        'espera_nova_rodada_segundos' => [7, 0, 300],
        // quantos motoristas, no máximo, são chamados por rodada
        'maximo_candidatos' => [10, 1, 50],
        // filtros: quem está abaixo disso não entra na fila (0 desliga)
        'nota_minima' => [4, 0, 5],
        'taxa_aceitacao_minima' => [0, 0, 100],
        'taxa_finalizacao_minima' => [0, 0, 100],
        // pesos da pontuação
        'peso_proximidade' => [40, 0, 100],
        'peso_nota' => [20, 0, 100],
        'peso_aceitacao' => [20, 0, 100],
        'peso_finalizacao' => [10, 0, 100],
        'peso_demanda' => [10, 0, 100],
    ];

    /**
     * @return array<string, int|float>
     */
    public function regras(): array
    {
        $salvas = DB::table('configuracoes_plataforma')
            ->where('chave', 'like', 'despacho\_%')
            ->pluck('valor', 'chave');

        $regras = [];

        foreach (self::REGRAS as $nome => [$padrao]) {
            $valor = $salvas->get('despacho_'.$nome);
            $regras[$nome] = $valor === null ? $this->padrao($nome, $padrao) : $this->converter($nome, $valor);
        }

        return $regras;
    }

    /**
     * @return array<string, int|float>
     */
    public function padroes(): array
    {
        return collect(self::REGRAS)->map(fn (array $regra, string $nome) => $this->padrao($nome, $regra[0]))->all();
    }

    /**
     * @param  array<string, mixed>  $novas
     * @return array<string, int|float>
     */
    public function salvar(array $novas): array
    {
        foreach ($novas as $nome => $valor) {
            if (! array_key_exists($nome, self::REGRAS)) {
                continue;
            }

            [, $minimo, $maximo] = self::REGRAS[$nome];
            $valor = min(max((float) $valor, $minimo), $maximo);

            DB::table('configuracoes_plataforma')->updateOrInsert(
                ['chave' => 'despacho_'.$nome],
                ['valor' => (string) $valor, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return $this->regras();
    }

    /** A fila nasce ligada, salvo se o ambiente (config/despacho.php) disser o contrário. */
    private function padrao(string $nome, int $padrao): int
    {
        return $nome === 'fila_ativa' ? (int) config('despacho.fila_ativa', true) : $padrao;
    }

    /** Só a nota mínima admite casas decimais. */
    private function converter(string $nome, mixed $valor): int|float
    {
        return $nome === 'nota_minima' ? round((float) $valor, 1) : (int) round((float) $valor);
    }
}
