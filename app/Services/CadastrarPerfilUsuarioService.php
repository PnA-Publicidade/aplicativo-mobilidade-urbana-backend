<?php

namespace App\Services;

use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Cadastro de motorista ou passageiro pela gestão. Cada um tem a própria conta:
 * e-mail, telefone e CPF são únicos dentro da tabela do app, e a mesma pessoa
 * pode ter cadastro nos dois.
 */
class CadastrarPerfilUsuarioService
{
    public function executar(Request $request, string $tipo): Model
    {
        $classe = match ($tipo) {
            'motorista' => Motorista::class,
            'passageiro' => Passageiro::class,
        };
        $tabela = (new $classe)->getTable();
        $regras = [
            'name' => 'required|string|min:3|max:255',
            'cpf' => ['required', 'digits:11', Rule::unique($tabela, 'cpf')],
            'data_nascimento' => 'required|date_format:Y-m-d|before_or_equal:'.now()->subYears(18)->toDateString(),
            'email' => ['required', 'email', 'max:255', Rule::unique($tabela, 'email')],
            'telefone' => ['nullable', 'digits_between:10,11', Rule::unique($tabela, 'telefone')],
            'password' => 'required|string|min:8|max:255|confirmed',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
        ];
        if ($tipo === 'motorista') {
            $regras = [...$regras,
                'numero_registro' => 'nullable|string|max:20', 'cnh_categoria' => 'nullable|string',
                'cnh_expiracao' => 'nullable|date', 'ear' => 'nullable|boolean', 'observacao' => 'nullable|string|max:5000',
            ];
        }
        $dados = $request->validate($regras, [
            'cpf.unique' => 'Este CPF já tem cadastro de '.$tipo.'.',
            'email.unique' => 'Este e-mail já tem cadastro de '.$tipo.'.',
            'telefone.unique' => 'Este telefone já tem cadastro de '.$tipo.'.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.confirmed' => 'A confirmação da senha não confere.',
            'data_nascimento.before_or_equal' => 'É necessário ter pelo menos 18 anos.',
        ]);
        $caminho = null;
        try {
            return DB::transaction(function () use ($request, $dados, $tipo, $classe, &$caminho): Model {
                $perfil = [
                    'name' => trim($dados['name']), 'cpf' => $dados['cpf'], 'data_nascimento' => $dados['data_nascimento'],
                    'email' => $dados['email'], 'telefone' => $dados['telefone'] ?? null,
                    'status' => $tipo === 'motorista' ? 'pendente' : 'ativo',
                ];
                foreach (['numero_registro', 'cnh_categoria', 'cnh_expiracao', 'ear', 'observacao'] as $campo) {
                    if (array_key_exists($campo, $dados)) {
                        $perfil[$campo] = $dados[$campo];
                    }
                }
                if ($request->hasFile('image')) {
                    $caminho = $request->file('image')->store($tipo, 'perfis_fotos');
                    $host = app()->environment('local') ? $request->getSchemeAndHttpHost() : rtrim((string) config('app.url'), '/');
                    $perfil['foto'] = $host.'/images/perfis/'.$caminho;
                }
                $registro = new $classe($perfil);
                $registro->forceFill(['password' => $dados['password']])->save();

                return $registro;
            });
        } catch (\Throwable $erro) {
            if ($caminho !== null) {
                Storage::disk('perfis_fotos')->delete($caminho);
            }
            throw $erro;
        }
    }

    /**
     * Nome, e-mail, telefone, CPF e nascimento, únicos dentro da tabela do app.
     *
     * @return array<string, mixed>
     */
    public static function regrasPessoais(string $tabela, int $id): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique($tabela, 'email')->ignore($id)],
            'telefone' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique($tabela, 'telefone')->ignore($id)],
            'cpf' => ['sometimes', 'nullable', 'digits:11', Rule::unique($tabela, 'cpf')->ignore($id)],
            'data_nascimento' => 'sometimes|nullable|date|before_or_equal:'.now()->subYears(18)->toDateString(),
        ];
    }
}
