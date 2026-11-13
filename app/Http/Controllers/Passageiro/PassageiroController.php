<?php

namespace App\Http\Controllers\Passageiro;

use App\Http\Controllers\Controller;
use App\Models\Passageiro;
use App\Services\CadastrarPerfilUsuarioService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PassageiroController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, Passageiro>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return $this->listar($request, Passageiro::query());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, CadastrarPerfilUsuarioService $cadastro): JsonResponse
    {
        $passageiro = $cadastro->executar($request, 'passageiro');

        return response()->json(['success' => true, 'message' => 'Passageiro cadastrado com sucesso.', 'data' => $passageiro], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Passageiro $passageiro): Passageiro
    {
        return $passageiro;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Passageiro $passageiro): JsonResponse
    {
        $passageiro->update($request->validate(CadastrarPerfilUsuarioService::regrasPessoais('passageiros', $passageiro->id)));

        return response()->json(['success' => true, 'message' => 'Registro atualizado com sucesso', 'data' => $passageiro]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Passageiro $passageiro): void
    {
        //
    }

    /**
     * @return LengthAwarePaginator<int, Passageiro>
     */
    public function passageirosArquivados(Request $request): LengthAwarePaginator
    {
        return $this->listar($request, Passageiro::onlyTrashed());
    }

    private function listar(Request $request, Builder $query): LengthAwarePaginator
    {
        $dados = $request->validate(['search' => 'nullable|string|max:255', 'rowsPerPage' => 'sometimes|integer|between:0,50']);
        $busca = trim($dados['search'] ?? '');
        if ($busca !== '') {
            $termo = '%'.$busca.'%';
            $cpf = preg_match('/^[\d\s.-]+$/', $busca) ? preg_replace('/\D/', '', $busca) : $busca;
            $query->where(function (Builder $consulta) use ($termo, $cpf): void {
                $consulta->where('name', 'like', $termo)->orWhere('cpf', 'like', '%'.$cpf.'%')
                    ->orWhere('email', 'like', $termo)->orWhere('telefone', 'like', $termo);
            });
        }

        return $query->orderByDesc('id')->paginate(($dados['rowsPerPage'] ?? 5) ?: 50);
    }
}
