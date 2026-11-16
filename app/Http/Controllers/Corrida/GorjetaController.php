<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Models\Corrida;
use App\Models\Gorjeta;
use App\Services\ConfiguracaoPlataformaService;
use App\Services\NotificarUsuarioService;
use App\Support\PerfilLogado;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GorjetaController extends Controller
{
    public function __construct(
        private readonly ConfiguracaoPlataformaService $configuracoes,
        private readonly NotificarUsuarioService $notificacoes,
    ) {}

    /** O que a tela de avaliação mostra: se a gorjeta está ligada e os 3 valores sugeridos. */
    public function configuracao(): JsonResponse
    {
        return response()->json([
            'ativa' => $this->configuracoes->gorjetaAtiva(),
            'sugestoes' => $this->configuracoes->gorjetaSugestoes(),
        ]);
    }

    /**
     * O passageiro deixa a gorjeta de uma corrida finalizada; ela entra no saldo
     * do motorista. Só vale um dos valores sugeridos pela plataforma.
     */
    public function store(Request $request, int $corrida): JsonResponse
    {
        $passageiro = PerfilLogado::passageiro($request);
        abort_if($passageiro === null, 403, 'Só o passageiro deixa gorjeta.');

        $dados = $request->validate(['valor' => 'required|numeric|decimal:0,2']);

        $encontrada = Corrida::where('passageiro_id', $passageiro->id)->find($corrida);

        if ($encontrada === null) {
            return response()->json(['message' => 'Corrida não encontrada.'], 404);
        }

        if ($encontrada->status_corrida !== 'finalizada' || (int) $encontrada->motorista_id <= 0) {
            return response()->json(['message' => 'Só é possível dar gorjeta em uma corrida finalizada.'], 409);
        }

        if (! $this->configuracoes->gorjetaAtiva()) {
            return response()->json(['message' => 'A gorjeta não está disponível no momento.'], 409);
        }

        $valor = round((float) $dados['valor'], 2);

        if (! in_array($valor, $this->configuracoes->gorjetaSugestoes(), true)) {
            return response()->json(['message' => 'Escolha um dos valores sugeridos.'], 422);
        }

        try {
            $gorjeta = Gorjeta::create([
                'corrida_id' => $encontrada->id,
                'passageiro_id' => $passageiro->id,
                'motorista_id' => $encontrada->motorista_id,
                'valor' => $valor,
            ]);
        } catch (QueryException) {
            // a restrição única barra a segunda gorjeta da mesma corrida (inclusive em duplo toque)
            return response()->json(['message' => 'Você já deixou gorjeta nesta corrida.'], 409);
        }

        $this->notificacoes->executar(
            (int) $encontrada->motorista_id,
            'motorista',
            'Você recebeu uma gorjeta',
            'Um passageiro deixou R$ '.number_format($valor, 2, ',', '.').' de gorjeta. O valor já está no seu saldo.',
            ['corrida_id' => (string) $encontrada->id],
        );

        return response()->json(['data' => $gorjeta], 201);
    }
}
