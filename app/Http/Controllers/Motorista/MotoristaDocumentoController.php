<?php

namespace App\Http\Controllers\Motorista;

use App\Enums\MotivoReprovacaoDocumento;
use App\Enums\TipoDocumentoMotorista;
use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Services\ArmazenarAnexoMotoristaService;
use App\Services\AtualizarSituacaoMotoristaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
        Motorista::findOrFail($motoristaId);

        $ultimosEnvios = MotoristaDocumento::query()
            ->where('motorista_id', $motoristaId)
            ->whereIn('tipo_documento', TipoDocumentoMotorista::valores())
            ->selectRaw('MAX(id) as id')
            ->groupBy('tipo_documento');

        $documentos = MotoristaDocumento::query()
            ->whereIn('id', $ultimosEnvios)
            ->get()
            ->keyBy(fn (MotoristaDocumento $documento): string => $documento->tipo_documento->value);

        $dados = array_map(fn (array $tipo): array => [
            ...$tipo,
            'id' => null,
            'status' => null,
            'url' => null,
            'verso' => null,
            ...($documentos->get($tipo['tipo_documento'])?->toArray() ?? []),
        ], TipoDocumentoMotorista::catalogo());

        return response()->json(['data' => $dados]);
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
        return MotoristaDocumento::paginate();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'motorista_id' => 'required|integer|exists:motoristas,id',
            'tipo_documento' => ['required', Rule::enum(TipoDocumentoMotorista::class)],
            'arquivo' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'arquivo_verso' => $this->armazenarAnexoMotoristaService->regrasVerso($request),
            'cnh' => 'sometimes|array:nome,cpf,data_nascimento,numero_registro,cnh_categoria,primeira_habilitacao,data_emissao,cnh_expiracao,ear,observacao|prohibited_unless:tipo_documento,'.TipoDocumentoMotorista::CNH->value,
            'cnh.nome' => 'nullable|string|max:255',
            'cnh.cpf' => ['nullable', 'string', 'regex:/^[0-9]{11}$/'],
            'cnh.data_nascimento' => 'nullable|date_format:Y-m-d',
            'cnh.numero_registro' => 'nullable|string|max:20',
            'cnh.cnh_categoria' => 'nullable|string|max:20',
            'cnh.primeira_habilitacao' => 'nullable|date_format:Y-m-d',
            'cnh.data_emissao' => 'nullable|date_format:Y-m-d',
            'cnh.cnh_expiracao' => 'nullable|date_format:Y-m-d',
            'cnh.ear' => 'nullable|boolean',
            'cnh.observacao' => 'nullable|string|max:5000',
        ]);

        $anexo = $this->armazenarAnexoMotoristaService->salvarEnvio($request);

        try {
            $resultado = DB::transaction(function () use ($dados, $anexo): array {
                $motorista = Motorista::lockForUpdate()->findOrFail($dados['motorista_id']);

                if ($dados['tipo_documento'] === TipoDocumentoMotorista::CNH->value && isset($dados['cnh'])) {
                    $motorista->update($dados['cnh']);
                }

                $motoristaDocumento = MotoristaDocumento::create([
                    'motorista_id' => $motorista->id,
                    'tipo_documento' => $dados['tipo_documento'],
                    ...$anexo,
                    'status' => 'em_analise',
                ]);

                $situacao = $this->atualizarSituacaoMotoristaService->executar($motorista);

                return [
                    'data' => $motoristaDocumento,
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
        return MotoristaDocumento::where('motorista_id', $motoristaDocumentoId)->paginate();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, MotoristaDocumento $motoristaDocumento): void
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $motoristaDocumentoId): JsonResponse
    {
        $motoristaDocumento = MotoristaDocumento::find($motoristaDocumentoId);

        if (! $motoristaDocumento) {
            return response()->json([
                'message' => 'Documento não encontrado',
            ], 404);
        }

        $this->armazenarAnexoMotoristaService->excluirEnvio($motoristaDocumento->toArray());

        // Remove do banco (soft delete)
        $motoristaId = $motoristaDocumento->motorista_id;
        $motoristaDocumento->delete();

        $motorista = Motorista::find($motoristaId);
        $situacao = $motorista === null
            ? null
            : $this->atualizarSituacaoMotoristaService->executar($motorista);

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

        $motoristaDocumento = MotoristaDocumento::findOrFail($motoristaDocumentoId);

        // ninguém aprova o próprio documento
        $motoristaDoUsuario = Motorista::where('user_id', $request->user()->id)->value('id');

        // if ($motoristaDoUsuario !== null && $motoristaDoUsuario === $motoristaDocumento->motorista_id) {
        //     return response()->json([
        //         'message' => 'Você não pode alterar o status dos seus próprios documentos.',
        //     ], 403);
        // }

        $situacao = DB::transaction(function () use ($motoristaDocumento, $dados): ?string {
            $registro = MotoristaDocumento::lockForUpdate()->findOrFail($motoristaDocumento->id);
            $reprovado = $dados['status'] === 'reprovado';
            $registro->update([
                'status' => $dados['status'],
                'motivo_reprovacao' => $reprovado ? $dados['motivo_reprovacao'] : null,
                'descricao_reprovacao' => $reprovado && $dados['motivo_reprovacao'] === MotivoReprovacaoDocumento::OUTRO->value
                    ? $dados['descricao_reprovacao'] : null,
            ]);
            $motorista = Motorista::lockForUpdate()->find($registro->motorista_id);

            return $motorista === null ? null : $this->atualizarSituacaoMotoristaService->executar($motorista);
        });

        return response()->json([
            'message' => 'Status do documento alterado com sucesso',
            'data' => $motoristaDocumento->fresh(),
            'situacao_motorista' => $situacao,
        ]);
    }
}
