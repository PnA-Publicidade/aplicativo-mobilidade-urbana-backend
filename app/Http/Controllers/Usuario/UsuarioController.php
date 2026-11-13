<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CadastroUsuarioRequest;
use App\Models\Passageiro;
use App\Models\User;
use App\Services\PerfilUsuarioService;
use App\Services\ReputacaoService;
use App\Services\RestricaoNotaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class UsuarioController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        $dados = $request->validate([
            'search' => 'nullable|string|max:255',
            'rowsPerPage' => 'sometimes|integer|between:0,50',
        ]);

        $query = User::query();
        $busca = trim($dados['search'] ?? '');
        if ($busca !== '') {
            $termo = '%'.$busca.'%';
            $cpf = preg_match('/^[\d\s.-]+$/', $busca) ? preg_replace('/\D/', '', $busca) : '';
            $termoCpf = '%'.($cpf !== '' ? $cpf : $busca).'%';
            $query->where(function (Builder $consulta) use ($termo, $termoCpf): void {
                $consulta->where('name', 'like', $termo)->orWhere('cpf', 'like', $termoCpf);
            });
        }

        return $query->orderBy('id', 'desc')->paginate(($dados['rowsPerPage'] ?? 15) ?: 15);
    }

    public function usuarioLogado(Request $request, PerfilUsuarioService $perfis): JsonResponse
    {
        /** @var ?User $user */
        $user = Auth::user();

        if ($user === null) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $perfil = $perfis->solicitado($request);
        $registroPerfil = $perfis->garantir($user, $perfil);

        if ($perfis->indisponivel($registroPerfil)) {
            return response()->json([
                'message' => 'Este perfil está indisponível. Entre em contato com o suporte.',
            ], 403);
        }

        // nota que os motoristas deram ao passageiro (o menu do app mostra)
        $passageiroId = Passageiro::where('user_id', $user->id)->value('id');
        // nota base 5,0 até a primeira avaliação real; "novo" enquanto tem poucas corridas
        $reputacao = $passageiroId === null ? null : app(ReputacaoService::class)->doPassageiro((int) $passageiroId);
        $dados = $perfis->dados($user, $perfil, $registroPerfil);
        $dados['nota_passageiro'] = $reputacao['nota'] ?? ReputacaoService::notaBase();
        // o perfil do passageiro mostra quantas corridas ele já fez
        $dados['corridas_passageiro'] = $reputacao['corridas'] ?? 0;
        $dados['passageiro_novo'] = $reputacao['novo'] ?? true;
        $dados['corridas_para_deixar_de_ser_novo'] = ReputacaoService::corridasParaDeixarDeSerNovo();
        // aviso, suspensão ou bloqueio por nota baixa (em modo sombra não aparece alerta)
        $dados['situacao_nota'] = $passageiroId === null ? null : app(RestricaoNotaService::class)->situacao(RestricaoNotaService::PASSAGEIRO, (int) $passageiroId);

        return response()->json($dados);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, PerfilUsuarioService $perfis): JsonResponse
    {
        $dados = $request->validate([
            ...$this->regrasCadastro(),
        ], $this->mensagensCadastro());

        $user = User::create([
            'name' => $dados['name'],
            'data_nascimento' => $dados['data_nascimento'],
            'telefone' => $dados['telefone'] ?? null,
            'cpf' => $dados['cpf'],
            'email' => $dados['email'],
            'status' => 'ativo',
            'password' => bcrypt($dados['password']),
        ]);

        $perfil = PerfilUsuarioService::PASSAGEIRO;
        $registroPerfil = $perfis->garantir($user, $perfil);
        $this->criarImagemPerfil($request, $registroPerfil);

        // gera token JWT
        /** @var JWTGuard $guard */
        $guard = auth('jwt');
        $token = $guard->claims(['perfil' => $perfil])->login($user);

        return response()->json([
            'user' => $perfis->dados($user, $perfil, $registroPerfil),
            'token' => $token,
        ], 201);
    }

    /** Antecipa a regra de e-mail do cadastro, para o app barrar já na tela do e-mail. */
    public function validarEmailCadastro(Request $request): JsonResponse
    {
        $request->validate(
            ['email' => CadastroUsuarioRequest::REGRAS_EMAIL],
            ['email.unique' => 'Este e-mail já está cadastrado. Faça login ou use outro e-mail.'],
        );

        return response()->json(['disponivel' => true]);
    }

    public function register(CadastroUsuarioRequest $request, PerfilUsuarioService $perfis): JsonResponse
    {
        $dados = $request->validated();
        $perfil = $dados['perfil'];

        [$user, $registroPerfil] = DB::transaction(function () use ($dados, $perfil, $perfis): array {
            $user = User::create([
                'name' => $dados['name'],
                'data_nascimento' => $dados['data_nascimento'],
                'telefone' => $dados['telefone'] ?? null,
                'cpf' => $dados['cpf'] ?? null,
                'email' => $dados['email'],
                'status' => 'ativo',
                'password' => bcrypt($dados['password']),
            ]);

            return [$user, $perfis->garantir($user, $perfil)];
        });

        $this->criarImagemPerfil($request, $registroPerfil);

        // gera token JWT
        /** @var JWTGuard $guard */
        $guard = auth('jwt');
        $token = $guard->claims(['perfil' => $perfil])->login($user);

        return response()->json([
            'user' => $perfis->dados($user, $perfil, $registroPerfil),
            'token' => $token,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): User
    {
        return User::findOrFail($id);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $dados = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'telefone' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users', 'telefone')->ignore($user->id)],
            'cpf' => ['sometimes', 'required', 'digits:11', Rule::unique('users', 'cpf')->ignore($user->id)],
            'data_nascimento' => 'sometimes|required|date|before_or_equal:'.now()->subYears(18)->toDateString(),
        ]);
        $user->update($dados);

        return response()->json(['user' => $user->fresh()]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): void {}

    /**
     * O motorista não informa CPF no cadastro: ele é pedido depois, ao
     * configurar o recebimento (PIX).
     *
     * @return array<string, string>
     */
    private function regrasCadastro(bool $ehMotorista = false): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'telefone' => 'nullable|string|unique:users,telefone',
            'cpf' => $ehMotorista
                ? 'nullable|string|size:11|unique:users,cpf'
                : 'required|string|size:11|unique:users,cpf',
            'data_nascimento' => 'required|date|before_or_equal:'.now()->subYears(18)->toDateString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function mensagensCadastro(): array
    {
        return [
            'password.min' => 'A senha deve ter no mínimo 8 caracteres.',
            'data_nascimento.before_or_equal' => 'Você precisa ter pelo menos 18 anos para se cadastrar.',
            'numero_registro.required' => 'Informe o número de registro da sua CNH.',
            'cnh_categoria.in' => 'Categoria de CNH inválida.',
            'cnh_expiracao.after' => 'Sua CNH está vencida.',
        ];
    }

    private function criarImagemPerfil(Request $request, Model $perfil): void
    {
        $image = $request->file('image', null);

        if ($image) {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
            ]);

            $image = $request->file('image');
            $host = App::environment('local')
                ? $request->getSchemeAndHttpHost()
                : rtrim((string) config('app.url'), '/');
            $extension = $image->extension();
            $imageName = Str::uuid().'.'.$extension;

            File::ensureDirectoryExists(public_path('images'));

            $image->move(public_path('images'), $imageName);
            $perfil->setAttribute('foto', $host.'/images/'.$imageName);
            $perfil->saveOrFail();
        }
    }

    public function removerFotoPerfil(
        Request $request,
        string $id,
        PerfilUsuarioService $perfis,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        abort_unless((string) $user->id === $id, 403, 'Você só pode alterar a foto da própria conta.');
        $perfil = $perfis->garantir($user, $perfis->solicitado($request));
        abort_if($perfis->indisponivel($perfil), 403, 'Este perfil está indisponível.');
        $foto = $perfil->getAttribute('foto');
        $perfil->setAttribute('foto', null);
        $perfil->saveOrFail();
        $this->excluirImagemPerfil($foto);

        return response()->json([
            'success' => true,
            'message' => 'Foto removida com sucesso!',
        ]);
    }

    public function alterarFotoPerfil(
        Request $request,
        string $id,
        PerfilUsuarioService $perfis,
    ): JsonResponse {
        $request->validate(['image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120']);
        /** @var User $user */
        $user = $request->user();
        abort_unless((string) $user->id === $id, 403, 'Você só pode alterar a foto da própria conta.');
        $perfil = $perfis->garantir($user, $perfis->solicitado($request));
        abort_if($perfis->indisponivel($perfil), 403, 'Este perfil está indisponível.');
        $fotoAnterior = $perfil->getAttribute('foto');
        $this->criarImagemPerfil($request, $perfil);
        $this->excluirImagemPerfil($fotoAnterior);

        return response()->json([
            'user' => [
                'foto' => $perfil->getAttribute('foto'),
            ],
            'success' => true,
            'message' => 'Foto alterada com sucesso!',
        ]);
    }

    private function excluirImagemPerfil(?string $url): void
    {
        $caminho = $url === null ? null : parse_url($url, PHP_URL_PATH);

        if (! is_string($caminho) || ! preg_match('~^/images/([^/\\\\]+)$~', $caminho, $matches)) {
            return;
        }

        $nome = rawurldecode($matches[1]);

        if ($nome === '.' || $nome === '..' || str_contains($nome, '/') || str_contains($nome, '\\')) {
            return;
        }

        File::delete(public_path('images/'.$nome));
    }

    public function usuarioArquivar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'usuarios' => 'required|array|min:1|max:100',
            'usuarios.*.id' => 'required|integer|distinct|exists:users,id',
        ]);

        DB::transaction(function () use ($dados): void {
            foreach ($dados['usuarios'] as $usuario) {
                User::findOrFail((int) $usuario['id'])->delete();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Usuário arquivado com sucesso',
        ]);
    }

    public function usuarioDeletar(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Exclusão permanente indisponível.'], 501);
    }

    public function usuarioRestaurar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'usuarios' => 'required|array|min:1|max:100',
            'usuarios.*.id' => 'required|integer|distinct',
        ]);

        DB::transaction(function () use ($dados): void {
            foreach ($dados['usuarios'] as $usuario) {
                User::onlyTrashed()->findOrFail((int) $usuario['id'])->restore();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Usuário restaurado com sucesso',
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function usuariosArquivados(): LengthAwarePaginator
    {
        return User::onlyTrashed()
            ->latest('updated_at')
            ->paginate();
    }
}
