<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Motorista\MotoristaCadastroController;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Services\AlterarCorridaService;
use App\Services\DespachoCorridaService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CorridaMotoristaController extends Controller
{
    private string $situacaoDoCadastro = MotoristaCadastroController::SEM_CADASTRO;

    private bool $jaLiberado = false;

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
            return $this->negarPorCadastro();
        }

        // só vale veículo do próprio cadastro: com o de outro motorista daria
        // para receber Elétrico ou Táxi sem ter sido aprovado
        if (isset($dados['veiculo_id']) && ! MotoristaVeiculo::where('motorista_id', $motorista->id)
            ->where('veiculo_id', (int) $dados['veiculo_id'])
            ->exists()) {
            return response()->json(['message' => 'Este veículo não está no seu cadastro.'], 422);
        }

        // sem veículo o motorista ficava "Buscando" sem nunca receber corrida
        // (toda corrida pede uma categoria de veículo)
        if ($dados['disponivel'] && ! MotoristaVeiculo::where('motorista_id', $motorista->id)->exists()) {
            return response()->json([
                'message' => 'Cadastre um veículo para ficar online.',
                'motivo' => 'sem_veiculo',
            ], 422);
        }

        try {
            $status = $this->despachoCorridaService->atualizarDisponibilidade(
                motorista: $motorista,
                disponivel: (bool) $dados['disponivel'],
                latitude: isset($dados['latitude']) ? (float) $dados['latitude'] : null,
                longitude: isset($dados['longitude']) ? (float) $dados['longitude'] : null,
                veiculoId: isset($dados['veiculo_id']) ? (int) $dados['veiculo_id'] : null
            );
        } catch (RuntimeException $excecao) {
            return response()->json(['message' => $excecao->getMessage()], $this->status($excecao));
        }

        return response()->json($status);
    }

    /**
     * Estado atual do motorista, para o app não abrir dessincronizado do servidor.
     */
    public function situacao(Request $request): JsonResponse
    {
        // quem já foi aprovado fica na home, mesmo com pendência: lá estão as ações necessárias
        $motorista = $this->motoristaDoUsuario($request)
            ?? (PerfilLogado::motorista($request)?->liberado_em !== null ? PerfilLogado::motorista($request) : null);

        if ($motorista === null) {
            return $this->negarPorCadastro();
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
            return $this->negarPorCadastro();
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
            return $this->negarPorCadastro();
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
            return $this->negarPorCadastro();
        }

        try {
            $aceita = $this->despachoCorridaService->aceitar($motorista, $corrida);
        } catch (RuntimeException $excecao) {
            return response()->json(['message' => $excecao->getMessage()], $this->status($excecao));
        }

        return response()->json($aceita);
    }

    public function recusar(Request $request, int $corrida): JsonResponse
    {
        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return $this->negarPorCadastro();
        }

        $this->despachoCorridaService->recusar($motorista, $corrida);

        return response()->json(null, 204);
    }

    /** O app avisa que a chamada apareceu na tela; só ela conta na taxa de aceitação. */
    public function tocou(Request $request, int $corrida): JsonResponse
    {
        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return $this->negarPorCadastro();
        }

        $this->despachoCorridaService->registrarToque($motorista, $corrida);

        return response()->json(null, 204);
    }

    public function transicionar(Request $request, int $corrida, string $acao): JsonResponse
    {
        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return $this->negarPorCadastro();
        }

        try {
            $atualizada = $this->despachoCorridaService->transicionar($motorista, $corrida, $acao);
        } catch (RuntimeException $excecao) {
            return response()->json(['message' => $excecao->getMessage()], $this->status($excecao));
        }

        return response()->json($atualizada);
    }

    public function confirmarParada(Request $request, int $corrida): JsonResponse
    {
        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return $this->negarPorCadastro();
        }

        try {
            $atualizada = $this->despachoCorridaService->confirmarParada($motorista, $corrida);
        } catch (RuntimeException $excecao) {
            return response()->json(['message' => $excecao->getMessage()], $this->status($excecao));
        }

        return response()->json($atualizada);
    }

    public function responderNovoDestino(Request $request, int $corrida, int $alteracao, string $resposta, AlterarCorridaService $alterarCorrida): JsonResponse
    {
        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return $this->negarPorCadastro();
        }

        try {
            $atualizada = $alterarCorrida->responderNovoDestino($motorista, $corrida, $alteracao, $resposta === 'aceitar');
        } catch (RuntimeException $excecao) {
            return response()->json(['message' => $excecao->getMessage()], $this->status($excecao));
        }

        $atualizada->setAttribute('alteracao_destino', $alterarCorrida->resumo($atualizada));

        return response()->json($atualizada);
    }

    public function cancelar(Request $request, int $corrida): JsonResponse
    {
        $dados = $request->validate([
            'motivo' => 'nullable|string|max:255',
            'tipo' => 'nullable|in:nao_comparecimento',
        ]);

        $motorista = $this->motoristaDoUsuario($request);

        if ($motorista === null) {
            return $this->negarPorCadastro();
        }

        try {
            $cancelada = $this->despachoCorridaService->cancelar(
                corridaId: $corrida,
                quem: 'motorista',
                donoId: $motorista->id,
                motivo: $dados['motivo'] ?? null,
                tipo: $dados['tipo'] ?? null
            );
        } catch (RuntimeException $excecao) {
            return response()->json(['message' => $excecao->getMessage()], $this->status($excecao));
        }

        return response()->json($cancelada);
    }

    /**
     * Só motorista aprovado opera. Quem ainda está na esteira recebe 403 com
     * a situação, para o app mandar direto para o envio de documentos em vez
     * de mostrar um erro genérico.
     */
    private function motoristaDoUsuario(Request $request): ?Motorista
    {
        $motorista = PerfilLogado::motorista($request);

        if ($motorista === null || $motorista->status !== MotoristaCadastroController::ATIVO) {
            $this->situacaoDoCadastro = $motorista->status
                ?? MotoristaCadastroController::SEM_CADASTRO;
            $this->jaLiberado = $motorista?->liberado_em !== null;

            return null;
        }

        return $motorista;
    }

    private function negarPorCadastro(): JsonResponse
    {
        return response()->json([
            'message' => $this->situacaoDoCadastro === MotoristaCadastroController::SEM_CADASTRO
                ? 'Envie seus documentos para dirigir pelo aplicativo.'
                : ($this->jaLiberado
                    ? 'Resolva as ações necessárias ou aguarde a análise dos documentos para ficar online.'
                    : 'Seu cadastro de motorista ainda não foi aprovado.'),
            'situacao' => $this->situacaoDoCadastro,
        ], 403);
    }

    private function status(RuntimeException $excecao): int
    {
        return in_array($excecao->getCode(), [403, 404, 409, 422], true)
            ? (int) $excecao->getCode()
            : 422;
    }
}
