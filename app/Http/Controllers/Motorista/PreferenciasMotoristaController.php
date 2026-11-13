<?php

namespace App\Http\Controllers\Motorista;

use App\Http\Controllers\Controller;
use App\Http\Requests\Motorista\AtualizarPreferenciasMotoristaRequest;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\StatusBusca;
use App\Models\Veiculo;
use App\Services\Compartilhada\GrupoCompartilhadoService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preferências do motorista para a viagem compartilhada: aceitar ou não as
 * chamadas de grupo (desligado para motorista novo) e quantos lugares o carro tem.
 */
class PreferenciasMotoristaController extends Controller
{
    public function mostrar(Request $request): JsonResponse
    {
        $motorista = PerfilLogado::motorista($request);

        if ($motorista === null) {
            return response()->json(['message' => 'Cadastro de motorista não encontrado.'], 404);
        }

        return response()->json($this->preferencias($motorista));
    }

    public function atualizar(AtualizarPreferenciasMotoristaRequest $request): JsonResponse
    {
        $motorista = PerfilLogado::motorista($request);

        if ($motorista === null) {
            return response()->json(['message' => 'Cadastro de motorista não encontrado.'], 404);
        }

        if ($request->has('aceita_compartilhada')) {
            $motorista->update(['aceita_compartilhada' => $request->boolean('aceita_compartilhada')]);
        }

        $veiculo = $this->veiculoEmUso($motorista);

        if ($request->has('assentos') && $veiculo !== null) {
            $veiculo->update(['assentos' => (int) $request->validated('assentos')]);
        }

        return response()->json($this->preferencias($motorista->fresh() ?? $motorista));
    }

    /**
     * @return array{aceita_compartilhada: bool, suspensa_ate: string|null, suspensa_texto: string|null, assentos: int|null, veiculo_id: int|null}
     */
    private function preferencias(Motorista $motorista): array
    {
        $veiculo = $this->veiculoEmUso($motorista);
        $grupos = app(GrupoCompartilhadoService::class);
        $suspenso = $grupos->suspenso($motorista);

        return [
            'aceita_compartilhada' => (bool) $motorista->aceita_compartilhada,
            // desistiu de um grupo: sem chamadas de grupo até este horário
            'suspensa_ate' => $suspenso ? $motorista->compartilhada_suspensa_ate?->toIso8601String() : null,
            'suspensa_texto' => $suspenso ? 'Você desistiu de um grupo. Compartilhadas voltam '.$grupos->quando($motorista->compartilhada_suspensa_ate).'.' : null,
            'assentos' => $veiculo === null ? null : (int) ($veiculo->assentos ?? 4),
            'veiculo_id' => $veiculo?->id,
        ];
    }

    private function veiculoEmUso(Motorista $motorista): ?Veiculo
    {
        $doStatus = StatusBusca::where('motorista_id', $motorista->id)->value('veiculo_id');
        $deles = MotoristaVeiculo::where('motorista_id', $motorista->id)->pluck('veiculo_id');
        $veiculoId = $doStatus !== null && $deles->contains($doStatus) ? $doStatus : $deles->first();

        return $veiculoId === null ? null : Veiculo::find((int) $veiculoId);
    }
}
