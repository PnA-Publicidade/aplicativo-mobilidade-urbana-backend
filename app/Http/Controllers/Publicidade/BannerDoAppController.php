<?php

namespace App\Http\Controllers\Publicidade;

use App\Http\Controllers\Controller;
use App\Models\BannerPublicidade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BannerDoAppController extends Controller
{
    private const LIMITE = 10;

    /**
     * Banners que os apps mostram na cidade onde o usuário está. O app manda
     * o nome da cidade que o GPS do celular encontrou (e a UF, quando tem);
     * cidade fora do cadastro não tem banner.
     */
    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'cidade' => 'required|string|max:120',
            'uf' => 'nullable|string|size:2',
        ]);

        $uf = isset($dados['uf']) ? mb_strtoupper($dados['uf']) : null;

        $banners = BannerPublicidade::query()
            ->whereHas('cidades', fn (Builder $cidade) => $cidade
                ->where('nome', trim($dados['cidade']))
                ->when($uf !== null, fn (Builder $consulta) => $consulta->where('uf', $uf)))
            ->latest('id')
            ->limit(self::LIMITE)
            ->get()
            ->map(fn (BannerPublicidade $banner) => [
                'id' => $banner->id,
                'titulo' => $banner->titulo,
                'url' => $banner->url,
            ]);

        return response()->json(['data' => $banners]);
    }
}
