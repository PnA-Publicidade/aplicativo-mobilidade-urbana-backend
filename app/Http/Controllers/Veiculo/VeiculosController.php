<?php

namespace App\Http\Controllers\Veiculo;

use App\Http\Controllers\Controller;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\Veiculo;
use App\Support\PerfilLogado;
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
    public function index(Request $request): LengthAwarePaginator
    {
        $dados = $request->validate([
            'search' => 'nullable|string|max:255',
            'rowsPerPage' => 'sometimes|integer|between:1,100',
            'page' => 'sometimes|integer|min:1',
        ]);
        $query = $request->user() instanceof Gestor ? Veiculo::query() : Veiculo::visiveis();
        $busca = trim($dados['search'] ?? '');
        if ($busca !== '') {
            $query->where(function ($consulta) use ($busca): void {
                foreach (['marca', 'modelo', 'placa', 'renavam', 'cor'] as $campo) {
                    $consulta->orWhere($campo, 'like', '%'.$busca.'%');
                }
            });
        }

        return $query->orderBy('id', 'desc')->paginate($dados['rowsPerPage'] ?? 15);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $request->merge(['cpf_cnpj_proprietario' => preg_replace('/\D/', '', (string) $request->input('cpf_cnpj_proprietario'))]);
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
            'chassi' => ['nullable', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/'],
            'cpf_cnpj_proprietario' => ['required', 'string', 'regex:/^(?:[0-9]{11}|[0-9]{14})$/'],
            'nome_proprietario' => 'nullable|string|max:255',
            'categoria' => 'required|string|in:carro,moto',
            'eletrico' => 'sometimes|boolean|declined_if:categoria,moto',
            'taxi' => 'sometimes|boolean',
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
            'chassi' => ['nullable', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/'],
            'categoria' => 'sometimes|required|string|in:carro,moto',
            'eletrico' => 'sometimes|boolean',
            'taxi' => 'sometimes|boolean',
            'status' => 'sometimes|required|string',
            'uf' => 'sometimes|required|string|size:2',
        ]);

        // ninguém aprova elétrico ou táxi no próprio veículo
        $decideCategoria = array_key_exists('eletrico', $dados) || array_key_exists('taxi', $dados);
        $motoristaDoUsuario = PerfilLogado::motorista($request)?->id;

        if ($decideCategoria && $motoristaDoUsuario !== null && MotoristaVeiculo::where('motorista_id', $motoristaDoUsuario)
            ->where('veiculo_id', $veiculo->id)
            ->exists()) {
            return response()->json([
                'message' => 'Você não pode aprovar elétrico ou táxi no seu próprio veículo.',
            ], 403);
        }

        $veiculo->fill($dados);
        // a gestão decidindo elétrico ou táxi encerra o pedido do motorista
        if (array_key_exists('eletrico', $dados)) {
            $veiculo->eletrico_solicitado = false;
        }
        if (array_key_exists('taxi', $dados)) {
            $veiculo->taxi_solicitado = false;
        }
        // não existe moto elétrica: trocar para moto tira a marcação
        if ($veiculo->categoria === 'moto') {
            $veiculo->eletrico = false;
            $veiculo->eletrico_solicitado = false;
        }
        $veiculo->save();

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
        return Veiculo::liberados()->where('placa', 'like', "%{$placa}%")->get();
    }
}
