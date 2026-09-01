<?php

namespace App\Http\Controllers\Motorista;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MotoristaController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, Motorista>
     */
    public function index(): LengthAwarePaginator
    {
        return Motorista::with('user')->orderBy('id', 'desc')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'user_id' => 'required|integer|exists:users,id|unique:motoristas,user_id',
            'cnh_numero' => 'required|string',
            'cnh_categoria' => 'required|string',
            'cnh_expiracao' => 'required|date',
            'ear' => 'required|boolean',
        ]);

        $motorista = Motorista::create($dados);

        return response()->json([
            'success' => true,
            'message' => 'Registro realizado com sucesso',
            'data' => $motorista,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $motoristaid): Motorista
    {
        return Motorista::with('user')->findOrFail($motoristaid);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $motoristaid): JsonResponse
    {
        $motorista = Motorista::findOrFail($motoristaid);

        $dados = $request->validate([
            'cnh_numero' => 'sometimes|required|string',
            'cnh_categoria' => 'sometimes|required|string',
            'cnh_expiracao' => 'sometimes|required|date',
            'ear' => 'sometimes|required|boolean',
        ]);

        $motorista->update($dados);

        return response()->json([
            'success' => true,
            'message' => 'Registro atualizado com sucesso',
            'data' => $motorista,
        ], 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Motorista $motorista): void
    {
        //
    }

    public function adicionarVeiculoAoMotorista(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'motorista_id' => 'required|integer|exists:motoristas,id',
            'veiculo_id' => 'required|integer|exists:veiculos,id',
        ]);

        $motoristaVeiculo = MotoristaVeiculo::create($dados);

        return response()->json([
            'success' => true,
            'message' => 'Registro realizado com sucesso',
            'data' => $motoristaVeiculo,
        ], 201);
    }

    /**
     * @return LengthAwarePaginator<int, MotoristaVeiculo>
     */
    public function motoristaVeiculos(int $motoristaid): LengthAwarePaginator
    {
        return MotoristaVeiculo::with(['motorista', 'veiculo'])
            ->where('motorista_id', $motoristaid)->paginate();
    }
}
