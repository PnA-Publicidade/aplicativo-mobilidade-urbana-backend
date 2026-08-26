<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class LogoutController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        /** @var JWTGuard $guard */
        $guard = auth('jwt');
        $guard->logout();

        return response()->json(['message' => 'Logout realizado com sucesso']);
    }
}
