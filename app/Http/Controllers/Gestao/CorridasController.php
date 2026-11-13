<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Models\Corrida;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Corridas do painel: todas as corridas, não só as da conta logada (o
 * controller do app filtra pelo passageiro ou motorista do usuário).
 */
class CorridasController extends Controller
{
    private const RELACOES = [
        'produto:id,nome',
        'motorista:id,name,foto',
        'passageiro:id,name,foto',
        'veiculo:id,modelo,cor,placa',
        'corrida_destinos:id,corrida_id,tipo,ordem,endereco',
        'corrida_financeiro',
    ];

    /**
     * @return LengthAwarePaginator<int, Corrida>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        $dados = $request->validate([
            'search' => 'nullable|string|max:255',
            'rowsPerPage' => 'sometimes|integer|min:0|max:100',
        ]);
        $busca = trim($dados['search'] ?? '');
        // 0 no seletor do Quasar é "todos": limita para não carregar a tabela inteira
        $porPagina = (int) ($dados['rowsPerPage'] ?? 20) ?: 100;

        return Corrida::query()
            ->with(self::RELACOES)
            ->when($busca !== '', fn (Builder $consulta) => $consulta->where(fn (Builder $filtro) => $filtro
                ->where('codigo_corrida', 'like', "%{$busca}%")
                ->orWhere('id', ctype_digit($busca) ? (int) $busca : 0)
                ->orWhereHas('passageiro', fn (Builder $passageiro) => $passageiro->where('name', 'like', "%{$busca}%"))
                ->orWhereHas('motorista', fn (Builder $motorista) => $motorista->where('name', 'like', "%{$busca}%"))))
            ->orderByDesc('id')
            ->paginate($porPagina);
    }

    public function show(int $corrida): Corrida
    {
        return Corrida::with(self::RELACOES)->findOrFail($corrida);
    }
}
