<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Http\Resources\DetalhesCorridaResource;
use App\Models\ChamadoAjuda;
use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Services\ChatCorridaService;
use Illuminate\Http\Request;

class ChatCorridaController extends Controller
{
    public function __construct(private ChatCorridaService $service) {}

    private function cursores(Request $request): array
    {
        $dados = $request->validate([
            'before_id' => 'nullable|integer|min:1', 'after_id' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);
        abort_if(isset($dados['before_id'], $dados['after_id']), 422, 'Use apenas um cursor.');

        return $dados;
    }

    private function participante(Request $request, Corrida $corrida): Motorista|Passageiro
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof Motorista || $usuario instanceof Passageiro, 403);
        abort_unless($this->service->perfilParticipante($corrida, $usuario), 404, 'Corrida não encontrada para este usuário.');

        return $usuario;
    }

    public function index(Request $request, Corrida $corrida)
    {
        $this->participante($request, $corrida);

        return response()->json($this->service->listar($corrida, $this->cursores($request), true));
    }

    public function store(Request $request, Corrida $corrida)
    {
        $usuario = $this->participante($request, $corrida);
        $dados = $request->validate([
            'conteudo' => 'required|string|max:4000', 'client_message_id' => 'required|uuid',
            'autor_tipo' => 'prohibited', 'autor_nome' => 'prohibited', 'user_id' => 'prohibited',
            'gestor_id' => 'prohibited', 'imagens' => 'prohibited',
        ]);
        $texto = trim($dados['conteudo']);
        abort_if($texto === '', 422, 'Escreva uma mensagem.');
        $mensagem = $this->service->enviar($corrida, $usuario, $texto, $dados['client_message_id']);

        return response()->json(['data' => $mensagem->dados()], 201);
    }

    public function historicoTicket(Request $request, ChamadoAjuda $ticket)
    {
        $gestor = $request->user();
        abort_unless($gestor instanceof Gestor && $gestor->podeAcessarTicket($ticket), 404);
        $corrida = $ticket->corrida;
        abort_unless($corrida, 404, 'Este ticket não possui uma corrida vinculada.');

        $resultado = $this->service->listar($corrida, $this->cursores($request));
        $resultado['corrida'] += (new DetalhesCorridaResource($corrida))->resolve($request);

        return response()->json($resultado);
    }
}
