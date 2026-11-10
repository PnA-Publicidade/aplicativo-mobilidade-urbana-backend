<?php

namespace App\Services;

use App\Enums\TipoDocumentoMotorista;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use Illuminate\Support\Collection;

class AtualizarSituacaoMotoristaService
{
    public function executar(Motorista $motorista): string
    {
        $situacao = $this->calcular($motorista);

        if ($motorista->status !== $situacao) {
            $motorista->update(['status' => $situacao]);
        }

        return $situacao;
    }

    /**
     * @return list<string>
     */
    public function documentosQueFaltam(Motorista $motorista): array
    {
        $service = app(DocumentosMotoristaService::class);
        $documentos = $service->ultimos($motorista);
        $faltam = [];
        foreach (TipoDocumentoMotorista::cases() as $tipo) {
            if ($tipo === TipoDocumentoMotorista::CRLV) {
                $veiculos = $service->veiculos($motorista);
                if ($veiculos->isEmpty() || $veiculos->contains(fn ($v) => ! $documentos->contains(fn ($d) => $d->tipo_documento === $tipo && $d->veiculo_id === $v->id && $d->status === 'aprovado'))) {
                    $faltam[] = $tipo->value;
                }
            } elseif (! $this->emDia($motorista, $tipo, $documentos)) {
                $faltam[] = $tipo->value;
            }
        }

        return $faltam;
    }

    /**
     * Aprovado, ou renovação em análise de um documento que já foi aprovado: quem
     * reenvia a CNH, o Nada Consta ou o Seguro continua ativo até a gestão decidir.
     */
    private function emDia(Motorista $motorista, TipoDocumentoMotorista $tipo, Collection $documentos): bool
    {
        $ultimo = $documentos->first(fn ($d) => $d->tipo_documento === $tipo);

        return match ($ultimo?->status) {
            'aprovado' => true,
            'em_analise' => MotoristaDocumento::where('motorista_id', $motorista->id)
                ->where('tipo_documento', $tipo)->where('ordem', 0)->where('status', 'aprovado')->exists(),
            default => false,
        };
    }

    private function calcular(Motorista $motorista): string
    {
        return $this->documentosQueFaltam($motorista) === [] ? 'ativo' : 'pendente';
    }
}
