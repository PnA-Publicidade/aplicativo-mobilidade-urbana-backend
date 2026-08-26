<?php

namespace App\Http\Controllers\Veiculo;

use App\Http\Controllers\Controller;
use App\Models\Veiculo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VeiculosController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, Veiculo>
     */
    public function index(): LengthAwarePaginator
    {
        return Veiculo::orderBy('id', 'desc')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $veiculo = Veiculo::create($request->input());

        return response()->json([
            'success' => true,
            'message' => 'Registro realizado com sucesso',
            'data' => $veiculo,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $veiculoId): Veiculo
    {
        return Veiculo::findOrFail($veiculoId);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $veiculoId): JsonResponse
    {
        $veiculo = Veiculo::findOrFail($veiculoId);
        $veiculo->update($request->input());

        return response()->json([
            'success' => true,
            'message' => 'Registro atualizado com sucesso',
            'data' => $veiculo,
        ], 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Veiculo $veiculos): void
    {
        //
    }

    /**
     * @return Collection<int, Veiculo>
     */
    public function veiculoPorPlaca(string $placa): Collection
    {
        return Veiculo::where('placa', 'ilike', "%{$placa}%")->get();
    }
}
