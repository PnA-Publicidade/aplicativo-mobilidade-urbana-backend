<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');

        /** @var JWTGuard $guard */
        $guard = auth('jwt');

        if (! ($user = User::where('email', $email)->first())) {
            return response()->json([
                'message' => 'Email ou senha inválidos',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $token = $guard->attempt(['email' => $email, 'password' => $password]);

        if (! is_string($token)) {
            return response()->json([
                'message' => 'Email ou senha inválidos',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'telefone' => $user->telefone,
                'cpf' => $user->cpf,
                'data_nascimento' => $user->data_nascimento,
                'foto' => $user->foto,
            ],
            'message' => 'Login realizado com sucesso.',
            'token' => $token,
        ])->withCookie($this->tokenCookie($token, $guard->getTTL()));
    }

    public function enviarCodigo(Request $request): JsonResponse
    {
        $request->validate([
            'telefone' => 'required',
        ]);
        $telefone = preg_replace('/\D/', '', $request->string('telefone')->toString());

        // código fake
        $codigo = random_int(1000, 9999);

        Cache::put("codigo_verificacao:$telefone", $codigo, 60);

        return response()->json([
            'message' => 'Código enviado',
            'codigo' => $codigo, // TEMPORÁRIO
        ]);
    }

    public function verificarCodigo(Request $request): JsonResponse
    {
        $request->validate([
            'telefone' => 'required',
            'codigo' => 'required',
        ]);

        $telefone = preg_replace('/\D/', '', $request->string('telefone')->toString());

        $codigo = preg_replace('/\D/', '', $request->string('codigo')->toString());

        $registro = Cache::get("codigo_verificacao:$telefone");

        if (! $registro || (string) $registro !== $codigo) {
            return response()->json([
                'message' => 'Código inválido',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // procura usuário
        $user = User::where('telefone', $telefone)->first();

        if (! $user) {
            return response()->json([
                'message' => 'Usuário não encontrado',
            ], Response::HTTP_UNAUTHORIZED);
        }

        /** @var JWTGuard $guard */
        $guard = auth('jwt');

        // gera token JWT
        $token = $guard->login($user);

        // remove código após uso
        Cache::forget("codigo_verificacao:$telefone");

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'telefone' => $user->telefone,
                'cpf' => $user->cpf,
                'data_nascimento' => $user->data_nascimento,
                'foto' => $user->foto,
            ],
            'message' => 'Login realizado com sucesso.',
            'token' => $token,
        ])->withCookie($this->tokenCookie($token, $guard->getTTL()));
    }

    public function verificaSeContaExiste(Request $request): JsonResponse
    {
        $request->validate([
            'telefone' => 'required',
        ]);
        $telefone = preg_replace('/\D/', '', $request->string('telefone')->toString());
        $user = User::where('telefone', $telefone)->first();

        return response()->json([
            'contaExiste' => $user !== null,
        ]);
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
