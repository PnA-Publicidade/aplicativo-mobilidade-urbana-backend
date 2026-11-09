<?php

namespace App\Http\Controllers\Veiculo;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModeloVeiculoRequest;
use App\Models\ModeloVeiculo;
use Illuminate\Http\JsonResponse;

/**
 * Cadastro de um modelo. A lista de modelos fica em MarcaVeiculoController::modelos,
 * agrupada por marca.
 */
class ModeloVeiculoController extends Controller
{
    public function store(ModeloVeiculoRequest $request): JsonResponse
    {
        $modelo = ModeloVeiculo::create($request->validated());

        return response()->json(['message' => 'Modelo cadastrado.', 'data' => $modelo], 201);
    }

    public function show(ModeloVeiculo $modelo): JsonResponse
    {
        return response()->json(['data' => $modelo]);
    }

    public function update(ModeloVeiculoRequest $request, ModeloVeiculo $modelo): JsonResponse
    {
        $modelo->update($request->validated());

        return response()->json(['message' => 'Modelo atualizado.', 'data' => $modelo]);
    }

    public function destroy(ModeloVeiculo $modelo): JsonResponse
    {
        $modelo->delete();

        return response()->json(['message' => 'Modelo removido.']);
    }
}
