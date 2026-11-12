<?php

namespace App\Http\Controllers\Motorista;

use App\Http\Controllers\Gestao\GanhosMotoristasController;
use App\Models\Motorista;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Seus ganhos" do app: o mesmo resumo e detalhamento da gestão, só que o
 * motorista vem do token e não da requisição, então ninguém vê os de outro.
 */
class GanhosController extends GanhosMotoristasController
{
    protected function filtros(Request $request): array
    {
        $dados = $request->validate([
            'data' => 'required|date_format:Y-m-d',
            'periodo' => ['required', Rule::in(['diario', 'semanal', 'mensal'])],
            'tipo_servico' => ['required', Rule::in(['todos', 'corrida', 'entrega'])],
        ]);
        $motorista = Motorista::with('user')->where('user_id', $request->user()->id)->first();
        abort_if($motorista === null, 403, 'Cadastro de motorista não encontrado.');

        return [$motorista, $dados];
    }
}
