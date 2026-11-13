<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaldoMotoristaResource;
use App\Models\SaldoMotorista;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaldoMotoristasController extends Controller
{
    public function index(Request $request)
    {
        $dados = $request->validate([
            'search' => 'nullable|string|max:100',
            'motorista_id' => 'nullable|integer|min:1',
            'tipo' => ['nullable', Rule::in(['entrada', 'saida'])],
            'categoria' => ['nullable', Rule::in(['descontos_taxas', 'ajustes_estornos', 'valor_corrida', 'ganho_corrida', 'recompensa', 'bonus', 'gorjeta', 'compensacao', 'ganhos_integrais', 'outro', 'transferencia', 'taxa_plataforma', 'desconto', 'ajuste', 'estorno'])],
            'status' => ['nullable', Rule::in(['confirmado', 'processando', 'falhou', 'cancelado'])],
            'data_inicio' => 'nullable|date_format:Y-m-d',
            'data_fim' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('data_inicio'), 'after_or_equal:data_inicio')],
            'timezone' => 'nullable|timezone:all',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        $query = SaldoMotorista::query()->with([
            'motorista' => fn ($q) => $q->withTrashed(),
            'corrida:id,codigo_corrida,produto_id,metodo_pagamento',
            'corrida.produto:id,nome',
        ]);
        if (! empty($dados['motorista_id'])) {
            $query->where('motorista_id', $dados['motorista_id']);
        }
        if ($search = trim($dados['search'] ?? '')) {
            $query->where(function (Builder $q) use ($search) {
                if (ctype_digit($search) && strlen($search) < 11) {
                    $q->where('motorista_id', $search);
                } else {
                    $q->whereHas('motorista', fn (Builder $m) => $this->buscarMotorista($m, $search));
                }
            });
        }
        if (! empty($dados['tipo'])) {
            $query->where('tipo', $dados['tipo']);
        }
        if (! empty($dados['categoria'])) {
            $query->filtrarCategoria($dados['categoria']);
        }
        if (! empty($dados['status'])) {
            $query->where(function (Builder $q) use ($dados) {
                $q->where('detalhes->status', $dados['status']);
                if ($dados['status'] === 'confirmado') {
                    $q->orWhereNull('detalhes->status');
                }
            });
        }
        $timezone = $dados['timezone'] ?? config('app.timezone');
        if (! empty($dados['data_inicio'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($dados['data_inicio'], $timezone)->startOfDay()->utc());
        }
        if (! empty($dados['data_fim'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($dados['data_fim'], $timezone)->startOfDay()->addDay()->utc());
        }

        return SaldoMotoristaResource::collection($query->orderByDesc('created_at')->orderByDesc('id')->paginate($dados['per_page'] ?? 20));
    }

    private function buscarMotorista(Builder $query, string $search): void
    {
        $cpf = preg_replace('/\D/', '', $search);
        $cpf = strlen($cpf) === 11 ? $cpf : $search;
        $query->where('name', 'like', '%'.$search.'%')
            ->orWhere('cpf', 'like', '%'.$cpf.'%');
    }

    public function show(int $movimentacao): SaldoMotoristaResource
    {
        $registro = SaldoMotorista::with([
            'motorista' => fn ($q) => $q->withTrashed(),
            'corrida.produto', 'corrida.corrida_financeiro',
        ])->findOrFail($movimentacao);

        return (new SaldoMotoristaResource($registro))->comDetalhes();
    }
}
