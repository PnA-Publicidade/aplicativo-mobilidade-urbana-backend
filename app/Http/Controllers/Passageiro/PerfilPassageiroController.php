<?php

namespace App\Http\Controllers\Passageiro;

use App\Http\Controllers\Controller;
use App\Models\Corrida;
use App\Services\ReputacaoService;
use App\Support\ImagemDoAvatar;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Perfil do passageiro no app: nota, selos recebidos, conquistas e verificações. */
class PerfilPassageiroController extends Controller
{
    public function __construct(private readonly ReputacaoService $reputacao) {}

    /**
     * O perfil público do passageiro como o motorista da corrida o enxerga: primeiro
     * nome, foto ou avatar, nota, corridas, tempo no app, verificações confirmadas,
     * avaliações positivas e conquistas. Sem contato nem documentos. Só o motorista
     * daquela corrida vê, e não há perfil quando a corrida é para outra pessoa.
     */
    public function paraMotorista(Request $request, int $corrida): JsonResponse
    {
        $motorista = PerfilLogado::motorista($request);
        abort_if($motorista === null, 403, 'Só o motorista da corrida vê este perfil.');

        $registro = Corrida::query()->with('passageiro')->find($corrida);
        $passageiro = $registro?->passageiro;

        abort_if(
            $registro === null
                || (int) $registro->motorista_id !== $motorista->id
                || $passageiro === null
                || $registro->convidado_nome !== null,
            404,
            'Perfil indisponível.',
        );

        $resumo = $this->reputacao->doPassageiro($passageiro->id);
        $meses = $this->reputacao->mesesNaConta($passageiro);

        return response()->json([
            'nome' => explode(' ', trim((string) $passageiro->name))[0],
            'foto' => $passageiro->foto,
            'foto_e_avatar' => (bool) $passageiro->usar_avatar && ImagemDoAvatar::doPerfil($passageiro->avatar_id) !== null,
            ...$resumo,
            'desde_em' => $passageiro->created_at === null ? null : Carbon::parse($passageiro->created_at)->toIso8601String(),
            'meses_na_plataforma' => $meses,
            'elogios' => $this->reputacao->elogiosDoPassageiro($passageiro->id),
            'selos' => $this->reputacao->selosDoPassageiro($resumo['corridas'], $meses),
            'verificacoes' => array_values(array_filter(
                $this->reputacao->verificacoesDoPassageiro($passageiro),
                fn (array $item) => $item['ok'] === true,
            )),
        ]);
    }

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
            'foto' => ImagemDoAvatar::fotoExibida($passageiro->fotoEnviada(), $passageiro->avatar_id, (bool) $passageiro->usar_avatar),
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
