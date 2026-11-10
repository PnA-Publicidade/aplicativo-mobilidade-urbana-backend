<?php

namespace App\Http\Controllers\Veiculo;

use App\Http\Controllers\Controller;
use App\Http\Requests\MarcaVeiculoRequest;
use App\Models\MarcaVeiculo;
use App\Models\ModeloVeiculo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarcaVeiculoController extends Controller
{
    private const MARCAS_POR_PAGINA = 10;

    /**
     * Marcas paginadas, com a quantidade de modelos de cada uma. Com filtro, só
     * entram as marcas que têm modelo dentro dele, e a contagem mostra quantos
     * modelos batem. `modelos_total` soma os modelos de todas as páginas.
     */
    public function index(Request $request): JsonResponse
    {
        ['tipo' => $tipo, 'busca' => $busca] = $this->filtros($request);

        $marcas = MarcaVeiculo::query()
            ->withCount(['modelos' => fn (Builder $q) => $q->filtrar($tipo, $busca)])
            ->when($tipo !== null || $busca !== '', function (Builder $q) use ($tipo, $busca): void {
                $q->where(function (Builder $marca) use ($tipo, $busca): void {
                    $marca->whereHas('modelos', fn (Builder $m) => $m->filtrar($tipo, $busca));

                    // marca recém-criada ainda sem modelos também aparece na busca pelo nome
                    if ($tipo === null) {
                        $marca->orWhere('nome', 'like', '%'.$busca.'%');
                    }
                });
            })
            ->orderBy('nome')
            ->paginate(self::MARCAS_POR_PAGINA);

        return response()->json([
            ...$marcas->toArray(),
            // os modelos que batem com o filtro estão todos em alguma marca da lista
            'modelos_total' => ModeloVeiculo::filtrar($tipo, $busca)->count(),
        ]);
    }

    /** Modelos de uma marca, com o mesmo filtro da lista de marcas. */
    public function modelos(Request $request, MarcaVeiculo $marca): JsonResponse
    {
        ['tipo' => $tipo, 'busca' => $busca] = $this->filtros($request);

        return response()->json([
            'data' => $marca->modelos()->filtrar($tipo, $busca)->orderBy('modelo')->get(),
        ]);
    }

    public function store(MarcaVeiculoRequest $request): JsonResponse
    {
        $marca = MarcaVeiculo::create($request->validated());

        return response()->json(['message' => 'Marca cadastrada.', 'data' => $marca->loadCount('modelos')], 201);
    }

    public function show(MarcaVeiculo $marca): JsonResponse
    {
        return response()->json(['data' => $marca->loadCount('modelos')]);
    }

    public function update(MarcaVeiculoRequest $request, MarcaVeiculo $marca): JsonResponse
    {
        $marca->update($request->validated());

        return response()->json(['message' => 'Marca atualizada.', 'data' => $marca->loadCount('modelos')]);
    }

    /** Remove a marca e todos os modelos dela. */
    public function destroy(MarcaVeiculo $marca): JsonResponse
    {
        $modelos = $marca->modelos()->count();
        $marca->delete();

        return response()->json([
            'message' => $modelos > 0 ? "Marca e $modelos modelos removidos." : 'Marca removida.',
        ]);
    }

    /**
     * @return array{tipo: string|null, busca: string}
     */
    private function filtros(Request $request): array
    {
        $dados = $request->validate([
            'tipo' => 'nullable|in:carro,moto',
            'search' => 'nullable|string|max:255',
            'page' => 'sometimes|integer|min:1',
        ]);

        return ['tipo' => $dados['tipo'] ?? null, 'busca' => trim($dados['search'] ?? '')];
    }
}
