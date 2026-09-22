<?php

namespace App\Http\Controllers\Publicidade;

use App\Http\Requests\StoreBannerPublicidadeRequest;
use App\Http\Requests\UpdateBannerPublicidadeRequest;
use App\Models\BannerPublicidade;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class BannerPublicidadeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return BannerPublicidade::with('cidade')->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'cidade_id' => 'required|integer',
            'titulo' => 'required|string',
            'arquivo' => 'required|file|mimes:jpg,jpeg,png|max:12048',
        ]);

        $imagem = $request->file('arquivo');

        /*
    |--------------------------------------------------------------------------
    | Dados da imagem
    |--------------------------------------------------------------------------
    | Capture antes do move(), pois depois o arquivo temporário deixa
    | de existir.
    */

        $originalName = $imagem->getClientOriginalName();
        $extension = $imagem->extension();
        $mimeType = $imagem->getMimeType();
        $size = $imagem->getSize();

        /*
    |--------------------------------------------------------------------------
    | Nome do arquivo
    |--------------------------------------------------------------------------
    */

        $imageName = time() . '_' . $originalName;

        /*
    |--------------------------------------------------------------------------
    | Diretório
    |--------------------------------------------------------------------------
    */

        $directory = public_path('images');

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        /*
    |--------------------------------------------------------------------------
    | Salvar imagem
    |--------------------------------------------------------------------------
    */

        $imagem->move($directory, $imageName);

        /*
    |--------------------------------------------------------------------------
    | URL
    |--------------------------------------------------------------------------
    */

        $path = "images/{$imageName}";

        $host = App::environment('local')
            ? $request->getSchemeAndHttpHost()
            : 'https://api.producao.app';

        $url = "{$host}/{$path}";

        /*
    |--------------------------------------------------------------------------
    | Salvar no banco
    |--------------------------------------------------------------------------
    */

        $bannerPublicidade = BannerPublicidade::create([
            'cidade_id' => $request->cidade_id,
            'titulo' => $request->titulo,
            'name' => $originalName,
            'type' => $extension,
            'mime_type' => $mimeType,
            'size' => $size,
            'path' => $path,
            'url' => $url,
        ]);

        return response()->json([
            'message' => 'Arquivo enviado com sucesso',
            'data' => $bannerPublicidade,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(BannerPublicidade $bannerPublicidade)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBannerPublicidadeRequest $request, BannerPublicidade $bannerPublicidade)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BannerPublicidade $bannerPublicidade)
    {
        //
    }
}
