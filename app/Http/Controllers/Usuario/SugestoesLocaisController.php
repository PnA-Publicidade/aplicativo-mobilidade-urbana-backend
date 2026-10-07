<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSugestaoLocalRequest;
use App\Models\SugestaoLocal;
use Illuminate\Http\JsonResponse;

class SugestoesLocaisController extends Controller
{
    public function store(StoreSugestaoLocalRequest $request): JsonResponse
    {
        $sugestao = SugestaoLocal::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $sugestao->fresh()], 201);
    }
}
