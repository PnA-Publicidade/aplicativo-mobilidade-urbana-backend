<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Services\GanhosMotoristaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GanhosMotoristasController extends Controller
{
    public function motoristas(Request $request)
    {
        $dados = $request->validate(['search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $query = Motorista::withTrashed()->with(['user' => fn ($q) => $q->withTrashed()]);
        if ($search = trim($dados['search'] ?? '')) {
            $cpf = preg_replace('/\D/', '', $search);
            $query->where(fn ($q) => $q->where('nome', 'like', '%'.$search.'%')
                ->orWhere('cpf', 'like', '%'.($cpf !== '' ? $cpf : $search).'%')
                ->orWhereHas('user', fn ($u) => $u->withTrashed()->where('name', 'like', '%'.$search.'%')));
        }
        $pagina = $query->orderBy('nome')->orderBy('id')->paginate(20);
        $pagina->setCollection($pagina->getCollection()->map(fn ($m) => ['id' => $m->id, 'nome' => $m->nome ?? $m->user?->name, 'foto' => $m->foto]));

        return response()->json($pagina);
    }

    public function resumo(Request $request, GanhosMotoristaService $service)
    {
        [$motorista, $dados] = $this->filtros($request);
        $resultado = $service->consultar($motorista, $dados);
        unset($resultado['itens'], $resultado['solicitacoes']);

        return response()->json(['data' => $resultado]);
    }

    public function detalhes(Request $request, GanhosMotoristaService $service)
    {
        [$motorista, $dados] = $this->filtros($request);
        abort_if($dados['periodo'] === 'anual', 422, 'O período anual apresenta somente resumo e estatísticas.');
        $visao = $request->validate([
            'visao' => ['required', Rule::in(['solicitacoes', 'demonstrativo', ...GanhosMotoristaService::COMPONENTES])],
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        $resultado = $service->consultar($motorista, $dados, false);
        $itens = $visao['visao'] === 'solicitacoes' ? $resultado['solicitacoes'] : $resultado['itens'];
        if (in_array($visao['visao'], GanhosMotoristaService::COMPONENTES, true)) {
            $itens = array_values(array_filter($itens, fn ($item) => $item['categoria'] === $visao['visao']));
        }
        $total = count($itens);
        $porPagina = (int) ($visao['per_page'] ?? 20);
        $pagina = (int) ($visao['page'] ?? 1);

        return response()->json(['data' => array_slice($itens, ($pagina - 1) * $porPagina, $porPagina),
            'meta' => ['total' => $total, 'current_page' => $pagina, 'last_page' => max(1, (int) ceil($total / $porPagina))]]);
    }

    protected function filtros(Request $request): array
    {
        $dados = $request->validate([
            'motorista_id' => 'required|integer|min:1',
            'data' => 'required|date_format:Y-m-d',
            'periodo' => ['required', Rule::in(['diario', 'semanal', 'mensal', 'anual'])],
            'tipo_servico' => ['required', Rule::in(['todos', 'corrida', 'entrega'])],
        ]);

        return [Motorista::withTrashed()->with(['user' => fn ($q) => $q->withTrashed()])->findOrFail($dados['motorista_id']), $dados];
    }
}
