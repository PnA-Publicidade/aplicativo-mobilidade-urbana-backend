<?php

namespace App\Services;

use App\Models\Motorista;
use App\Models\MotoristaDocumento;

class AtualizarSituacaoMotoristaService
{
    /**
     * Documentos que o painel de gestão analisa. A liberação do motorista é
     * derivada deles: aprovar o último documento aprova o motorista.
     */
    public const DOCUMENTOS_EXIGIDOS = [
        'cnh',
        'crlv',
        'nada_consta',
        'seguro_obrigatorio',
    ];

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
        $documentos = MotoristaDocumento::where('motorista_id', $motorista->id)
            ->whereIn('tipo_documento', self::DOCUMENTOS_EXIGIDOS)
            ->orderByDesc('id')
            ->get(['tipo_documento', 'status'])
            ->unique('tipo_documento');

        $aprovados = $documentos
            ->where('status', 'aprovado')
            ->pluck('tipo_documento')
            ->all();

        return array_values(array_diff(self::DOCUMENTOS_EXIGIDOS, $aprovados));
    }

    private function calcular(Motorista $motorista): string
    {
        $documentos = MotoristaDocumento::where('motorista_id', $motorista->id)
            ->whereIn('tipo_documento', self::DOCUMENTOS_EXIGIDOS)
            ->orderByDesc('id')
            ->get(['tipo_documento', 'status'])
            ->unique('tipo_documento');

        if ($documentos->isEmpty()) {
            return 'pendente';
        }

        // um documento reprovado reprova o cadastro: o motorista precisa
        // reenviar aquele item
        if ($documentos->contains(fn ($documento) => $documento->status === 'reprovado')) {
            return 'reprovado';
        }

        $aprovados = $documentos
            ->where('status', 'aprovado')
            ->pluck('tipo_documento')
            ->unique();

        $faltam = array_diff(self::DOCUMENTOS_EXIGIDOS, $aprovados->all());

        return $faltam === [] ? 'aprovado' : 'em_analise';
    }
}
