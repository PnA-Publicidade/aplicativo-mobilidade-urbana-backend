<?php

namespace App\Support;

use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Http\Request;

/**
 * Quem está logado nos apps: o motorista ou o passageiro, cada um com o próprio
 * token (guards motoristas e passageiros). Rota de um app chamada com o token
 * do outro recebe null aqui, como antes recebia quem não tinha aquele perfil.
 */
class PerfilLogado
{
    public static function motorista(Request $request): ?Motorista
    {
        $perfil = $request->user();

        return $perfil instanceof Motorista ? $perfil : null;
    }

    public static function passageiro(Request $request): ?Passageiro
    {
        $perfil = $request->user();

        return $perfil instanceof Passageiro ? $perfil : null;
    }

    /** o perfil logado, de qualquer um dos apps */
    public static function de(Request $request): Motorista|Passageiro
    {
        $perfil = $request->user();
        abort_unless($perfil instanceof Motorista || $perfil instanceof Passageiro, 401, 'Não autenticado.');

        return $perfil;
    }
}
