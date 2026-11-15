<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Support\ImagemDoSelo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use stdClass;

/**
 * Selos (elogios) que passageiros e motoristas escolhem ao se avaliar.
 * `perfil_alvo` é quem recebe: `motorista` (escolhido pelo passageiro) ou
 * `passageiro` (escolhido pelo motorista). Selo em uso só se desativa; não se apaga.
 *
 * Imagem: o arquivo enviado (`imagem`) tem prioridade; sem ele vale `imagem_url`;
 * sem os dois os apps mostram o emoji (`icone`). Os campos de arquivo vão em
 * multipart; para editar com arquivo, envie POST com `_method=PUT`.
 */
class SelosAvaliacaoController extends Controller
{
    private const TAMANHO_MAXIMO_KB = 3072;

    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate(['perfil_alvo' => 'nullable|in:motorista,passageiro']);

        $selos = DB::table('selos_avaliacao as s')
            ->when($dados['perfil_alvo'] ?? null, fn ($q, $alvo) => $q->where('s.perfil_alvo', $alvo))
            ->orderBy('s.perfil_alvo')->orderBy('s.ordem')->orderBy('s.id')
            ->get()
            ->map(fn ($selo) => $this->formatar($selo, $this->vezesUsado((string) $selo->chave)));

        return response()->json(['data' => $selos]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'perfil_alvo' => ['required', Rule::in(['motorista', 'passageiro'])],
            'titulo' => 'required|string|min:2|max:60',
            'descricao' => 'nullable|string|max:200',
            'icone' => 'nullable|string|min:1|max:16',
            'ordem' => 'sometimes|integer|between:0,999',
            'ativo' => 'sometimes|boolean',
            ...$this->regrasDaImagem(),
        ]);

        $chave = $this->chaveUnica($dados['titulo']);
        $arquivo = $request->file('imagem');

        $id = DB::table('selos_avaliacao')->insertGetId([
            'chave' => $chave,
            'perfil_alvo' => $dados['perfil_alvo'],
            'titulo' => $dados['titulo'],
            'descricao' => $dados['descricao'] ?? null,
            // sem emoji informado, vale a estrela (a imagem é o que aparece quando existe)
            'icone' => $dados['icone'] ?? '⭐',
            'imagem_path' => $arquivo instanceof UploadedFile ? $this->guardar($arquivo) : null,
            'imagem_url' => $dados['imagem_url'] ?? null,
            'ordem' => $dados['ordem'] ?? (((int) DB::table('selos_avaliacao')->where('perfil_alvo', $dados['perfil_alvo'])->max('ordem')) + 1),
            'ativo' => $dados['ativo'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->formatar($this->achar($id), 0), 201);
    }

    public function update(Request $request, int $selo): JsonResponse
    {
        $atual = DB::table('selos_avaliacao')->where('id', $selo)->first();

        if ($atual === null) {
            return response()->json(['message' => 'Selo não encontrado.'], 404);
        }

        // a chave e quem recebe o selo não mudam: o histórico de avaliações aponta para eles
        $dados = $request->validate([
            'titulo' => 'sometimes|string|min:2|max:60',
            'descricao' => 'nullable|string|max:200',
            'icone' => 'sometimes|string|min:1|max:16',
            'ordem' => 'sometimes|integer|between:0,999',
            'ativo' => 'sometimes|boolean',
            'remover_imagem' => 'sometimes|boolean',
            ...$this->regrasDaImagem(),
        ]);

        $mudancas = Arr::only($dados, ['titulo', 'descricao', 'icone', 'ordem', 'ativo', 'imagem_url']);
        $arquivo = $request->file('imagem');
        $antiga = filled($atual->imagem_path) ? (string) $atual->imagem_path : null;

        if ($arquivo instanceof UploadedFile) {
            $mudancas['imagem_path'] = $this->guardar($arquivo);
        } elseif ($request->boolean('remover_imagem')) {
            $mudancas['imagem_path'] = null;
        }

        DB::table('selos_avaliacao')->where('id', $selo)->update([...$mudancas, 'updated_at' => now()]);

        // o arquivo velho só sai depois que o selo já aponta para o novo
        if ($antiga !== null && array_key_exists('imagem_path', $mudancas) && $mudancas['imagem_path'] !== $antiga) {
            $this->apagarArquivo($antiga);
        }

        return response()->json($this->formatar($this->achar($selo), $this->vezesUsado((string) $atual->chave)));
    }

    public function destroy(int $selo): JsonResponse
    {
        $atual = DB::table('selos_avaliacao')->where('id', $selo)->first();

        if ($atual === null) {
            return response()->json(['message' => 'Selo não encontrado.'], 404);
        }

        if ($this->vezesUsado((string) $atual->chave) > 0) {
            return response()->json(['message' => 'Este selo já foi recebido em avaliações. Desative-o em vez de excluir.'], 409);
        }

        DB::table('selos_avaliacao')->where('id', $selo)->delete();

        if (filled($atual->imagem_path)) {
            $this->apagarArquivo((string) $atual->imagem_path);
        }

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function regrasDaImagem(): array
    {
        return [
            'imagem' => 'nullable|file|image|mimes:png,jpg,jpeg,webp|max:'.self::TAMANHO_MAXIMO_KB,
            'imagem_url' => 'nullable|url:http,https|max:500',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatar(stdClass $selo, int $vezesUsado): array
    {
        return [
            'id' => (int) $selo->id,
            'chave' => $selo->chave,
            'perfil_alvo' => $selo->perfil_alvo,
            'titulo' => $selo->titulo,
            'descricao' => $selo->descricao,
            'icone' => $selo->icone,
            // o que os apps mostram: o arquivo enviado, senão a URL, senão o emoji
            'imagem' => ImagemDoSelo::url($selo->imagem_path, $selo->imagem_url),
            'imagem_enviada' => filled($selo->imagem_path),
            'imagem_url' => $selo->imagem_url,
            'ordem' => (int) $selo->ordem,
            'ativo' => (bool) $selo->ativo,
            'vezes_usado' => $vezesUsado,
        ];
    }

    private function achar(int $id): stdClass
    {
        return DB::table('selos_avaliacao')->where('id', $id)->firstOrFail();
    }

    /** Guarda a imagem com nome gerado pelo servidor (o nome enviado pode trazer caminho ou caracteres estranhos). */
    private function guardar(UploadedFile $arquivo): string
    {
        $nome = Str::uuid().'.'.$arquivo->extension();
        Storage::disk(ImagemDoSelo::DISCO)->putFileAs('', $arquivo, $nome, 'public');

        return $nome;
    }

    private function apagarArquivo(string $nome): void
    {
        // o nome vem do banco, mas nunca sai da pasta dos selos
        Storage::disk(ImagemDoSelo::DISCO)->delete(basename($nome));
    }

    private function chaveUnica(string $titulo): string
    {
        $base = Str::slug($titulo, '_') ?: 'selo';
        $chave = $base;

        for ($i = 2; DB::table('selos_avaliacao')->where('chave', $chave)->exists(); $i++) {
            $chave = "{$base}_{$i}";
        }

        return $chave;
    }

    /** Quantas avaliações já levaram este selo. */
    private function vezesUsado(string $chave): int
    {
        return DB::table('avaliacoes_corridas')->whereJsonContains('elogios', $chave)->count();
    }
}
