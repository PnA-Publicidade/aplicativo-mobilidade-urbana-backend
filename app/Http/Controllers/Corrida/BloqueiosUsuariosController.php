<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Services\BloqueioUsuarioService;
use App\Support\ImagemDoSelo;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bloqueio entre motorista e passageiro. `perfil` diz com qual dos dois a
 * pessoa está usando o app (quem tem os dois perfis usa o mesmo login).
 */
class BloqueiosUsuariosController extends Controller
{
    public function __construct(private readonly BloqueioUsuarioService $bloqueios) {}

    /** Quem eu bloqueei, com o contexto da avaliação que dei na corrida. */
    public function index(Request $request): JsonResponse
    {
        [$perfil, $perfilId] = $this->quem($request);

        return response()->json($this->bloqueios->listar($perfil, $perfilId));
    }

    public function store(Request $request): JsonResponse
    {
        [$perfil, $perfilId] = $this->quem($request);
        $dados = $request->validate(['corrida_id' => 'required|integer']);

        try {
            $id = $this->bloqueios->bloquear($perfil, $perfilId, (int) $dados['corrida_id']);
        } catch (RuntimeException $erro) {
            return response()->json(['message' => $erro->getMessage()], $erro->getCode() === 409 ? 409 : 404);
        }

        return response()->json(['id' => $id], 201);
    }

    public function destroy(Request $request, int $bloqueio): JsonResponse
    {
        [$perfil, $perfilId] = $this->quem($request);

        if (! $this->bloqueios->desbloquear($perfil, $perfilId, $bloqueio)) {
            return response()->json(['message' => 'Bloqueio não encontrado.'], 404);
        }

        return response()->json(null, 204);
    }

    /**
     * Selos que dá para escolher ao avaliar. `perfil` é quem vai RECEBER o selo:
     * o passageiro escolhe os do motorista e o motorista, os do passageiro.
     */
    public function selos(Request $request): JsonResponse
    {
        $dados = $request->validate(['perfil' => 'required|in:motorista,passageiro']);

        return response()->json([
            'data' => DB::table('selos_avaliacao')
                ->where('perfil_alvo', $dados['perfil'])
                ->where('ativo', true)
                ->orderBy('ordem')->orderBy('id')
                ->get(['chave', 'titulo', 'descricao', 'icone', 'imagem_path', 'imagem_url'])
                // `imagem`: o arquivo enviado na gestão, senão a URL cadastrada, senão nulo (o app usa o emoji)
                ->map(fn ($selo) => [
                    'chave' => $selo->chave,
                    'titulo' => $selo->titulo,
                    'descricao' => $selo->descricao,
                    'icone' => $selo->icone,
                    'imagem' => ImagemDoSelo::url($selo->imagem_path, $selo->imagem_url),
                ])->values(),
        ]);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function quem(Request $request): array
    {
        $dados = $request->validate(['perfil' => 'required|in:motorista,passageiro']);

        $registro = $dados['perfil'] === BloqueioUsuarioService::MOTORISTA
            ? PerfilLogado::motorista($request)
            : PerfilLogado::passageiro($request);

        abort_if($registro === null, 403, 'Cadastro não encontrado.');

        return [$dados['perfil'], (int) $registro->id];
    }
}
