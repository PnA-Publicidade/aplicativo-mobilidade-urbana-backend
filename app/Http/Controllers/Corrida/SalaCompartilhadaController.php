<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Http\Requests\Compartilhada\AbrirSalaCompartilhadaRequest;
use App\Http\Requests\Compartilhada\CotacaoCompartilhadaRequest;
use App\Http\Requests\Compartilhada\EntrarSalaCompartilhadaRequest;
use App\Models\CotacaoCorrida;
use App\Models\Passageiro;
use App\Models\SalaCompartilhada;
use App\Models\SalaParticipante;
use App\Services\Compartilhada\GrupoCompartilhadoService;
use App\Services\Compartilhada\SalaCompartilhadaService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SalaCompartilhadaController extends Controller
{
    public function __construct(
        private readonly SalaCompartilhadaService $salas,
        private readonly GrupoCompartilhadoService $grupos,
    ) {}

    public function disponibilidade(CotacaoCompartilhadaRequest $request): JsonResponse
    {
        return $this->responder(function () use ($request) {
            $cotacao = $this->cotacao($request, (string) $request->validated('cotacao_id'));

            return response()->json($this->salas->disponibilidade($this->passageiro($request), $cotacao));
        });
    }

    /**
     * Salas públicas em que quem está pedindo uma corrida normal pode entrar.
     */
    public function ofertas(CotacaoCompartilhadaRequest $request): JsonResponse
    {
        return $this->responder(function () use ($request) {
            $cotacao = $this->cotacao($request, (string) $request->validated('cotacao_id'));

            return response()->json(['ofertas' => $this->salas->ofertas($this->passageiro($request), $cotacao)]);
        });
    }

    public function atual(Request $request): JsonResponse
    {
        $passageiro = $this->passageiro($request);
        $participacao = $this->salas->participacaoAtual($passageiro->id);

        if ($participacao === null) {
            return response()->json(['sala' => null]);
        }

        $this->salas->processar($participacao->sala_id);

        return response()->json(['sala' => $this->resumo((int) $participacao->sala_id, $passageiro->id)]);
    }

    public function show(Request $request, int $sala): JsonResponse
    {
        $passageiro = $this->passageiro($request);
        $this->salas->processar($sala);
        $resumo = $this->resumo($sala, $passageiro->id);

        // quem saiu da sala não acompanha mais quem está nela
        if ($resumo === null || $resumo['eu'] === null || $resumo['eu']['status'] === SalaParticipante::SAIU) {
            return response()->json(['message' => 'Sala não encontrada.'], 404);
        }

        return response()->json(['sala' => $resumo]);
    }

    public function store(AbrirSalaCompartilhadaRequest $request): JsonResponse
    {
        return $this->responder(function () use ($request) {
            $cotacao = $this->cotacao($request, (string) $request->validated('cotacao_id'));
            $sala = $this->salas->abrir(
                $this->passageiro($request),
                $cotacao,
                $request->validated('tipo') === 'convite' ? 'convite' : 'publica',
                (string) $request->validated('metodo_pagamento')
            );

            return response()->json(['sala' => $this->resumo($sala->id, $this->passageiro($request)->id)], 201);
        });
    }

    public function entrar(EntrarSalaCompartilhadaRequest $request, int $sala): JsonResponse
    {
        return $this->responder(function () use ($request, $sala) {
            $cotacao = $this->cotacao($request, (string) $request->validated('cotacao_id'));
            $entrou = $this->salas->entrar($this->passageiro($request), $sala, $cotacao, (string) $request->validated('metodo_pagamento'));

            return response()->json(['sala' => $this->resumo($entrou->id, $this->passageiro($request)->id)]);
        });
    }

    public function entrarPorCodigo(EntrarSalaCompartilhadaRequest $request, string $codigo): JsonResponse
    {
        return $this->responder(function () use ($request, $codigo) {
            $cotacao = $this->cotacao($request, (string) $request->validated('cotacao_id'));
            $entrou = $this->salas->entrarPorCodigo($this->passageiro($request), $codigo, $cotacao, (string) $request->validated('metodo_pagamento'));

            return response()->json(['sala' => $this->resumo($entrou->id, $this->passageiro($request)->id)]);
        });
    }

    public function pronto(Request $request, int $sala): JsonResponse
    {
        return $this->votar($request, $sala, true);
    }

    public function desfazerPronto(Request $request, int $sala): JsonResponse
    {
        return $this->votar($request, $sala, false);
    }

    public function sair(Request $request, int $sala): JsonResponse
    {
        return $this->responder(function () use ($request, $sala) {
            $this->salas->sair($this->passageiro($request), $sala);

            return response()->json(['sala' => null]);
        });
    }

    public function pedirSozinho(Request $request, int $sala): JsonResponse
    {
        return $this->responder(fn () => response()->json(
            $this->salas->pedirSozinho($this->passageiro($request), $sala),
            201
        ));
    }

    /**
     * Grupo há mais de 1 min sem motorista: o passageiro pode seguir sozinho.
     */
    public function seguirSozinho(Request $request, int $corrida): JsonResponse
    {
        return $this->responder(fn () => response()->json(
            $this->grupos->seguirSozinho($this->passageiro($request), $corrida)
        ));
    }

    private function votar(Request $request, int $sala, bool $pronto): JsonResponse
    {
        return $this->responder(function () use ($request, $sala, $pronto) {
            $this->salas->pronto($this->passageiro($request), $sala, $pronto);

            return response()->json(['sala' => $this->resumo($sala, $this->passageiro($request)->id)]);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resumo(int $salaId, int $passageiroId): ?array
    {
        $sala = SalaCompartilhada::find($salaId);

        return $sala === null ? null : $this->salas->resumo($sala, $passageiroId);
    }

    private function cotacao(Request $request, string $id): CotacaoCorrida
    {
        $cotacao = CotacaoCorrida::find($id);

        if ($cotacao === null || $cotacao->passageiro_id !== PerfilLogado::passageiro($request)?->id) {
            throw new RuntimeException('Cotação não encontrada.', 404);
        }

        if ($cotacao->expirada()) {
            throw new RuntimeException('Cotação expirada. Refaça a consulta de preços.', 410);
        }

        return $cotacao;
    }

    private function passageiro(Request $request): Passageiro
    {
        return PerfilLogado::passageiro($request) ?? abort(403, 'Perfil de passageiro não encontrado.');
    }

    /**
     * @param  callable(): JsonResponse  $acao
     */
    private function responder(callable $acao): JsonResponse
    {
        try {
            return $acao();
        } catch (RuntimeException $erro) {
            $status = in_array($erro->getCode(), [403, 404, 409, 410, 422], true) ? $erro->getCode() : 422;

            return response()->json(['message' => $erro->getMessage()], $status);
        }
    }
}
