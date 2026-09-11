<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Cookie;

class RefreshController extends Controller
{
    /**
     * Troca um token expirado por um novo, sem obrigar o usuário a logar de novo.
     *
     * O TTL do token de acesso é de 60 minutos; o refresh_ttl, de 14 dias. Sem
     * esta rota o app derrubava a sessão de hora em hora — qualquer 401 caía
     * direto no logout, e quem estava montando uma corrida perdia o itinerário.
     *
     * Não fica atrás do middleware auth:jwt de propósito: o token que chega aqui
     * já está expirado, e o guard rejeitaria antes de dar chance de renovar.
     */
    public function __invoke(): JsonResponse
    {
        /** @var JWTGuard $guard */
        $guard = auth('jwt');

        try {
            // refresh() aceita token expirado enquanto estiver dentro do
            // refresh_ttl, e invalida o antigo (fica na blacklist)
            $token = $guard->refresh();
        } catch (JWTException $e) {
            // passou do refresh_ttl, token malformado, ausente ou já
            // invalidado — aí é logout mesmo
            return response()->json([
                'message' => 'Sessão expirada. Faça login novamente.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'token' => $token,
        ])->withCookie($this->tokenCookie($token, $guard->getTTL()));
    }

    private function tokenCookie(string $token, int $ttlMinutes): Cookie
    {
        return cookie(
            name: 'token',
            value: $token,
            minutes: $ttlMinutes,
            path: '/',
            domain: null,
            secure: app()->environment('production'),
            httpOnly: true,
            raw: false,
            sameSite: 'Lax'
        );
    }
}
