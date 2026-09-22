<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Image;
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
    public function index(): LengthAwarePaginator
    {
        return User::orderBy('id', 'desc')->paginate();
    }

    public function usuarioLogado(): ?User
    {
        /** @var ?User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
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
            'foto' => null,
            'status' => 'ativo',
            'password' => bcrypt($dados['password']),
        ]);

        $this->criarImagemPerfil($request, $user);

        // gera token JWT
        /** @var JWTGuard $guard */
        $guard = auth('jwt');
        $token = $guard->login($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token,
        ], 201);
    }

    public function register(Request $request): JsonResponse
    {
        $ehMotorista = $request->input('perfil') === 'motorista';

        $dados = $request->validate(
            [
                ...$this->regrasCadastro(),
                'perfil' => 'nullable|in:passageiro,motorista',
            ],
            $this->mensagensCadastro()
        );

        $user = DB::transaction(function () use ($dados, $ehMotorista) {
            $user = User::create([
                'name' => $dados['name'],
                'data_nascimento' => $dados['data_nascimento'],
                'telefone' => $dados['telefone'] ?? null,
                'cpf' => $dados['cpf'],
                'email' => $dados['email'],
                'status' => 'ativo',
                'password' => bcrypt($dados['password']),
            ]);

            if ($ehMotorista) {
                // nasce pendente e sem CNH: os documentos vêm no passo
                // seguinte e a liberação é feita pelo painel de gestão
                Motorista::create([
                    'user_id' => $user->id,
                    'status' => 'pendente',
                ]);
            }

            return $user;
        });

        $this->criarImagemPerfil($request, $user);

        // gera token JWT
        /** @var JWTGuard $guard */
        $guard = auth('jwt');
        $token = $guard->login($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'telefone' => $user->telefone,
                'cpf' => $user->cpf,
                'data_nascimento' => $user->data_nascimento,
                'motorista' => $ehMotorista,
            ],
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
     * @return array<string, string>
     */
    private function regrasCadastro(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'telefone' => 'nullable|string|unique:users,telefone',
            'cpf' => 'required|string|size:11|unique:users,cpf',
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
            'cnh_numero.required' => 'Informe o número da sua CNH.',
            'cnh_categoria.in' => 'Categoria de CNH inválida.',
            'cnh_expiracao.after' => 'Sua CNH está vencida.',
        ];
    }

    private function criarImagemPerfil(Request $request, User $user): JsonResponse
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
            $thumbnail = Str::uuid().'_thumbnail.'.$extension;

            File::ensureDirectoryExists(public_path('images'));

            File::put(
                public_path('images/').$thumbnail,
                Image::fromUpload($image)->resize(100, 100)->toBytes(),
            );

            $image->move(public_path('images'), $imageName);
            $user->foto = $host.'/images/'.$imageName;
            $user->foto_thumbnail = $host.'/images/'.$thumbnail;
            $user->saveOrFail();
        }

        return response()->json([
            'success' => true,
            'message' => 'Registro realizado com sucesso',
            'data' => $user,
        ], 201);
    }

    public function removerFotoPerfil(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $foto = $user->foto;
        $thumbnail = $user->foto_thumbnail;
        $user->foto = null;
        $user->foto_thumbnail = null;
        $user->saveOrFail();
        $this->excluirImagemPerfil($foto);
        $this->excluirImagemPerfil($thumbnail);

        return response()->json([
            'success' => true,
            'message' => 'Foto removida com sucesso!',
        ]);
    }

    public function alterarFotoPerfil(Request $request, string $id): JsonResponse
    {
        $request->validate(['image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120']);
        $user = User::findOrFail($id);
        $fotoAnterior = $user->foto;
        $thumbnailAnterior = $user->foto_thumbnail;
        $this->criarImagemPerfil($request, $user);
        $this->excluirImagemPerfil($fotoAnterior);
        $this->excluirImagemPerfil($thumbnailAnterior);

        return response()->json([
            'user' => [
                'foto' => $user->foto,
                'foto_thumbnail' => $user->foto_thumbnail,
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
