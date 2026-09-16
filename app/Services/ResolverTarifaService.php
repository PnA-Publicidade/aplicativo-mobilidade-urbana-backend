<?php

namespace App\Services;

use App\Models\Tarifa;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ResolverTarifaService
{
    /**
     * @return Collection<int, Tarifa>
     */
    public function paraTodosOsProdutos(?int $cidadeId, CarbonInterface $momento): Collection
    {
        return Tarifa::query()
            ->where('ativo', true)
            ->whereNotNull('produto_id')
            ->where(fn ($q) => $q->where('cidade_id', $cidadeId)->orWhereNull('cidade_id'))
            ->with('produto')
            ->get()
            ->filter(fn (Tarifa $tarifa) => $this->valeAgora($tarifa, $momento))
            ->sortByDesc(fn (Tarifa $tarifa) => $tarifa->cidade_id === null ? 0 : 1)
            ->groupBy('produto_id')
            ->map(fn (Collection $doProduto) => $doProduto->first())
            ->sortBy(fn (Tarifa $tarifa) => $tarifa->produto->ordem)
            ->values();
    }

    public function paraProduto(int $produtoId, ?int $cidadeId, CarbonInterface $momento): ?Tarifa
    {
        return $this->paraTodosOsProdutos($cidadeId, $momento)
            ->firstWhere('produto_id', $produtoId);
    }

    private function valeAgora(Tarifa $tarifa, CarbonInterface $momento): bool
    {
        if (! $this->valeNesteDia($tarifa, $momento)) {
            return false;
        }

        $inicio = $tarifa->horario_inicio;
        $fim = $tarifa->horario_fim;

        if ($inicio === null || $fim === null) {
            return true;
        }

        $agora = $momento->format('H:i:s');

        if ($tarifa->vira_dia) {
            return $agora >= $inicio || $agora <= $fim;
        }

        return $agora >= $inicio && $agora <= $fim;
    }

    private function valeNesteDia(Tarifa $tarifa, CarbonInterface $momento): bool
    {
        $dias = $this->diasDaSemana($tarifa);

        if ($dias === []) {
            return true;
        }

        $dia = $tarifa->vira_dia && $momento->format('H:i:s') <= (string) $tarifa->horario_fim
            ? $momento->copy()->subDay()->isoWeekday()
            : $momento->isoWeekday();

        return in_array($dia, $dias, true);
    }

    /**
     * @return list<int>
     */
    private function diasDaSemana(Tarifa $tarifa): array
    {
        $bruto = $tarifa->dias_semana;

        if ($bruto === null || trim((string) $bruto) === '') {
            return [];
        }

        $decodificado = json_decode((string) $bruto, true);

        $valores = is_array($decodificado)
            ? $decodificado
            : explode(',', (string) $bruto);

        return array_values(array_filter(
            array_map(static fn ($d) => (int) trim((string) $d), $valores),
            static fn (int $d) => $d >= 1 && $d <= 7
        ));
    }
}
