<?php

namespace App\Http\Controllers\Motorista;

use App\Enums\MotivoReprovacaoDocumento;
use App\Enums\TipoDocumentoMotorista;
use App\Http\Controllers\Controller;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\User;
use App\Services\ArmazenarAnexoMotoristaService;
use App\Services\AtualizarSituacaoMotoristaService;
use App\Services\CrlvVeiculoService;
use App\Services\DocumentosMotoristaService;
use App\Services\InformacoesDocumentoMotoristaService;
use App\Services\LeituraCrlvService;
use App\Services\SolicitacaoVeiculoService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MotoristaDocumentoController extends Controller
{
    public function __construct(
        protected AtualizarSituacaoMotoristaService $atualizarSituacaoMotoristaService,
        protected ArmazenarAnexoMotoristaService $armazenarAnexoMotoristaService
    ) {}

    public function tipos(): JsonResponse
    {
        return response()->json(['data' => TipoDocumentoMotorista::catalogo()]);
    }

    public function motivosReprovacao(): JsonResponse
    {
        return response()->json(['data' => MotivoReprovacaoDocumento::catalogo()]);
    }

    public function resumo(int $motoristaId): JsonResponse
    {
        $motorista = Motorista::findOrFail($motoristaId);

        $service = app(DocumentosMotoristaService::class);

        return response()->json(['data' => $service->resumo($motorista), 'veiculos' => $service->veiculos($motorista)]);
    }

    public function baixar(Request $request, int $motoristaDocumentoId): StreamedResponse
    {
        $dados = $request->validate(['lado' => 'sometimes|in:frente,verso']);
        $documento = MotoristaDocumento::findOrFail($motoristaDocumentoId);
        $anexo = ($dados['lado'] ?? 'frente') === 'verso' ? $documento->verso : $documento->toArray();
        $path = $anexo['path'] ?? null;
        $diretorio = ArmazenarAnexoMotoristaService::DIRETORIO.'/';
        abort_unless(is_string($path) && str_starts_with($path, $diretorio), 404, 'Arquivo não encontrado.');
        $arquivo = substr($path, strlen($diretorio));
        abort_if($arquivo === '' || basename($arquivo) !== $arquivo, 404, 'Arquivo não encontrado.');
        $disk = Storage::disk(ArmazenarAnexoMotoristaService::DIRETORIO);
        abort_unless($disk->exists($arquivo), 404, 'Arquivo não encontrado.');

        return $disk->download($arquivo, $anexo['name'] ?? basename($arquivo), [
            'Content-Type' => $anexo['mime_type'] ?? 'application/octet-stream',
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return LengthAwarePaginator<int, MotoristaDocumento>
     */
    public function index(): LengthAwarePaginator
    {
        return MotoristaDocumento::where('ordem', 0)->paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $informacoesService = app(InformacoesDocumentoMotoristaService::class);
        $informacoesService->normalizar($request);
        $crlvService = app(CrlvVeiculoService::class);
        $crlvService->normalizar($request);
        $dados = $request->validate([
            ...$informacoesService->regras($request),
            'motorista_id' => 'required|integer|exists:motoristas,id',
            'origem' => 'sometimes|required|in:documentos,veiculos|prohibited_unless:tipo_documento,crlv',
            'tipo_documento' => ['required', Rule::enum(TipoDocumentoMotorista::class)],
            'arquivo' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'arquivo_verso' => $this->armazenarAnexoMotoristaService->regrasVerso($request),

        ]);

        if ($dados['tipo_documento'] === 'crlv' && isset($dados['veiculo_id'])) {
            $veiculo = $crlvService->veiculoVinculado((int) $dados['motorista_id'], (int) $dados['veiculo_id']);
            $crlvService->validar($veiculo, $dados['informacoes_complementares']);
        }
        $anexo = $this->armazenarAnexoMotoristaService->salvarEnvio($request);

        try {
            $resultado = DB::transaction(function () use ($dados, $anexo): array {
                $motorista = Motorista::lockForUpdate()->findOrFail($dados['motorista_id']);

                if ($dados['tipo_documento'] === TipoDocumentoMotorista::CNH->value && isset($dados['informacoes_complementares'])) {
                    $motorista->update($dados['informacoes_complementares']);
                }

                $veiculo = $dados['tipo_documento'] === 'crlv'
                    ? app(CrlvVeiculoService::class)->registrarOuAtualizar($motorista, $dados['informacoes_complementares'], $dados['veiculo_id'] ?? null, $dados['origem'] ?? 'documentos')
                    : null;

                $motoristaDocumento = $this->armazenarAnexoMotoristaService->registrarEnvio([
                    'motorista_id' => $motorista->id,
                    'tipo_documento' => $dados['tipo_documento'],
                    'veiculo_id' => $veiculo?->id,
                    'informacoes_complementares' => $dados['informacoes_complementares'] ?? null,
                    'status' => 'em_analise',
                ], $anexo);

                if ($veiculo !== null) {
                    app(CrlvVeiculoService::class)->sincronizarStatus($veiculo);
                }
                $situacao = $this->atualizarSituacaoMotoristaService->executar($motorista);

                return [
                    'data' => $motoristaDocumento->load(['veiculo']),
                    'motorista' => $motorista->fresh(),
                    'situacao_motorista' => $situacao,
                ];
            });
        } catch (Throwable $exception) {
            $this->armazenarAnexoMotoristaService->excluirEnvio($anexo);
            throw $exception;
        }

        return response()->json([
            'message' => 'Arquivo enviado com sucesso',
            ...$resultado,
        ], 201);
    }

    /**
     * Display the specified resource.
     *
     * @return LengthAwarePaginator<int, MotoristaDocumento>
     */
    public function show(int $motoristaDocumentoId): LengthAwarePaginator
    {
        return MotoristaDocumento::where('motorista_id', $motoristaDocumentoId)->where('ordem', 0)->paginate();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $motoristaDocumentoId): JsonResponse
    {
        abort_unless($request->user() instanceof Gestor && $request->user()->pode('documentos.analisar'), 403);
        $request->validate(['leitura_automatica' => 'sometimes|boolean']);
        if ($request->boolean('leitura_automatica')) {
            return $this->lerSolicitacao($motoristaDocumentoId);
        }
        $dados = $request->validate(['informacoes_complementares' => 'required|array']);
        $informacoes = app(SolicitacaoVeiculoService::class)->validar($dados['informacoes_complementares']);
        $documento = DB::transaction(function () use ($motoristaDocumentoId, $informacoes) {
            $documento = MotoristaDocumento::lockForUpdate()->findOrFail($motoristaDocumentoId);
            abort_unless($documento->solicitacao_veiculo !== null && $documento->veiculo_id === null && $documento->status !== 'aprovado', 409, 'Este documento não é uma solicitação de novo veículo pendente.');
            app(SolicitacaoVeiculoService::class)->validarCategoriaDocumento($documento, $informacoes);
            $documento->update(['informacoes_complementares' => $informacoes]);

            return $documento;
        });

        return response()->json(['message' => 'Dados conferidos. O veículo será cadastrado ao aprovar o CRLV.', 'data' => $documento]);
    }

    /** Completa envios antigos sem substituir os campos já conferidos. */
    private function lerSolicitacao(int $id): JsonResponse
    {
        $registro = MotoristaDocumento::findOrFail($id);
        abort_unless($registro->solicitacao_veiculo !== null && $registro->veiculo_id === null && $registro->status !== 'aprovado', 409);
        $dados = $registro->informacoes_complementares ?? [];
        try {
            app(SolicitacaoVeiculoService::class)->validar($dados);

            // Documento completo: não repetir OCR nem sobrescrever a conferência.
            return response()->json(['data' => $registro]);
        } catch (ValidationException) {
            // Compatibilidade com documentos enviados antes da leitura no upload.
        }
        $nome = basename((string) $registro->path);
        abort_unless($nome !== '' && Storage::disk(ArmazenarAnexoMotoristaService::DIRETORIO)->exists($nome), 404);
        $arquivo = new UploadedFile(Storage::disk(ArmazenarAnexoMotoristaService::DIRETORIO)->path($nome),
            $registro->name, $registro->mime_type, null, true);
        $extraidos = app(LeituraCrlvService::class)->extrair($arquivo);
        $registro = DB::transaction(function () use ($id, $extraidos) {
            $atual = MotoristaDocumento::lockForUpdate()->findOrFail($id);
            abort_unless($atual->veiculo_id === null && $atual->status !== 'aprovado', 409);
            $informacoes = $atual->informacoes_complementares ?? [];
            foreach ($extraidos as $campo => $valor) {
                if (trim((string) ($informacoes[$campo] ?? '')) === '') {
                    $informacoes[$campo] = $valor;
                }
            }
            if ($informacoes !== []) {
                $atual->update(['informacoes_complementares' => $informacoes]);
            }

            return $atual;
        });

        return response()->json(['data' => $registro]);
    }

    /** Remove the specified resource from storage. */
    public function destroy(string $motoristaDocumentoId): JsonResponse
    {
        $motoristaDocumento = MotoristaDocumento::find($motoristaDocumentoId);

        if (! $motoristaDocumento) {
            return response()->json([
                'message' => 'Documento não encontrado',
            ], 404);
        }

        $motoristaDocumento = $motoristaDocumento->principalDoEnvio();
        $this->armazenarAnexoMotoristaService->excluirEnvio($motoristaDocumento->toArray());

        $situacao = DB::transaction(function () use ($motoristaDocumento): ?string {
            $motorista = Motorista::lockForUpdate()->find($motoristaDocumento->motorista_id);
            $registro = MotoristaDocumento::lockForUpdate()->findOrFail($motoristaDocumento->id);
            $veiculo = $registro->tipo_documento === TipoDocumentoMotorista::CRLV ? $registro->veiculo : null;
            $registro->anexosDoEnvio()->delete();
            if ($veiculo !== null) {
                app(CrlvVeiculoService::class)->sincronizarStatus($veiculo);
            }

            return $motorista === null ? null : $this->atualizarSituacaoMotoristaService->executar($motorista);
        });

        return response()->json([
            'message' => 'Documento removido com sucesso',
            'situacao_motorista' => $situacao,
        ]);
    }

    public function mudarStatusDocumento(Request $request, int $motoristaDocumentoId): JsonResponse
    {
        $dados = $request->validate([
            'status' => 'required|in:em_analise,aprovado,reprovado',
            'motivo_reprovacao' => ['exclude_unless:status,reprovado', 'required', Rule::enum(MotivoReprovacaoDocumento::class)],
            'descricao_reprovacao' => ['exclude_unless:status,reprovado', 'exclude_unless:motivo_reprovacao,outro', 'required', 'string', 'max:2000'],
        ]);

        $motoristaDocumento = MotoristaDocumento::findOrFail($motoristaDocumentoId)->principalDoEnvio();
        if ($motoristaDocumento->solicitacao_veiculo !== null) {
            abort_unless($request->user() instanceof Gestor && $request->user()->pode('documentos.analisar'), 403);
        }

        // IDs de gestores e usuários pertencem a tabelas diferentes.
        $motoristaDoUsuario = $request->user() instanceof User
            ? Motorista::where('user_id', $request->user()->id)->value('id') : null;
        if ($motoristaDoUsuario !== null && $motoristaDoUsuario === $motoristaDocumento->motorista_id) {
            return response()->json(['message' => 'Você não pode alterar o status dos seus próprios documentos.'], 403);
        }

        $situacao = DB::transaction(function () use ($motoristaDocumento, $dados): ?string {
            $motorista = Motorista::lockForUpdate()->find($motoristaDocumento->motorista_id);
            $registro = MotoristaDocumento::lockForUpdate()->findOrFail($motoristaDocumento->id);
            $reprovado = $dados['status'] === 'reprovado';
            if ($dados['status'] === 'aprovado' && $registro->tipo_documento === TipoDocumentoMotorista::CRLV) {
                $service = app(CrlvVeiculoService::class);
                if ($registro->solicitacao_veiculo !== null && $registro->veiculo_id === null) {
                    app(SolicitacaoVeiculoService::class)->vincularNaAprovacao($registro, $motorista);
                }
                if (! $registro->veiculo_id || ! $registro->informacoes_complementares) {
                    throw ValidationException::withMessages(['informacoes_complementares' => 'Vincule o CRLV a um veículo e confira os dados antes de aprovar.']);
                }
                $veiculo = $service->veiculoVinculado($registro->motorista_id, $registro->veiculo_id);
                $service->validar($veiculo, $registro->informacoes_complementares);
            }
            $registro->anexosDoEnvio()->update([
                'status' => $dados['status'],
                'motivo_reprovacao' => $reprovado ? $dados['motivo_reprovacao'] : null,
                'descricao_reprovacao' => $reprovado && $dados['motivo_reprovacao'] === MotivoReprovacaoDocumento::OUTRO->value
                    ? $dados['descricao_reprovacao'] : null,
            ]);
            if ($registro->tipo_documento === TipoDocumentoMotorista::CRLV && $registro->veiculo !== null) {
                app(CrlvVeiculoService::class)->sincronizarStatus($registro->veiculo);
            }

            return $motorista === null ? null : $this->atualizarSituacaoMotoristaService->executar($motorista);
        });

        return response()->json([
            'message' => 'Status do documento alterado com sucesso',
            'data' => $motoristaDocumento->fresh(),
            'situacao_motorista' => $situacao,
        ]);
    }
}
