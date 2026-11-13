<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Support\FotoDePerfil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login de cada app (/api/motorista/auth/... e /api/passageiro/auth/...), como
 * o dos gestores: procura só na tabela daquele app e devolve um token que não
 * vale no outro app. O perfil vem da rota.
 */
class AutenticacaoAppController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:1024',
        ]);
        $perfil = $this->modelo($request)::withTrashed()->where('email', $dados['email'])->first();

        if ($perfil === null || ! filled($perfil->password) || ! Hash::check($dados['password'], $perfil->password)) {
            return response()->json(['message' => 'E-mail ou senha inválidos.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->entrar($request, $perfil);
    }

    public function enviarCodigo(Request $request): JsonResponse
    {
        $telefone = $this->telefoneNormalizado($request);
        $codigo = random_int(1000, 9999);

        Cache::put("codigo_verificacao:$telefone", $codigo, now()->addMinutes(30));

        $resposta = ['message' => 'Código enviado.'];

        // apoio aos botões de desenvolvimento sem expor o código em produção
        if (app()->environment(['local', 'testing'])) {
            $resposta['codigo'] = $codigo;
        }

        return response()->json($resposta);
    }

    public function verificarCodigo(Request $request): JsonResponse
    {
        $telefone = $this->telefoneNormalizado($request);
        $request->validate(['codigo' => 'required|digits:4']);
        $codigo = preg_replace('/\D/', '', $request->string('codigo')->toString());
        $registro = Cache::get("codigo_verificacao:$telefone");

        if (! $registro || (string) $registro !== $codigo) {
            return response()->json(['message' => 'Código inválido ou expirado.'], Response::HTTP_UNAUTHORIZED);
        }

        $perfil = $this->modelo($request)::withTrashed()->where('telefone', $telefone)->first();
        if ($perfil === null) {
            // o app segue para o cadastro
            return response()->json(['message' => 'Conta não encontrada para este telefone.'], Response::HTTP_UNAUTHORIZED);
        }
        Cache::forget("codigo_verificacao:$telefone");

        return $this->entrar($request, $perfil);
    }

    public function verificaSeContaExiste(Request $request): JsonResponse
    {
        $telefone = $this->telefoneNormalizado($request);

        return response()->json([
            'contaExiste' => $this->modelo($request)::withTrashed()->where('telefone', $telefone)->exists(),
        ]);
    }

    /** antecipa a regra de e-mail do cadastro, para o app barrar já na tela do e-mail */
    public function validaEmailCadastro(Request $request): JsonResponse
    {
        $request->validate(
            ['email' => ['required', 'email', 'max:255', Rule::unique($this->tabela($request), 'email')]],
            ['email.unique' => 'Este e-mail já está cadastrado. Faça login ou use outro e-mail.'],
        );

        return response()->json(['disponivel' => true]);
    }

    public function register(Request $request): JsonResponse
    {
        $modelo = $this->modelo($request);
        $tabela = $this->tabela($request);
        $motorista = $modelo === Motorista::class;
        $dados = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique($tabela, 'email')],
            'password' => 'required|string|min:8|max:1024',
            'telefone' => ['nullable', 'string', 'max:20', Rule::unique($tabela, 'telefone')],
            // o motorista informa o CPF depois, com a CNH ou ao configurar o Pix
            'cpf' => [$motorista ? 'nullable' : 'required', 'string', 'size:11', Rule::unique($tabela, 'cpf')],
            'data_nascimento' => 'required|date|before_or_equal:'.now()->subYears(18)->toDateString(),
            'image' => 'sometimes|image|mimes:jpeg,png,jpg,webp|max:5120',
        ], [
            'email.unique' => 'Este e-mail já está cadastrado. Faça login ou use outro e-mail.',
            'telefone.unique' => 'Este telefone já está cadastrado. Entre com o código por SMS.',
            'cpf.unique' => 'Este CPF já está cadastrado.',
            'password.min' => 'A senha deve ter no mínimo 8 caracteres.',
            'data_nascimento.before_or_equal' => 'Você precisa ter pelo menos 18 anos para se cadastrar.',
        ]);
        $dados['telefone'] = filled($dados['telefone'] ?? null) ? preg_replace('/\D/', '', $dados['telefone']) : null;

        $perfil = new $modelo([
            ...array_intersect_key($dados, array_flip(['name', 'email', 'telefone', 'cpf', 'data_nascimento'])),
            'status' => $motorista ? 'pendente' : 'ativo',
        ]);
        $perfil->forceFill(['password' => $dados['password']])->save();
        FotoDePerfil::salvar($request, $perfil);

        return $this->entrar($request, $perfil, Response::HTTP_CREATED);
    }

    public function refresh(Request $request): JsonResponse
    {
        $modelo = $this->modelo($request);
        $guard = $this->guard($request);
        try {
            $jwt = app('tymon.jwt')->setRequest($request);
            $token = $jwt->getToken();
            if ($token === null) {
                throw new JWTException('Token ausente.');
            }
            // valida também o token vencido dentro da janela de renovação
            $payload = (clone $jwt->manager())->setRefreshFlow(true)->decode($token);
            $perfil = $modelo::query()->whereKey($payload->get('sub'))->first();
            if ($payload->get('prv') !== sha1($modelo) || $perfil === null || $perfil->indisponivelNoApp()) {
                throw new JWTException('Perfil não autenticado.');
            }
            $novoToken = $guard->setToken($token)->refresh();
        } catch (JWTException) {
            return response()->json(['message' => 'Sessão expirada. Faça login novamente.'], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json(['token' => $novoToken]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->guard($request)->logout();

        return response()->json(['message' => 'Logout realizado com sucesso']);
    }

    private function entrar(Request $request, Motorista|Passageiro $perfil, int $status = Response::HTTP_OK): JsonResponse
    {
        if ($perfil->indisponivelNoApp()) {
            return response()->json([
                'message' => 'Esta conta está indisponível. Entre em contato com o suporte.',
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'user' => $perfil->dadosDoApp(),
            'message' => 'Login realizado com sucesso.',
            'token' => $this->guard($request)->login($perfil),
        ], $status);
    }

    /** @return class-string<Motorista|Passageiro> */
    private function modelo(Request $request): string
    {
        return $request->route('perfil') === 'motorista' ? Motorista::class : Passageiro::class;
    }

    private function tabela(Request $request): string
    {
        return $request->route('perfil') === 'motorista' ? 'motoristas' : 'passageiros';
    }

    private function guard(Request $request): JWTGuard
    {
        /** @var JWTGuard */
        return auth($this->tabela($request));
    }

    private function telefoneNormalizado(Request $request): string
    {
        $request->validate(['telefone' => 'required|string|max:32']);
        $telefone = preg_replace('/\D/', '', $request->string('telefone')->toString());

        if ($telefone === null || strlen($telefone) < 10 || strlen($telefone) > 13) {
            throw ValidationException::withMessages(['telefone' => 'Informe um telefone válido.']);
        }

        return $telefone;
    }
}
