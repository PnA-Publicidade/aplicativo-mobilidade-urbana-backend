<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Models\Notificacao;
use App\Support\PerfilLogado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacoesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $consulta = $this->doUsuario($request);

        return response()->json([
            'data' => (clone $consulta)->latest('id')->limit(50)->get(['id', 'titulo', 'mensagem', 'lida_em', 'created_at']),
            'nao_lidas' => (clone $consulta)->whereNull('lida_em')->count(),
        ]);
    }

    public function marcarLidas(Request $request): JsonResponse
    {
        $this->doUsuario($request)
            ->whereNull('lida_em')
            ->update(['lida_em' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * As notificações do perfil do token (motorista ou passageiro). O ?perfil=
     * que os apps ainda mandam é o mesmo do token e não muda nada.
     *
     * @return Builder<Notificacao>
     */
    private function doUsuario(Request $request): Builder
    {
        $request->validate(['perfil' => 'sometimes|string|in:passageiro,motorista']);
        $perfil = PerfilLogado::de($request);

        return Notificacao::where('perfil', $perfil->perfilDoApp())->where('perfil_id', $perfil->id);
    }
}
