<?php

namespace App\Http\Controllers\Passageiro;

use App\Http\Controllers\Controller;
use App\Services\ReputacaoService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Perfil do passageiro no app: nota, selos recebidos, conquistas e verificações. */
class PerfilPassageiroController extends Controller
{
    public function __construct(private readonly ReputacaoService $reputacao) {}

    public function mostrar(Request $request): JsonResponse
    {
        $passageiro = PerfilLogado::passageiro($request);

        if ($passageiro === null) {
            return response()->json(['message' => 'Cadastro de passageiro não encontrado.'], 403);
        }

        $resumo = $this->reputacao->doPassageiro($passageiro->id);
        $meses = $this->reputacao->mesesNaConta($passageiro);

        return response()->json([
            'nome' => $passageiro->name,
            'foto' => $passageiro->foto,
            ...$resumo,
            'corridas_para_deixar_de_ser_novo' => ReputacaoService::corridasParaDeixarDeSerNovo(),
            // a nota é a média das últimas avaliações reais dos motoristas, até este número
            'janela_da_media' => $this->reputacao->janelaDaMedia(),
            'desde' => $passageiro->created_at === null ? null : Carbon::parse($passageiro->created_at)->toDateString(),
            // momento exato da criação da conta, para o app mostrar dias e horas no primeiro mês
            'desde_em' => $passageiro->created_at === null ? null : Carbon::parse($passageiro->created_at)->toIso8601String(),
            'meses_na_plataforma' => $meses,
            'distribuicao' => $this->reputacao->distribuicaoDoPassageiro($passageiro->id),
            // selos recebidos dos motoristas (vazio para conta nova) e as dicas de "Como ter mais avaliações positivas?"
            'elogios' => $this->reputacao->elogiosDoPassageiro($passageiro->id),
            'dicas' => DB::table('dicas_avaliacao')->where('perfil_alvo', 'passageiro')->where('ativo', true)->orderBy('ordem')->orderBy('id')->get(['id', 'titulo', 'texto']),
            'selos' => $this->reputacao->selosDoPassageiro($resumo['corridas'], $meses),
            'verificacoes' => $this->reputacao->verificacoesDoPassageiro($passageiro),
        ]);
    }
}
