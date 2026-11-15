<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** "Como ter mais avaliações positivas?": as dicas que o motorista e o passageiro veem no perfil. */
class DicasAvaliacaoController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('dicas_avaliacao')->orderBy('ordem')->orderBy('id')->get()
                ->map(function ($dica) {
                    $dica->ativo = (bool) $dica->ativo;

                    return $dica;
                }),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'titulo' => 'required|string|min:2|max:80',
            'texto' => 'required|string|min:2|max:300',
            'perfil_alvo' => 'sometimes|in:motorista,passageiro',
            'ordem' => 'sometimes|integer|between:0,999',
            'ativo' => 'sometimes|boolean',
        ]);
        $dados['ordem'] ??= ((int) DB::table('dicas_avaliacao')->max('ordem')) + 1;

        $id = DB::table('dicas_avaliacao')->insertGetId([...$dados, 'perfil_alvo' => $dados['perfil_alvo'] ?? 'motorista', 'ativo' => $dados['ativo'] ?? true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(DB::table('dicas_avaliacao')->find($id), 201);
    }

    public function update(Request $request, int $dica): JsonResponse
    {
        if (! DB::table('dicas_avaliacao')->where('id', $dica)->exists()) {
            return response()->json(['message' => 'Dica não encontrada.'], 404);
        }

        $dados = $request->validate([
            'titulo' => 'sometimes|string|min:2|max:80',
            'texto' => 'sometimes|string|min:2|max:300',
            'perfil_alvo' => 'sometimes|in:motorista,passageiro',
            'ordem' => 'sometimes|integer|between:0,999',
            'ativo' => 'sometimes|boolean',
        ]);

        DB::table('dicas_avaliacao')->where('id', $dica)->update([...$dados, 'updated_at' => now()]);

        return response()->json(DB::table('dicas_avaliacao')->find($dica));
    }

    public function destroy(int $dica): JsonResponse
    {
        if (DB::table('dicas_avaliacao')->where('id', $dica)->delete() === 0) {
            return response()->json(['message' => 'Dica não encontrada.'], 404);
        }

        return response()->json(null, 204);
    }
}
