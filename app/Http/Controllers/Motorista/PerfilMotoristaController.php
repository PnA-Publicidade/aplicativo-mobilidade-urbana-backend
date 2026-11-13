<?php

namespace App\Http\Controllers\Motorista;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Services\ReputacaoService;
use App\Services\RestricaoNotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Perfil do motorista no app: reputação, elogios, notas de agradecimento, selos e verificações. */
class PerfilMotoristaController extends Controller
{
    private const NOTAS_NO_RESUMO = 10;

    public function __construct(private readonly ReputacaoService $reputacao) {}

    public function mostrar(Request $request): JsonResponse
    {
        $motorista = Motorista::with('user:id,name')->where('user_id', $request->user()->id)->first();

        if ($motorista === null) {
            return response()->json(['message' => 'Cadastro de motorista não encontrado.'], 403);
        }

        $resumo = $this->reputacao->doMotorista($motorista->id);
        $distribuicao = $this->reputacao->distribuicaoDoMotorista($motorista->id);
        $meses = $this->reputacao->mesesNaPlataforma($motorista);
        $elogios = $this->reputacao->elogiosDoMotorista($motorista->id);
        $notas = $this->reputacao->notasDeAgradecimento($motorista->id, self::NOTAS_NO_RESUMO);

        return response()->json([
            'nome' => $motorista->nome ?? $motorista->user?->name,
            'foto' => $motorista->foto,
            ...$resumo,
            'corridas_para_deixar_de_ser_novo' => ReputacaoService::corridasParaDeixarDeSerNovo(),
            // a nota é a média das últimas avaliações reais, até este número
            'janela_da_media' => $this->reputacao->janelaDaMedia(),
            // classificação da nota (Excelente, Atenção, Alto risco) e os limites definidos na gestão
            'faixa' => $this->reputacao->classificar($resumo['nota']),
            'faixas' => $this->reputacao->limitesDasFaixas(),
            // alerta, prazo ou restrição por nota baixa (em modo sombra o motorista não vê alerta)
            'situacao_nota' => app(RestricaoNotaService::class)->situacao(RestricaoNotaService::MOTORISTA, $motorista->id),
            'desde' => ($inicio = $motorista->liberado_em ?? $motorista->created_at) === null ? null : Carbon::parse($inicio)->toDateString(),
            'meses_na_plataforma' => $meses,
            'distribuicao' => $distribuicao,
            'elogios' => $elogios,
            'notas_agradecimento' => $notas->items(),
            'total_notas_agradecimento' => $notas->total(),
            'selos' => $this->reputacao->selosDoMotorista($resumo['corridas'], $this->reputacao->totalDeCincoEstrelas($motorista->id), $meses),
            'verificacoes' => $this->reputacao->verificacoesDoMotorista($motorista),
        ]);
    }

    /** Lista completa das notas de agradecimento (tela Ver tudo). */
    public function notasDeAgradecimento(Request $request): JsonResponse
    {
        $motorista = Motorista::where('user_id', $request->user()->id)->first();

        if ($motorista === null) {
            return response()->json(['message' => 'Cadastro de motorista não encontrado.'], 403);
        }

        return response()->json($this->reputacao->notasDeAgradecimento($motorista->id, 20));
    }
}
