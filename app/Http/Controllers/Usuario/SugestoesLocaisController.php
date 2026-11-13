<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSugestaoLocalRequest;
use App\Models\SugestaoLocal;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;

class SugestoesLocaisController extends Controller
{
    public function store(StoreSugestaoLocalRequest $request): JsonResponse
    {
        $sugestao = SugestaoLocal::create([
            ...$request->validated(),
            'passageiro_id' => (PerfilLogado::passageiro($request) ?? abort(403, 'Sugestões de lugar são do passageiro.'))->id,
        ]);

        return response()->json(['data' => $sugestao->fresh()], 201);
    }
}
