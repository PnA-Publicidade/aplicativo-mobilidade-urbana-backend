<?php

namespace App\Services;

use App\Models\MotoristaDocumento;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ImpedirCrlvDuplicadoService
{
    /** A chamada final ocorre com o motorista bloqueado na transação do envio. */
    public function verificar(int $motoristaId, string $hash, array $informacoes = []): void
    {
        $documentos = MotoristaDocumento::where('motorista_id', $motoristaId)
            ->where('tipo_documento', 'crlv')->where('ordem', 0)
            ->whereIn('status', ['em_analise', 'aprovado'])->with('veiculo')->get();
        foreach ($documentos as $documento) {
            $sha = $documento->solicitacao_veiculo['arquivo_sha256'] ?? null;
            if (! $sha && $documento->path) {
                // Compatibilidade com envios anteriores, sem depender de nome do arquivo.
                $disk = Storage::disk(ArmazenarAnexoMotoristaService::DIRETORIO);
                $nome = basename($documento->path);
                if ($disk->exists($nome)) {
                    $stream = $disk->readStream($nome);
                    if (is_resource($stream)) {
                        try {
                            $contexto = hash_init('sha256');
                            hash_update_stream($contexto, $stream);
                            $sha = hash_final($contexto);
                        } finally {
                            fclose($stream);
                        }
                    }
                }
            }
            $mesmoArquivo = $sha && hash_equals($sha, $hash);
            $salvos = $documento->informacoes_complementares ?? [];
            $anoAnterior = $salvos['exercicio'] ?? $documento->veiculo?->exercicio;
            $mesmoCrlv = false;
            if (! empty($informacoes['exercicio']) && (string) $informacoes['exercicio'] === (string) $anoAnterior) {
                foreach (['placa', 'renavam', 'chassi'] as $campo) {
                    $anterior = $salvos[$campo] ?? $documento->veiculo?->{$campo};
                    if (! empty($informacoes[$campo]) && $anterior !== null && $this->normalizar($informacoes[$campo]) === $this->normalizar($anterior)) {
                        $mesmoCrlv = true;
                        break;
                    }
                }
            }
            if ($mesmoArquivo || $mesmoCrlv) {
                $situacao = $documento->status === 'aprovado' ? 'já foi aprovado' : 'já está em análise';
                throw ValidationException::withMessages([
                    'arquivo' => "Este CRLV já foi enviado e $situacao. Consulte o envio existente para acompanhar a análise.",
                ]);
            }
        }
    }

    private function normalizar(string $value): string
    {
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $value));
    }
}
