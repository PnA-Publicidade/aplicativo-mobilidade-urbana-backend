<?php

namespace App\Http\Controllers\Motorista;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\MotoristaVeiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MotoristaCadastroController extends Controller
{
    public const APROVADO = 'aprovado';

    public const SEM_CADASTRO = 'sem_cadastro';

    /**
     * Onde o motorista está na esteira de liberação. Responde também para
     * quem ainda não é motorista — é por aqui que um passageiro descobre
     * que precisa enviar documentos para virar motorista.
     */
    public function mostrar(Request $request): JsonResponse
    {
        $motorista = Motorista::where('user_id', $request->user()->id)->first();

        if ($motorista === null) {
            return response()->json([
                'situacao' => self::SEM_CADASTRO,
                'cnh' => null,
                'documentos' => [],
                'veiculos' => 0,
                'pendencias' => ['cnh', 'documentos', 'veiculo'],
            ]);
        }

        $documentos = MotoristaDocumento::where('motorista_id', $motorista->id)
            ->get(['tipo_documento', 'status', 'observacao']);

        $veiculos = MotoristaVeiculo::where('motorista_id', $motorista->id)->count();

        return response()->json([
            'situacao' => $motorista->status,
            'cnh' => $motorista->cnh_numero === null ? null : [
                'numero' => $motorista->cnh_numero,
                'categoria' => $motorista->cnh_categoria,
                'expiracao' => $motorista->cnh_expiracao,
                'ear' => (bool) $motorista->ear,
            ],
            'documentos' => $documentos,
            'veiculos' => $veiculos,
            'pendencias' => $this->pendencias($motorista, $documentos->count(), $veiculos),
        ]);
    }

    /**
     * Envia ou corrige os dados da CNH. Cria o registro de motorista quando
     * quem chama é um passageiro que está virando motorista.
     */
    public function salvarCnh(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'cnh_numero' => 'required|string|max:20',
            'cnh_categoria' => 'required|string|in:A,B,AB,C,D,E,a,b,ab,c,d,e',
            'cnh_expiracao' => 'required|date|after:today',
            'ear' => 'required|boolean',
        ], [
            'cnh_numero.required' => 'Informe o número da sua CNH.',
            'cnh_categoria.in' => 'Categoria de CNH inválida.',
            'cnh_expiracao.after' => 'Sua CNH está vencida.',
        ]);

        $motorista = Motorista::firstOrNew(['user_id' => $request->user()->id]);

        if ($motorista->exists && $motorista->status === self::APROVADO) {
            return response()->json([
                'message' => 'Seu cadastro já foi aprovado. Fale com o suporte para alterar a CNH.',
            ], 409);
        }

        $motorista->fill([
            'cnh_numero' => preg_replace('/\D/', '', (string) $dados['cnh_numero']),
            'cnh_categoria' => strtoupper((string) $dados['cnh_categoria']),
            'cnh_expiracao' => $dados['cnh_expiracao'],
            'ear' => (bool) $dados['ear'],
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
        $dados = $request->validate([
            'tipo_documento' => 'required|string|max:60',
            'arquivo' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        $motorista = Motorista::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['status' => 'pendente']
        );

        if ($motorista->status === self::APROVADO) {
            return response()->json([
                'message' => 'Seu cadastro já foi aprovado. Fale com o suporte para trocar documentos.',
            ], 409);
        }

        $arquivo = $request->file('arquivo');
        $caminho = $arquivo->storeAs(
            'motorista_documentos',
            time().'_'.$arquivo->getClientOriginalName()
        );

        $documento = MotoristaDocumento::create([
            'motorista_id' => $motorista->id,
            'tipo_documento' => $dados['tipo_documento'],
            'name' => $arquivo->getClientOriginalName(),
            'type' => $arquivo->extension(),
            'mime_type' => $arquivo->getMimeType(),
            'size' => $arquivo->getSize(),
            'path' => $caminho,
            'status' => 'em_analise',
        ]);

        // documento novo devolve o cadastro para a fila de análise
        if ($motorista->status !== 'em_analise') {
            $motorista->update(['status' => 'em_analise']);
        }

        return response()->json([
            'message' => 'Documento enviado para análise.',
            'documento' => $documento->only(['tipo_documento', 'status']),
            'situacao' => $motorista->status,
        ], 201);
    }

    /**
     * Remove um documento do próprio motorista, enquanto o cadastro não foi
     * aprovado.
     */
    public function removerDocumento(Request $request, int $documento): JsonResponse
    {
        $motorista = Motorista::where('user_id', $request->user()->id)->first();

        if ($motorista === null) {
            return response()->json(['message' => 'Você ainda não tem cadastro de motorista.'], 403);
        }

        $registro = MotoristaDocumento::where('id', $documento)
            ->where('motorista_id', $motorista->id)
            ->first();

        if ($registro === null) {
            return response()->json(['message' => 'Documento não encontrado.'], 404);
        }

        if ($registro->path && Storage::disk('local')->exists($registro->path)) {
            Storage::disk('local')->delete($registro->path);
        }

        $registro->delete();

        return response()->json(['message' => 'Documento removido.']);
    }

    /**
     * @return list<string>
     */
    private function pendencias(Motorista $motorista, int $totalDocumentos, int $veiculos): array
    {
        $pendencias = [];

        if ($motorista->cnh_numero === null) {
            $pendencias[] = 'cnh';
        }

        if ($totalDocumentos === 0) {
            $pendencias[] = 'documentos';
        }

        if ($veiculos === 0) {
            $pendencias[] = 'veiculo';
        }

        return $pendencias;
    }
}
