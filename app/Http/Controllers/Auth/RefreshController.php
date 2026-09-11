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
    public function __invoke(): JsonResponse
    {
        /** @var JWTGuard $guard */
        $guard = auth('jwt');

        try {
            $token = $guard->refresh();
        } catch (JWTException $e) {
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
