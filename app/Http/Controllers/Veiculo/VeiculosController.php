<?php

namespace App\Http\Controllers\Veiculo;

use App\Http\Controllers\Controller;
use App\Models\Veiculo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $dados = $request->validate([
            'marca' => 'required|string',
            'modelo' => 'required|string',
            'ano_fabricacao' => 'required|integer|digits:4',
            'ano_modelo' => 'required|integer|digits:4',
            'cor' => 'required|string',
            'placa' => [
                'required',
                'string',
                Rule::unique('veiculos')->where('renavam', $request->input('renavam')),
            ],
            'renavam' => 'required|string|max:11',
            'categoria' => 'required|string',
            'status' => 'required|string',
            'uf' => 'required|string|size:2',
        ]);

        $veiculo = Veiculo::create($dados);

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

        $dados = $request->validate([
            'marca' => 'sometimes|required|string',
            'modelo' => 'sometimes|required|string',
            'ano_fabricacao' => 'sometimes|required|integer|digits:4',
            'ano_modelo' => 'sometimes|required|integer|digits:4',
            'cor' => 'sometimes|required|string',
            'placa' => [
                'sometimes',
                'required',
                'string',
                Rule::unique('veiculos')
                    ->where('renavam', $request->input('renavam', $veiculo->renavam))
                    ->ignore($veiculo->id),
            ],
            'renavam' => 'sometimes|required|string|max:11',
            'categoria' => 'sometimes|required|string',
            'status' => 'sometimes|required|string',
            'uf' => 'sometimes|required|string|size:2',
        ]);

        $veiculo->update($dados);

        return response()->json([
            'success' => true,
            'message' => 'Registro atualizado com sucesso',
            'data' => $veiculo,
        ], 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Veiculo $veiculo): void
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
