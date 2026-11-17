<?php

namespace App\Http\Controllers\Publicidade;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBannerPublicidadeRequest;
use App\Http\Requests\UpdateBannerPublicidadeRequest;
use App\Models\BannerPublicidade;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class BannerPublicidadeController extends Controller
{
    /**
     * Banners mais novos primeiro. Filtro: cidade_id. Paginação: per_page (até 50).
     *
     * @return LengthAwarePaginator<int, BannerPublicidade>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return $this->listar($request, BannerPublicidade::query());
    }

    /**
     * Os banners arquivados (excluídos de forma reversível), com os mesmos filtros.
     *
     * @return LengthAwarePaginator<int, BannerPublicidade>
     */
    public function arquivados(Request $request): LengthAwarePaginator
    {
        return $this->listar($request, BannerPublicidade::onlyTrashed());
    }

    public function restaurar(BannerPublicidade $banner): JsonResponse
    {
        $banner->restore();

        return response()->json([
            'message' => 'Banner restaurado.',
            'data' => $banner->load('cidades:id,nome,uf'),
        ]);
    }

    /**
     * @param  Builder<BannerPublicidade>  $consulta
     * @return LengthAwarePaginator<int, BannerPublicidade>
     */
    private function listar(Request $request, Builder $consulta): LengthAwarePaginator
    {
        $filtros = $request->validate([
            'cidade_id' => 'sometimes|integer',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        return $consulta
            ->with('cidades:id,nome,uf')
            ->when(isset($filtros['cidade_id']), fn (Builder $filtrada) => $filtrada->whereHas('cidades', fn (Builder $cidade) => $cidade->whereKey($filtros['cidade_id'])))
            ->latest('id')
            ->paginate($filtros['per_page'] ?? 15);
    }

    /**
     * multipart/form-data: titulo e arquivo (imagem). As cidades vão depois,
     * por PUT publicidades/{banner}/cidades.
     */
    public function store(StoreBannerPublicidadeRequest $request): JsonResponse
    {
        /** @var UploadedFile $arquivo */
        $arquivo = $request->file('arquivo');

        $banner = BannerPublicidade::create([
            'titulo' => $request->string('titulo')->trim()->toString(),
            ...$this->guardarImagem($arquivo),
        ]);

        return response()->json([
            'message' => 'Banner cadastrado.',
            'data' => $banner->load('cidades:id,nome,uf'),
        ], 201);
    }

    public function show(BannerPublicidade $banner): BannerPublicidade
    {
        return $banner->load('cidades:id,nome,uf');
    }

    /**
     * Com troca de imagem, envie multipart por POST com _method=PUT.
     */
    public function update(UpdateBannerPublicidadeRequest $request, BannerPublicidade $banner): JsonResponse
    {
        $dados = $request->safe()->only(['titulo']);
        $imagemAntiga = null;

        if ($request->hasFile('arquivo')) {
            /** @var UploadedFile $arquivo */
            $arquivo = $request->file('arquivo');
            $imagemAntiga = $banner->path;
            $dados = [...$dados, ...$this->guardarImagem($arquivo)];
        }

        $banner->update($dados);

        // a imagem velha só sai depois que o banner já aponta para a nova
        if ($imagemAntiga !== null && $imagemAntiga !== $banner->path) {
            Storage::disk($this->disco())->delete($imagemAntiga);
        }

        return response()->json([
            'message' => 'Banner atualizado.',
            'data' => $banner->load('cidades:id,nome,uf'),
        ]);
    }

    /**
     * Define de uma vez as cidades onde o banner aparece (troca a lista
     * inteira; lista vazia tira o banner de todas).
     */
    public function definirCidades(Request $request, BannerPublicidade $banner): JsonResponse
    {
        $dados = $request->validate([
            'cidade_ids' => 'present|array',
            'cidade_ids.*' => 'integer|distinct|exists:cidades,id',
        ]);

        $banner->cidades()->sync($dados['cidade_ids']);

        return response()->json([
            'message' => 'Cidades do banner atualizadas.',
            'data' => $banner->load('cidades:id,nome,uf'),
        ]);
    }

    /**
     * Exclusão reversível (soft delete): a imagem fica guardada.
     */
    public function destroy(BannerPublicidade $banner): JsonResponse
    {
        $banner->delete();

        return response()->json(['message' => 'Banner excluído.']);
    }

    /**
     * @return array{name: string, type: string, mime_type: string, size: int, path: string}
     */
    private function guardarImagem(UploadedFile $arquivo): array
    {
        $extensao = $arquivo->extension();

        // nome gerado pelo servidor: o nome enviado pode trazer caminho ou
        // caracteres estranhos, e dois envios do mesmo arquivo não colidem
        $caminho = $arquivo->storeAs(
            (string) config('publicidade.pasta'),
            Str::uuid().'.'.$extensao,
            ['disk' => $this->disco(), 'visibility' => 'public'],
        );

        if ($caminho === false) {
            throw new RuntimeException('Não foi possível guardar a imagem do banner.');
        }

        return [
            'name' => Str::limit($arquivo->getClientOriginalName(), 250, ''),
            'type' => $extensao,
            'mime_type' => (string) $arquivo->getMimeType(),
            'size' => (int) $arquivo->getSize(),
            'path' => $caminho,
        ];
    }

    private function disco(): string
    {
        return (string) config('publicidade.disco');
    }
}
