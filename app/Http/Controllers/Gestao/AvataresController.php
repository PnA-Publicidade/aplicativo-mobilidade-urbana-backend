<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Support\ImagemDoAvatar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use stdClass;

/**
 * Avatares que o passageiro escolhe no lugar da foto. Sempre uma imagem: o
 * arquivo enviado (`imagem`) tem prioridade; sem ele vale `imagem_url`.
 * Avatar em uso só se desativa; não se apaga.
 *
 * Os campos de arquivo vão em multipart; para editar com arquivo, envie POST
 * com `_method=PUT`.
 */
class AvataresController extends Controller
{
    private const TAMANHO_MAXIMO_KB = 3072;

    public function index(): JsonResponse
    {
        $usos = DB::table('passageiros')->whereNotNull('avatar_id')
            ->selectRaw('avatar_id, count(*) as total')->groupBy('avatar_id')->pluck('total', 'avatar_id');

        $avatares = DB::table('avatares')
            ->orderBy('colecao')->orderBy('ordem')->orderBy('id')
            ->get()
            ->map(fn ($avatar) => $this->formatar($avatar, (int) ($usos[$avatar->id] ?? 0)));

        return response()->json(['data' => $avatares]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'titulo' => 'required|string|min:2|max:60',
            'colecao' => 'nullable|string|max:40',
            'ordem' => 'sometimes|integer|between:0,999',
            'ativo' => 'sometimes|boolean',
            'imagem' => 'required_without:imagem_url|nullable|file|image|mimes:png,jpg,jpeg,webp|max:'.self::TAMANHO_MAXIMO_KB,
            'imagem_url' => 'required_without:imagem|nullable|url:http,https|max:500',
        ]);

        $arquivo = $request->file('imagem');

        $id = DB::table('avatares')->insertGetId([
            'titulo' => $dados['titulo'],
            'colecao' => filled($dados['colecao'] ?? null) ? trim($dados['colecao']) : null,
            'imagem_path' => $arquivo instanceof UploadedFile ? $this->guardar($arquivo) : null,
            'imagem_url' => $dados['imagem_url'] ?? null,
            'ordem' => $dados['ordem'] ?? (((int) DB::table('avatares')->max('ordem')) + 1),
            'ativo' => $dados['ativo'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->formatar($this->achar($id), 0), 201);
    }

    public function update(Request $request, int $avatar): JsonResponse
    {
        $atual = DB::table('avatares')->where('id', $avatar)->first();

        if ($atual === null) {
            return response()->json(['message' => 'Avatar não encontrado.'], 404);
        }

        $dados = $request->validate([
            'titulo' => 'sometimes|string|min:2|max:60',
            'colecao' => 'nullable|string|max:40',
            'ordem' => 'sometimes|integer|between:0,999',
            'ativo' => 'sometimes|boolean',
            'remover_imagem' => 'sometimes|boolean',
            'imagem' => 'nullable|file|image|mimes:png,jpg,jpeg,webp|max:'.self::TAMANHO_MAXIMO_KB,
            'imagem_url' => 'nullable|url:http,https|max:500',
        ]);

        $mudancas = Arr::only($dados, ['titulo', 'ordem', 'ativo', 'imagem_url']);

        if (array_key_exists('colecao', $dados)) {
            $mudancas['colecao'] = filled($dados['colecao']) ? trim($dados['colecao']) : null;
        }

        $arquivo = $request->file('imagem');
        $antiga = filled($atual->imagem_path) ? (string) $atual->imagem_path : null;

        if ($arquivo instanceof UploadedFile) {
            $mudancas['imagem_path'] = $this->guardar($arquivo);
        } elseif ($request->boolean('remover_imagem')) {
            $mudancas['imagem_path'] = null;
        }

        // um avatar sem imagem não existe: precisa sobrar o arquivo ou a URL
        $caminho = array_key_exists('imagem_path', $mudancas) ? $mudancas['imagem_path'] : $atual->imagem_path;
        $url = array_key_exists('imagem_url', $mudancas) ? $mudancas['imagem_url'] : $atual->imagem_url;

        if (blank($caminho) && blank($url)) {
            if (isset($mudancas['imagem_path'])) {
                $this->apagarArquivo($mudancas['imagem_path']);
            }

            return response()->json([
                'message' => 'O avatar precisa de uma imagem: envie um arquivo ou informe uma URL.',
                'errors' => ['imagem' => ['O avatar precisa de uma imagem: envie um arquivo ou informe uma URL.']],
            ], 422);
        }

        DB::table('avatares')->where('id', $avatar)->update([...$mudancas, 'updated_at' => now()]);

        // o arquivo velho só sai depois que o avatar já aponta para o novo
        if ($antiga !== null && array_key_exists('imagem_path', $mudancas) && $mudancas['imagem_path'] !== $antiga) {
            $this->apagarArquivo($antiga);
        }

        return response()->json($this->formatar($this->achar($avatar), $this->emUso($avatar)));
    }

    public function destroy(int $avatar): JsonResponse
    {
        $atual = DB::table('avatares')->where('id', $avatar)->first();

        if ($atual === null) {
            return response()->json(['message' => 'Avatar não encontrado.'], 404);
        }

        if ($this->emUso($avatar) > 0) {
            return response()->json(['message' => 'Este avatar está em uso por passageiros. Desative-o em vez de excluir.'], 409);
        }

        DB::table('avatares')->where('id', $avatar)->delete();

        if (filled($atual->imagem_path)) {
            $this->apagarArquivo((string) $atual->imagem_path);
        }

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatar(stdClass $avatar, int $emUso): array
    {
        return [
            'id' => (int) $avatar->id,
            'titulo' => $avatar->titulo,
            'colecao' => $avatar->colecao,
            // o que os apps mostram: o arquivo enviado, senão a URL
            'imagem' => ImagemDoAvatar::url($avatar->imagem_path, $avatar->imagem_url),
            'imagem_enviada' => filled($avatar->imagem_path),
            'imagem_url' => $avatar->imagem_url,
            'ordem' => (int) $avatar->ordem,
            'ativo' => (bool) $avatar->ativo,
            'em_uso' => $emUso,
        ];
    }

    private function achar(int $id): stdClass
    {
        return DB::table('avatares')->where('id', $id)->firstOrFail();
    }

    /** Guarda a imagem com nome gerado pelo servidor (o nome enviado pode trazer caminho ou caracteres estranhos). */
    private function guardar(UploadedFile $arquivo): string
    {
        $nome = Str::uuid().'.'.$arquivo->extension();
        Storage::disk(ImagemDoAvatar::DISCO)->putFileAs('', $arquivo, $nome, 'public');

        return $nome;
    }

    private function apagarArquivo(string $nome): void
    {
        // o nome vem do banco, mas nunca sai da pasta dos avatares
        Storage::disk(ImagemDoAvatar::DISCO)->delete(basename($nome));
    }

    /** Quantos passageiros usam este avatar. */
    private function emUso(int $id): int
    {
        return DB::table('passageiros')->where('avatar_id', $id)->count();
    }
}
