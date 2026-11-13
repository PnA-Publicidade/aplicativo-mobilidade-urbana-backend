<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Services\ReputacaoService;
use App\Services\RestricaoNotaService;
use App\Support\FotoDePerfil;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** o perfil logado nos apps (motorista ou passageiro): dados e foto */
class PerfilController extends Controller
{
    public function usuarioLogado(Request $request): JsonResponse
    {
        $perfil = PerfilLogado::de($request);
        abort_if($perfil->indisponivelNoApp(), 403, 'Este perfil está indisponível. Entre em contato com o suporte.');

        $passageiroId = $perfil instanceof Passageiro ? $perfil->id : null;
        // nota base 5,0 até a primeira avaliação real; "novo" enquanto tem poucas corridas
        $reputacao = $passageiroId === null ? null : app(ReputacaoService::class)->doPassageiro($passageiroId);

        return response()->json([
            ...$perfil->dadosDoApp(),
            'nota_passageiro' => $reputacao['nota'] ?? ReputacaoService::notaBase(),
            // o perfil do passageiro mostra quantas corridas ele já fez
            'corridas_passageiro' => $reputacao['corridas'] ?? 0,
            'passageiro_novo' => $reputacao['novo'] ?? true,
            'corridas_para_deixar_de_ser_novo' => ReputacaoService::corridasParaDeixarDeSerNovo(),
            // aviso, suspensão ou bloqueio por nota baixa (em modo sombra não aparece alerta)
            'situacao_nota' => $passageiroId === null ? null : app(RestricaoNotaService::class)->situacao(RestricaoNotaService::PASSAGEIRO, $passageiroId),
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
