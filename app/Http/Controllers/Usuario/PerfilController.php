<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Support\FotoDePerfil;
use App\Support\PerfilLogado;
use App\Support\Reputacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** o perfil logado nos apps (motorista ou passageiro): dados e foto */
class PerfilController extends Controller
{
    public function usuarioLogado(Request $request): JsonResponse
    {
        $perfil = PerfilLogado::de($request);
        abort_if($perfil->indisponivelNoApp(), 403, 'Este perfil está indisponível. Entre em contato com o suporte.');

        // nota que os motoristas deram ao passageiro (o menu do app mostra)
        $passageiroId = $perfil instanceof Passageiro ? $perfil->id : null;
        $nota = $passageiroId === null ? null : AvaliacoesCorrida::query()
            ->join('corridas', 'corridas.id', '=', 'avaliacoes_corridas.corrida_id')
            ->where('avaliacoes_corridas.tipo_usuario', 'motorista')
            ->where('corridas.passageiro_id', $passageiroId)
            ->avg('avaliacoes_corridas.nota');
        // o perfil do passageiro mostra quantas corridas ele já fez
        $corridas = $passageiroId === null ? 0 : Corrida::where('passageiro_id', $passageiroId)
            ->where('status_corrida', 'finalizada')
            ->count();

        return response()->json([
            ...$perfil->dadosDoApp(),
            'nota_passageiro' => Reputacao::media($nota, $corridas),
            'corridas_passageiro' => $corridas,
            'minimo_corridas_para_nota' => Reputacao::minimoDeCorridas(),
        ]);
    }

    public function alterarFotoPerfil(Request $request, string $id): JsonResponse
    {
        $request->validate(['image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120']);
        $perfil = $this->proprio($request, $id);
        $fotoAnterior = $perfil->getAttribute('foto');
        FotoDePerfil::salvar($request, $perfil);
        FotoDePerfil::excluir($fotoAnterior);

        return response()->json([
            'user' => ['foto' => $perfil->getAttribute('foto')],
            'success' => true,
            'message' => 'Foto alterada com sucesso!',
        ]);
    }

    public function removerFotoPerfil(Request $request, string $id): JsonResponse
    {
        $perfil = $this->proprio($request, $id);
        $foto = $perfil->getAttribute('foto');
        $perfil->setAttribute('foto', null);
        $perfil->saveOrFail();
        FotoDePerfil::excluir($foto);

        return response()->json(['success' => true, 'message' => 'Foto removida com sucesso!']);
    }

    /** a foto é só do próprio perfil (o id da rota é o do perfil logado) */
    private function proprio(Request $request, string $id): Motorista|Passageiro
    {
        $perfil = PerfilLogado::de($request);
        abort_unless((string) $perfil->id === $id, 403, 'Você só pode alterar a foto da própria conta.');
        abort_if($perfil->indisponivelNoApp(), 403, 'Este perfil está indisponível.');

        return $perfil;
    }
}
