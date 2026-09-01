<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Intervention\Image\Laravel\Facades\Image;
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
            'foto' => 'nullable|string',
        ], $this->mensagensCadastro());

        $user = User::create([
            'name' => $dados['name'],
            'data_nascimento' => $dados['data_nascimento'],
            'telefone' => $dados['telefone'] ?? null,
            'cpf' => $dados['cpf'],
            'email' => $dados['email'],
            'foto' => $dados['foto'] ?? null,
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
        $dados = $request->validate(
            $this->regrasCadastro(),
            $this->mensagensCadastro()
        );

        $user = User::create([
            'name' => $dados['name'],
            'data_nascimento' => $dados['data_nascimento'],
            'telefone' => $dados['telefone'] ?? null,
            'cpf' => $dados['cpf'],
            'email' => $dados['email'],
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
                'telefone' => $user->telefone,
                'cpf' => $user->cpf,
                'data_nascimento' => $user->data_nascimento,
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
    public function update(Request $request, string $id): void
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): void
    {
        //
    }

    /**
     * @return array<string, string>
     */
    private function regrasCadastro(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|regex:/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/',
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
            'password.regex' => 'A senha deve ter ao menos 1 letra maiúscula, 1 número e 1 símbolo.',
            'data_nascimento.before_or_equal' => 'Você precisa ter pelo menos 18 anos para se cadastrar.',
        ];
    }

    private function criarImagemPerfil(Request $request, User $user): JsonResponse
    {
        $image = $request->file('image', null);

        if ($image) {
            $host = App::environment('local') ? $request->getSchemeAndHttpHost() : 'https://api.producao.app/';
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg',
            ]);

            $image = $request->file('image');
            $imageName = $image->getClientOriginalName();
            $imageName = time().'_'.$imageName;
            $thumbnail = $image->getClientOriginalName();
            $thumbnail = time().'_thumbnail'.$thumbnail;

            Image::decode($image)
                ->resize(100, 100)
                ->save(public_path('images/').$thumbnail);

            $image->move(public_path('images'), $imageName);
            $user->foto = "{$host}/images/{$imageName}";
            $user->foto_thumbnail = "{$host}/images/{$thumbnail}";
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
        try {
            $user = User::findOrFail($id);

            if ($user->foto) {
                $hostImagePath = public_path(str_replace(url('/'), '', $user->foto));
                if (File::exists($hostImagePath)) {
                    File::delete($hostImagePath);
                }

                if ($user->foto_thumbnail) {
                    $hostThumbPath = public_path(str_replace(url('/'), '', $user->foto_thumbnail));
                    if (File::exists($hostThumbPath)) {
                        File::delete($hostThumbPath);
                    }
                }
            }

            $user->foto = null;
            $user->foto_thumbnail = null;
            $user->saveOrFail();

            return response()->json([
                'success' => true,
                'message' => 'Foto removida com sucesso!',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover foto: '.$e->getMessage(),
            ], 500);
        }
    }

    public function alterarFotoPerfil(Request $request, string $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);
            $this->removerFotoPerfil($id);
            $this->criarImagemPerfil($request, $user);

            return response()->json([
                'user' => [
                    'foto' => $user->foto,
                    'foto_thumbnail' => $user->foto_thumbnail,
                ],
                'success' => true,
                'message' => 'Foto alterada com sucesso!',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao alterar foto: '.$e->getMessage(),
            ], 500);
        }
    }

    public function usuarioArquivar(Request $request): JsonResponse
    {
        try {
            $usuarios = $request->input('usuarios', []);
            foreach ($usuarios as $usuario) {
                User::findOrFail((int) $usuario['id'])->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Usuário arquivado com sucesso',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao arquivar usuário: '.$e->getMessage(),
            ], 500);
        }
    }

    public function usuarioDeletar(Request $request): void
    {
        //
    }

    public function usuarioRestaurar(Request $request): void
    {
        //
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
