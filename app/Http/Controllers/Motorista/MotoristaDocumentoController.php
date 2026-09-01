<?php

namespace App\Http\Controllers\Motorista;

use App\Http\Controllers\Controller;
use App\Models\MotoristaDocumento;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MotoristaDocumentoController extends Controller
{
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
        $request->validate([
            'motorista_id' => 'required|integer',
            'tipo_documento' => 'required|string',
            'arquivo' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048', // 2MB
        ]);

        $file = $request->file('arquivo');

        // Nome único
        $fileName = time().'_'.$file->getClientOriginalName();

        // Salvar arquivo
        $path = $file->storeAs('motorista_documentos', $fileName);

        // Salvar no banco
        $motoristaDocumento = MotoristaDocumento::create([
            'motorista_id' => $request->motorista_id,
            'tipo_documento' => $request->tipo_documento,
            'name' => $file->getClientOriginalName(),
            'type' => $file->extension(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'status' => 'em_analise',
        ]);

        return response()->json([
            'message' => 'Arquivo enviado com sucesso',
            'data' => $motoristaDocumento,
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

        // Verifica e deleta o arquivo no storage PRIVATE (local)
        if ($motoristaDocumento->path && Storage::disk('local')->exists($motoristaDocumento->path)) {
            Storage::disk('local')->delete($motoristaDocumento->path);
        }

        // Remove do banco (soft delete)
        $motoristaDocumento->delete();

        return response()->json([
            'message' => 'Documento removido com sucesso',
        ]);
    }

    public function mudarStatusDocumento(Request $request, int $motoristaDocumentoId): JsonResponse
    {
        $motoristaDocumento = MotoristaDocumento::findOrFail($motoristaDocumentoId);
        $motoristaDocumento->status = $request->status;
        $motoristaDocumento->saveOrFail();

        return response()->json([
            'message' => 'Status do documento alterado com sucesso',
        ]);
    }
}
