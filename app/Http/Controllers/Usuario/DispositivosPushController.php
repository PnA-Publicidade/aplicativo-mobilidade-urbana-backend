<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Models\DispositivoPush;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// os apps registram o token do FCM do aparelho depois do login e o tiram ao sair
class DispositivosPushController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'token' => 'required|string|max:512',
            'perfil' => 'sometimes|in:passageiro,motorista',
            'plataforma' => 'sometimes|in:android,ios',
        ]);

        // o token é do aparelho: se outra conta entrou nele, passa a ser desta
        $perfil = PerfilLogado::de($request);
        DispositivoPush::updateOrCreate(
            ['token' => $dados['token']],
            [
                'perfil' => $perfil->perfilDoApp(),
                'perfil_id' => $perfil->id,
                'plataforma' => $dados['plataforma'] ?? 'android',
            ],
        );

        return response()->json(['message' => 'Aparelho registrado.'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $dados = $request->validate(['token' => 'required|string|max:512']);

        DispositivoPush::where('token', $dados['token'])
            ->where('perfil', PerfilLogado::de($request)->perfilDoApp())
            ->where('perfil_id', PerfilLogado::de($request)->id)
            ->delete();

        return response()->json(null, 204);
    }
}
