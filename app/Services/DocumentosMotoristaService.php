<?php

namespace App\Services;

use App\Enums\TipoDocumentoMotorista;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\MotoristaVeiculo;
use Illuminate\Support\Collection;

class DocumentosMotoristaService
{
    public function veiculos(Motorista $motorista): Collection
    {
        return MotoristaVeiculo::where('motorista_id', $motorista->id)->with('veiculo.ultimoCrlv')->orderBy('id')->get()->pluck('veiculo')->filter()->unique('id')->values();
    }

    public function ultimos(Motorista $motorista): Collection
    {
        $veiculos = $this->veiculos($motorista)->pluck('id');

        return MotoristaDocumento::where('ordem', 0)->where(function ($query) use ($motorista, $veiculos): void {
            $query->where(fn ($pessoais) => $pessoais->where('motorista_id', $motorista->id)->where('tipo_documento', '!=', TipoDocumentoMotorista::CRLV))
                ->orWhere(fn ($crlv) => $crlv->where('tipo_documento', TipoDocumentoMotorista::CRLV)->whereIn('veiculo_id', $veiculos));
        })->with('veiculo')->orderByDesc('id')->get()
            ->unique(fn ($d) => $d->tipo_documento->value.':'.($d->veiculo_id ?? 'motorista'))->values();
    }

    public function resumo(Motorista $motorista): array
    {
        $ultimos = $this->ultimos($motorista);
        $veiculos = $this->veiculos($motorista);
        $rows = [];
        foreach (TipoDocumentoMotorista::catalogo() as $tipo) {
            $base = [...$tipo, 'id' => null, 'status' => null, 'url' => null, 'verso' => null, 'veiculo_id' => null, 'ordem' => 0, 'informacoes_complementares' => null];
            if ($tipo['tipo_documento'] !== 'crlv') {
                $doc = $ultimos->first(fn ($d) => $d->tipo_documento->value === $tipo['tipo_documento']);
                $rows[] = [...$base, ...($doc?->toArray() ?? []), 'chave' => $tipo['tipo_documento']];

                continue;
            }
            foreach ($veiculos->take(1) as $veiculo) {
                $doc = $ultimos->first(fn ($d) => $d->tipo_documento === TipoDocumentoMotorista::CRLV && $d->veiculo_id === $veiculo->id);
                $rows[] = [...$base, ...($doc?->toArray() ?? []), 'chave' => 'crlv:'.$veiculo->id, 'veiculo_id' => $veiculo->id, 'veiculo' => $veiculo->toArray()];
            }
            $legado = MotoristaDocumento::where('motorista_id', $motorista->id)->where('tipo_documento', 'crlv')->where('ordem', 0)->whereNull('veiculo_id')->whereNull('solicitacao_veiculo')->orderByDesc('id')->first();
            if ($legado) {
                $rows[] = [...$base, ...$legado->toArray(), 'chave' => 'crlv:legado', 'legado' => true];
            }
            if ($veiculos->isEmpty() && ! $legado) {
                $rows[] = [...$base, 'chave' => 'crlv:sem_veiculo', 'sem_veiculo' => true];
            }
        }

        // Cada envio adicional aparece individualmente na gestão, sem ocultar os anteriores.
        foreach (MotoristaDocumento::where('motorista_id', $motorista->id)->whereNotNull('solicitacao_veiculo')->with('veiculo')->latest('id')->get() as $solicitacao) {
            if (collect($rows)->contains(fn ($row) => ($row['id'] ?? null) === $solicitacao->id)) {
                continue;
            }
            $catalogo = collect(TipoDocumentoMotorista::catalogo())->firstWhere('tipo_documento', 'crlv');
            $rows[] = [...$catalogo, ...$solicitacao->toArray(), 'chave' => 'crlv:solicitacao:'.$solicitacao->id, 'nova_solicitacao' => true];
        }

        return $rows;
    }
}
