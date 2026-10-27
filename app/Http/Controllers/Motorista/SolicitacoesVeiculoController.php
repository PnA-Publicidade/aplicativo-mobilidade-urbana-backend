<?php

namespace App\Http\Controllers\Motorista;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Services\ArmazenarAnexoMotoristaService;
use App\Services\ImpedirCrlvDuplicadoService;
use App\Services\LeituraCrlvService;
use App\Services\SolicitacaoVeiculoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class SolicitacoesVeiculoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $motorista = $this->motorista($request);

        return response()->json(['data' => MotoristaDocumento::where('motorista_id', $motorista->id)
            ->whereNotNull('solicitacao_veiculo')->with('veiculo')->latest('id')->get()]);
    }

    public function store(Request $request, ArmazenarAnexoMotoristaService $anexos): JsonResponse
    {
        $motorista = $this->motorista($request);
        $dados = $request->validate([
            'categoria' => 'required|in:carro,moto',
            'tipo_solicitacao' => 'required|in:passageiros',
            'formato' => 'required|in:fisico,digital',
            'id_envio' => ['required', 'string', 'min:16', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'arquivo' => 'required|file|'.($request->input('formato') === 'digital'
                ? 'mimes:pdf' : 'mimes:jpg,jpeg,png,webp,heic,heif').'|max:10240',
            'motorista_id' => 'prohibited', 'veiculo_id' => 'prohibited',
            'status' => 'prohibited', 'informacoes_complementares' => 'prohibited',
        ]);
        // Reenvios idempotentes retornam antes de repetir OCR e armazenamento.
        $existente = MotoristaDocumento::where('motorista_id', $motorista->id)
            ->where('solicitacao_veiculo->id_envio', $dados['id_envio'])->first();
        if ($existente !== null) {
            return response()->json(['message' => 'CRLV enviado para análise da gestão.', 'data' => $existente]);
        }
        // O tipo incorreto deve ser informado antes de qualquer erro de duplicidade.
        $informacoes = app(LeituraCrlvService::class)->extrair($request->file('arquivo'));
        app(SolicitacaoVeiculoService::class)->validarCategoria($dados['categoria'], $informacoes);
        $hash = hash_file('sha256', $request->file('arquivo')->getRealPath());
        $duplicados = app(ImpedirCrlvDuplicadoService::class);
        $duplicados->verificar($motorista->id, $hash);
        $anexo = $anexos->salvarEnvio($request);
        $reutilizado = false;
        try {
            $documento = DB::transaction(function () use ($motorista, $dados, $anexo, $anexos, $informacoes, $hash, $duplicados, &$reutilizado) {
                // Serializa os reenvios do mesmo motorista, inclusive tentativas após timeout.
                Motorista::lockForUpdate()->findOrFail($motorista->id);
                $existente = MotoristaDocumento::where('motorista_id', $motorista->id)
                    ->where('solicitacao_veiculo->id_envio', $dados['id_envio'])->first();
                if ($existente !== null) {
                    $reutilizado = true;

                    return $existente;
                }

                $duplicados->verificar($motorista->id, $hash, $informacoes);

                // O CRLV aguarda a gestão identificar o NOVO veículo. Nunca substitui o atual.
                return $anexos->registrarEnvio([
                    'motorista_id' => $motorista->id, 'tipo_documento' => 'crlv',
                    'veiculo_id' => null, 'status' => 'em_analise',
                    'informacoes_complementares' => $informacoes ?: null,
                    'solicitacao_veiculo' => [...array_intersect_key($dados,
                        array_flip(['categoria', 'tipo_solicitacao', 'formato', 'id_envio'])),
                        'arquivo_sha256' => $hash, 'categoria_identificada' => $informacoes['categoria_veiculo']],
                ], $anexo);
            });
        } catch (Throwable $exception) {
            $anexos->excluirEnvio($anexo);
            throw $exception;
        }
        if ($reutilizado) {
            $anexos->excluirEnvio($anexo);
        }

        return response()->json([
            'message' => 'CRLV enviado para análise da gestão.', 'data' => $documento,
        ], $reutilizado ? 200 : 201);
    }

    private function motorista(Request $request): Motorista
    {
        $motorista = Motorista::where('user_id', $request->user()->id)->first();
        abort_unless($motorista !== null, 403, 'Cadastro de motorista não encontrado.');
        abort_if(in_array($motorista->status, ['bloqueado', 'inativo'], true), 403, 'Cadastro indisponível.');

        return $motorista;
    }
}
