<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\Veiculo;
use App\Services\CompartilhamentoVeiculoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VeiculoUsuariosController extends Controller
{
    public function __invoke(Request $request, int $veiculoId): JsonResponse
    {
        Veiculo::findOrFail($veiculoId);
        $dados = $request->validate([
            'search' => 'nullable|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'somente_vinculados' => 'sometimes|boolean',
            'rowsPerPage' => 'sometimes|integer|in:5,10,15,25',
        ]);
        $busca = trim($dados['search'] ?? '');
        $cpf = preg_replace('/\D/', '', $busca);

        $motoristas = Motorista::query()
            ->select(['id', 'name', 'cpf', 'foto', 'status'])
            ->when($request->boolean('somente_vinculados'), fn ($query) => $query
                ->whereIn('id', MotoristaVeiculo::select('motorista_id')->where('veiculo_id', $veiculoId)))
            ->addSelect(['vinculado' => MotoristaVeiculo::selectRaw('1')
                ->whereColumn('motorista_id', 'motoristas.id')
                ->where('veiculo_id', $veiculoId)->limit(1)])
            ->when($busca !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$busca.'%')
                ->orWhere('cpf', 'like', '%'.($cpf !== '' ? $cpf : $busca).'%')))
            ->orderBy('id')
            ->paginate($dados['rowsPerPage'] ?? 15);

        return response()->json([
            ...$motoristas->toArray(),
            'total_vinculados' => MotoristaVeiculo::where('veiculo_id', $veiculoId)->count(),
            'limite_motoristas' => CompartilhamentoVeiculoService::LIMITE_MOTORISTAS,
        ]);
    }
}
