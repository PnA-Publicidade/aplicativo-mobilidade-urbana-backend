<?php

namespace App\Services;

use App\Enums\TipoDocumentoMotorista;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\MotoristaVeiculo;
use Illuminate\Support\Carbon;
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

    /**
     * O que o motorista acompanha no app: os últimos envios e o CRLV que ainda não
     * tem veículo, porque o veículo só é criado quando a gestão aprova.
     */
    public function paraAcompanhar(Motorista $motorista): Collection
    {
        $crlvSemVeiculo = MotoristaDocumento::where('motorista_id', $motorista->id)
            ->where('tipo_documento', TipoDocumentoMotorista::CRLV)->where('ordem', 0)
            ->whereNull('veiculo_id')->latest('id')->first();

        return $this->ultimos($motorista)->push($crlvSemVeiculo)->filter()->sortByDesc('id')->values()
            ->each(fn (MotoristaDocumento $documento) => $documento->setAttribute('vencido', $this->vencido($motorista, $documento)));
    }

    /**
     * O que impede o motorista de ficar online até ser resolvido.
     *
     * @return list<string>
     */
    public function acoesNecessarias(Motorista $motorista): array
    {
        $documentos = $this->paraAcompanhar($motorista)->unique(fn (MotoristaDocumento $documento) => $documento->tipo_documento->value);
        $acoes = $documentos->filter(fn (MotoristaDocumento $documento) => $documento->vencido || $documento->status === 'reprovado')
            ->map(fn (MotoristaDocumento $documento) => $documento->tipo_documento->value)->values()->all();

        if ($this->veiculos($motorista)->isNotEmpty()) {
            return $acoes;
        }

        // sem veículo o CRLV sai pelo cadastro do veículo; em análise, é só aguardar
        $crlv = $documentos->first(fn (MotoristaDocumento $documento) => $documento->tipo_documento === TipoDocumentoMotorista::CRLV);
        $acoes = array_values(array_diff($acoes, [TipoDocumentoMotorista::CRLV->value]));

        return $crlv === null || $crlv->status === 'reprovado' ? [...$acoes, 'veiculo'] : $acoes;
    }

    /**
     * Aprovado, mas com a validade passada. O CRLV traz só o exercício: quem o vence é a
     * gestão, reprovando.
     */
    private function vencido(Motorista $motorista, MotoristaDocumento $documento): bool
    {
        $validade = match ($documento->tipo_documento) {
            TipoDocumentoMotorista::CNH => $motorista->cnh_expiracao,
            TipoDocumentoMotorista::NADA_CONSTA, TipoDocumentoMotorista::SEGURO_OBRIGATORIO => $documento->informacoes_complementares['data_validade'] ?? null,
            default => null,
        };
        if ($documento->status !== 'aprovado' || $validade === null) {
            return false;
        }
        $validade = Carbon::parse($validade)->endOfDay();

        // a data da CNH é do cadastro: enviada depois dela, foi renovada e a gestão aprovou
        // sem atualizar o cadastro. Nos outros a data é do próprio documento.
        return $validade->isPast()
            && ($documento->tipo_documento !== TipoDocumentoMotorista::CNH || $documento->created_at->lte($validade));
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
