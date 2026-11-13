<?php

namespace App\Http\Controllers\Motorista;

use App\Enums\TipoDocumentoMotorista;
use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\MotoristaVeiculo;
use App\Models\Veiculo;
use App\Services\ArmazenarAnexoMotoristaService;
use App\Services\AtualizarSituacaoMotoristaService;
use App\Services\CrlvVeiculoService;
use App\Services\DocumentosMotoristaService;
use App\Services\InformacoesDocumentoMotoristaService;
use App\Services\VerificacaoTipoDocumentoService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class MotoristaCadastroController extends Controller
{
    public const ATIVO = 'ativo';

    public const APROVADO = 'aprovado';

    public function __construct(
        protected AtualizarSituacaoMotoristaService $atualizarSituacaoMotoristaService,
        protected ArmazenarAnexoMotoristaService $armazenarAnexoMotoristaService
    ) {}

    public const SEM_CADASTRO = 'sem_cadastro';

    /**
     * Onde o motorista está na esteira de liberação. Responde também para
     * quem ainda não é motorista — é por aqui que um passageiro descobre
     * que precisa enviar documentos para virar motorista.
     */
    public function mostrar(Request $request): JsonResponse
    {
        $motorista = PerfilLogado::motorista($request);

        if ($motorista === null) {
            return response()->json([
                'situacao' => self::SEM_CADASTRO,
                'liberado' => false,
                'cnh' => null,
                'documentos' => [],
                'veiculos' => 0,
                'acoes_necessarias' => [],
                'veiculos_em_analise' => [],
                'pendencias' => ['cnh', 'documentos', 'veiculo'],
                'documentos_faltando' => TipoDocumentoMotorista::valores(),
            ]);
        }

        $documentos = app(DocumentosMotoristaService::class)->paraAcompanhar($motorista);

        $veiculos = MotoristaVeiculo::where('motorista_id', $motorista->id)->count();

        return response()->json([
            'situacao' => $motorista->status,
            'liberado' => $motorista->liberado_em !== null,
            'cnh' => $motorista->numero_registro === null ? null : [
                'numero' => $motorista->numero_registro,
                'categoria' => $motorista->cnh_categoria,
                'expiracao' => $motorista->cnh_expiracao,
                'ear' => (bool) $motorista->ear,
                'observacao' => $motorista->observacao,
            ],
            'documentos' => $documentos,
            'veiculos' => $veiculos,
            'acoes_necessarias' => app(DocumentosMotoristaService::class)->acoesNecessarias($motorista),
            'veiculos_em_analise' => app(DocumentosMotoristaService::class)->veiculosEmAnalise($motorista),
            'pendencias' => $this->pendencias($motorista, $veiculos),
            'documentos_faltando' => $this->atualizarSituacaoMotoristaService
                ->documentosQueFaltam($motorista),
        ]);
    }

    /**
     * Envia ou corrige os dados da CNH. Cria o registro de motorista quando
     * quem chama é um passageiro que está virando motorista.
     */
    public function salvarCnh(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'numero_registro' => 'required|string|max:20',
            'cnh_categoria' => 'required|string|in:A,B,AB,C,D,E,a,b,ab,c,d,e',
            'cnh_expiracao' => 'required|date|after:today',
            'ear' => 'required|boolean',
            'observacao' => 'nullable|string|max:5000',
        ], [
            'numero_registro.required' => 'Informe o número de registro da sua CNH.',
            'cnh_categoria.in' => 'Categoria de CNH inválida.',
            'cnh_expiracao.after' => 'Sua CNH está vencida.',
        ]);

        $motorista = (PerfilLogado::motorista($request) ?? abort(403, 'Perfil de motorista não encontrado.'));

        if ($motorista->exists && $motorista->status === self::ATIVO) {
            return response()->json([
                'message' => 'Seu cadastro já foi aprovado. Fale com o suporte para alterar a CNH.',
            ], 409);
        }

        $motorista->fill([
            'numero_registro' => preg_replace('/\D/', '', (string) $dados['numero_registro']),
            'cnh_categoria' => strtoupper((string) $dados['cnh_categoria']),
            'cnh_expiracao' => $dados['cnh_expiracao'],
            'ear' => (bool) $dados['ear'],
            ...array_intersect_key($dados, ['observacao' => true]),
            'status' => 'em_analise',
        ])->save();

        return response()->json([
            'situacao' => $motorista->status,
            'message' => 'CNH enviada para análise.',
        ], $motorista->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Envia um documento do próprio motorista.
     *
     * O `motorista-documentos` do painel de gestão recebe `motorista_id` pelo
     * corpo da requisição — o que serve ao painel, que age sobre terceiros,
     * mas deixaria qualquer usuário autenticado enviar documento em nome de
     * outro motorista. Aqui o dono sai sempre do token.
     */
    public function enviarDocumento(Request $request): JsonResponse
    {
        $informacoesService = app(InformacoesDocumentoMotoristaService::class);
        $informacoesService->normalizar($request);
        $crlvService = app(CrlvVeiculoService::class);
        $crlvService->normalizar($request);
        // o app envia só o arquivo do CRLV e do Nada Consta; a gestão confere e
        // completa os dados no painel antes de aprovar
        $semDados = in_array($request->input('tipo_documento'), [TipoDocumentoMotorista::CRLV->value, TipoDocumentoMotorista::NADA_CONSTA->value], true)
            && ! $request->filled('informacoes_complementares');
        $regrasDasInformacoes = $semDados
            ? ['veiculo_id' => $request->input('tipo_documento') === TipoDocumentoMotorista::CRLV->value ? 'nullable|integer' : 'prohibited']
            : $informacoesService->regras($request);
        $dados = $request->validate([
            ...$regrasDasInformacoes,
            'tipo_documento' => ['required', Rule::enum(TipoDocumentoMotorista::class)],
            'arquivo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif,gif,pdf|max:10240',
            'arquivo_verso' => $this->armazenarAnexoMotoristaService->regrasVerso($request, 'jpg,jpeg,png,webp,heic,heif,gif', 10240),
        ]);

        $motorista = PerfilLogado::motorista($request) ?? abort(403, 'Perfil de motorista não encontrado.');

        // o motorista ativo também reenvia CNH, Nada Consta e Seguro; o CRLV de quem
        // já está ativo entra pelo cadastro de novo veículo
        if ($motorista->status === self::ATIVO && $dados['tipo_documento'] === TipoDocumentoMotorista::CRLV->value) {
            return response()->json([
                'message' => 'Seu cadastro já foi aprovado. Para trocar o CRLV, envie-o pelo cadastro de veículo.',
            ], 409);
        }

        $atual = app(DocumentosMotoristaService::class)->paraAcompanhar($motorista)
            ->first(fn (MotoristaDocumento $documento) => $documento->tipo_documento->value === $dados['tipo_documento']);
        if ($atual !== null && $atual->status !== 'reprovado' && ! $atual->vencido) {
            return response()->json([
                'message' => 'Este documento já foi enviado. Só é possível enviar outro se ele vencer ou for recusado.',
            ], 409);
        }

        if ($dados['tipo_documento'] === 'crlv' && isset($dados['veiculo_id'])) {
            $veiculo = $crlvService->veiculoVinculado($motorista->id, (int) $dados['veiculo_id']);
            if (! $semDados) {
                $crlvService->validar($veiculo, $dados['informacoes_complementares']);
            }
        }
        $this->conferirTipoDoArquivo($request, TipoDocumentoMotorista::from($dados['tipo_documento']));
        $anexo = $this->armazenarAnexoMotoristaService->salvarEnvio($request);
        try {
            [$documento, $situacao] = DB::transaction(function () use ($motorista, $dados, $anexo, $semDados): array {
                $motorista = Motorista::lockForUpdate()->findOrFail($motorista->id);
                if ($dados['tipo_documento'] === 'cnh' && isset($dados['informacoes_complementares'])) {
                    $motorista->update($dados['informacoes_complementares']);
                }
                $veiculo = match (true) {
                    $dados['tipo_documento'] !== 'crlv' => null,
                    // sem os dados, o CRLV fica no veículo escolhido ou no veículo do motorista
                    $semDados => isset($dados['veiculo_id'])
                        ? app(CrlvVeiculoService::class)->veiculoVinculado($motorista->id, (int) $dados['veiculo_id'])
                        : Veiculo::whereKey(MotoristaVeiculo::where('motorista_id', $motorista->id)->orderBy('id')->value('veiculo_id'))->first(),
                    default => app(CrlvVeiculoService::class)->registrarOuAtualizar($motorista, $dados['informacoes_complementares'], $dados['veiculo_id'] ?? null),
                };

                $documento = $this->armazenarAnexoMotoristaService->registrarEnvio([
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

                return [$documento, $situacao];
            });
        } catch (Throwable $exception) {
            $this->armazenarAnexoMotoristaService->excluirEnvio($anexo);
            throw $exception;
        }

        return response()->json([
            'message' => 'Documento enviado para análise.',
            'documento' => $documento->load(['veiculo']),
            'situacao' => $situacao,
        ], 201);
    }

    /** Recusa o arquivo antes de guardá-lo, para não substituir o documento atual por outro tipo. */
    private function conferirTipoDoArquivo(Request $request, TipoDocumentoMotorista $tipo): void
    {
        $verificacao = app(VerificacaoTipoDocumentoService::class);
        $verificacao->validar($request->file('arquivo'), $tipo);
        if ($request->hasFile('arquivo_verso')) {
            $verificacao->validar($request->file('arquivo_verso'), $tipo, 'arquivo_verso');
        }
    }

    /**
     * Remove um documento do próprio motorista, enquanto o cadastro não foi
     * aprovado.
     */
    public function removerDocumento(Request $request, int $documento): JsonResponse
    {
        $motorista = PerfilLogado::motorista($request);

        if ($motorista === null) {
            return response()->json(['message' => 'Você ainda não tem cadastro de motorista.'], 403);
        }

        $registro = MotoristaDocumento::where('id', $documento)
            ->where('motorista_id', $motorista->id)
            ->first();

        if ($registro === null) {
            return response()->json(['message' => 'Documento não encontrado.'], 404);
        }

        $registro = $registro->principalDoEnvio();
        $this->armazenarAnexoMotoristaService->excluirEnvio($registro->toArray());

        $situacao = DB::transaction(function () use ($motorista, $registro): string {
            $motorista = Motorista::lockForUpdate()->findOrFail($motorista->id);
            $registro = MotoristaDocumento::lockForUpdate()->findOrFail($registro->id);
            $veiculo = $registro->tipo_documento === TipoDocumentoMotorista::CRLV->value ? $registro->veiculo : null;
            $registro->anexosDoEnvio()->delete();
            if ($veiculo instanceof Veiculo) {
                app(CrlvVeiculoService::class)->sincronizarStatus($veiculo);
            }

            return $this->atualizarSituacaoMotoristaService->executar($motorista);
        });

        return response()->json([
            'message' => 'Documento removido.',
            'situacao' => $situacao,
        ]);
    }

    /**
     * Atalho de desenvolvimento: pula CNH e documentos e aprova o motorista
     * direto, sem passar pela análise. Mesmo espírito do código de
     * verificação fake em AutenticacaoAppController::enviarCodigo — remover antes de
     * publicar.
     */
    public function aprovarDev(Request $request): JsonResponse
    {
        abort_unless(App::environment(['local', 'testing']), 404);

        $motorista = DB::transaction(function () use ($request): Motorista {
            $motorista = (PerfilLogado::motorista($request) ?? abort(403, 'Perfil de motorista não encontrado.'));
            $motorista->numero_registro ??= '00000000000';
            $motorista->cnh_categoria ??= 'B';
            $motorista->cnh_expiracao ??= now()->addYears(5)->toDateString();
            $motorista->ear ??= false;
            $motorista->save();

            $veiculos = MotoristaVeiculo::where('motorista_id', $motorista->id)
                ->with('veiculo')
                ->get()
                ->pluck('veiculo')
                ->filter()
                ->values();

            if ($veiculos->isEmpty()) {
                $veiculo = $this->criarVeiculoDev($motorista);
                MotoristaVeiculo::create([
                    'motorista_id' => $motorista->id,
                    'veiculo_id' => $veiculo->id,
                ]);
                $veiculos->push($veiculo);
            }

            foreach (TipoDocumentoMotorista::valores() as $tipo) {
                if ($tipo === TipoDocumentoMotorista::CRLV->value) {
                    continue;
                }

                $this->aprovarDocumentoDev($motorista, $tipo);
            }

            foreach ($veiculos as $veiculo) {
                $veiculo->update(['status' => self::APROVADO]);
                $this->aprovarDocumentoDev($motorista, TipoDocumentoMotorista::CRLV->value, $veiculo);
            }

            MotoristaDocumento::where('motorista_id', $motorista->id)
                ->where('tipo_documento', TipoDocumentoMotorista::CRLV)
                ->whereNull('veiculo_id')
                ->where('path', 'like', 'motorista_documentos_anexos/dev-%')
                ->delete();

            $motorista->update(['status' => self::ATIVO]);

            return $motorista;
        });

        return response()->json([
            'message' => 'Cadastro aprovado (atalho de desenvolvimento).',
            'situacao' => $motorista->status,
        ]);
    }

    /**
     * Atalho de desenvolvimento: leva à home sem enviar nada, para testar as ações
     * necessárias com todos os documentos e o veículo faltando. Remover antes de publicar.
     */
    public function liberarDev(Request $request): JsonResponse
    {
        abort_unless(App::environment(['local', 'testing']), 404);

        $motorista = (PerfilLogado::motorista($request) ?? abort(403, 'Perfil de motorista não encontrado.'));
        $motorista->forceFill(['liberado_em' => $motorista->liberado_em ?? now()])->save();

        return response()->json([
            'message' => 'Entrou sem documentos (atalho de desenvolvimento).',
            'situacao' => $motorista->status,
        ]);
    }

    private function criarVeiculoDev(Motorista $motorista): Veiculo
    {
        $sufixo = str_pad((string) $motorista->id, 2, '0', STR_PAD_LEFT);

        return Veiculo::create([
            'marca' => 'Chevrolet',
            'modelo' => 'Onix de desenvolvimento',
            'ano_fabricacao' => now()->year,
            'ano_modelo' => now()->year,
            'cor' => 'Branco',
            'placa' => 'DEV'.($motorista->id % 10).'A'.substr($sufixo, -2),
            'renavam' => '9'.str_pad((string) $motorista->id, 10, '0', STR_PAD_LEFT),
            'categoria' => 'carro',
            'status' => self::APROVADO,
            'uf' => 'RO',
        ]);
    }

    private function aprovarDocumentoDev(Motorista $motorista, string $tipo, ?Veiculo $veiculo = null): void
    {
        $nome = $veiculo === null ? "dev-$tipo.pdf" : "dev-$tipo-{$veiculo->id}.pdf";

        MotoristaDocumento::updateOrCreate([
            'motorista_id' => $motorista->id,
            'tipo_documento' => $tipo,
            'veiculo_id' => $veiculo?->id,
            'ordem' => 0,
        ], [
            'name' => $nome,
            'type' => 'pdf',
            'mime_type' => 'application/pdf',
            'size' => 0,
            'path' => "motorista_documentos_anexos/$nome",
            'url' => rtrim((string) config('app.url'), '/')."/motorista_documentos_anexos/$nome",
            'informacoes_complementares' => $veiculo === null ? null : [
                'placa' => $veiculo->placa,
                'renavam' => $veiculo->renavam,
            ],
            'status' => self::APROVADO,
        ]);
    }

    /**
     * @return list<string>
     */
    private function pendencias(Motorista $motorista, int $veiculos): array
    {
        $pendencias = [];

        if ($motorista->numero_registro === null) {
            $pendencias[] = 'cnh';
        }

        if ($this->atualizarSituacaoMotoristaService->documentosQueFaltam($motorista) !== []) {
            $pendencias[] = 'documentos';
        }

        if ($veiculos === 0) {
            $pendencias[] = 'veiculo';
        }

        return $pendencias;
    }
}
