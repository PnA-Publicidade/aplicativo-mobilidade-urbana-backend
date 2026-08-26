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
        $motorista = Motorista::create($request->input());

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
        $motorista->update($request->input());

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
        $motoristaVeiculo = MotoristaVeiculo::create($request->input());

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
