<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Services\DespachoCorridaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CorridaMotoristaController extends Controller
{
    public function __construct(
        protected DespachoCorridaService $despachoCorridaService
    ) {}

    public function disponibilidade(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'disponivel' => 'required|boolean',
            'latitude' => 'required_if:disponivel,true|nullable|numeric|between:-90,90',
            'longitude' => 'required_if:disponivel,true|nullable|numeric|between:-180,180',
            'veiculo_id' => 'nullable|integer|exists:veiculos,id',
        ]);

        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return response()->json(['message' => 'Usuário não é motorista.'], 403);
        }

        $status = $this->despachoCorridaService->atualizarDisponibilidade(
            motorista: $motorista,
            disponivel: (bool) $dados['disponivel'],
            latitude: isset($dados['latitude']) ? (float) $dados['latitude'] : null,
            longitude: isset($dados['longitude']) ? (float) $dados['longitude'] : null,
            veiculoId: isset($dados['veiculo_id']) ? (int) $dados['veiculo_id'] : null
        );

        return response()->json($status);
    }

    /**
     * Estado atual do motorista, para o app não abrir dessincronizado do servidor.
     */
    public function situacao(Request $request): JsonResponse
    {
        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return response()->json(['message' => 'Usuário não é motorista.'], 403);
        }

        return response()->json(
            $this->despachoCorridaService->situacaoDe($motorista)
        );
    }

    public function posicao(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return response()->json(['message' => 'Usuário não é motorista.'], 403);
        }

        $this->despachoCorridaService->atualizarPosicao(
            motorista: $motorista,
            latitude: (float) $dados['latitude'],
            longitude: (float) $dados['longitude']
        );

        return response()->json(['ok' => true]);
    }

    public function corridasDisponiveis(Request $request): JsonResponse
    {
        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return response()->json(['message' => 'Usuário não é motorista.'], 403);
        }

        try {
            $ofertas = $this->despachoCorridaService->ofertasPara($motorista);
        } catch (RuntimeException $excecao) {
            return response()->json(['message' => $excecao->getMessage()], $this->status($excecao));
        }

        return response()->json(['corridas' => $ofertas]);
    }

    public function aceitar(Request $request, int $corrida): JsonResponse
    {
        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return response()->json(['message' => 'Usuário não é motorista.'], 403);
        }

        try {
            $aceita = $this->despachoCorridaService->aceitar($motorista, $corrida);
        } catch (RuntimeException $excecao) {
            return response()->json(['message' => $excecao->getMessage()], $this->status($excecao));
        }

        return response()->json($aceita);
    }

    public function transicionar(Request $request, int $corrida, string $acao): JsonResponse
    {
        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return response()->json(['message' => 'Usuário não é motorista.'], 403);
        }

        try {
            $atualizada = $this->despachoCorridaService->transicionar($motorista, $corrida, $acao);
        } catch (RuntimeException $excecao) {
            return response()->json(['message' => $excecao->getMessage()], $this->status($excecao));
        }

        return response()->json($atualizada);
    }

    public function cancelar(Request $request, int $corrida): JsonResponse
    {
        $dados = $request->validate(['motivo' => 'nullable|string|max:255']);

        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return response()->json(['message' => 'Usuário não é motorista.'], 403);
        }

        try {
            $cancelada = $this->despachoCorridaService->cancelar(
                corridaId: $corrida,
                quem: 'motorista',
                donoId: $motorista->id,
                motivo: $dados['motivo'] ?? null
            );
        } catch (RuntimeException $excecao) {
            return response()->json(['message' => $excecao->getMessage()], $this->status($excecao));
        }

        return response()->json($cancelada);
    }

    private function motoristaDoUsuario(Request $request): ?Motorista
    {
        return Motorista::where('user_id', $request->user()->id)->first();
    }

    private function status(RuntimeException $excecao): int
    {
        return in_array($excecao->getCode(), [403, 404, 409, 422], true)
            ? (int) $excecao->getCode()
            : 422;
    }
}
