<?php

namespace App\Enums;

enum MotivoReprovacaoDocumento: string
{
    case ILEGIVEL = 'ilegivel';
    case INCOMPLETO = 'incompleto';
    case VENCIDO = 'vencido';
    case DADOS_DIVERGENTES = 'dados_divergentes';
    case TIPO_INCORRETO = 'tipo_incorreto';
    case FRENTE_VERSO_AUSENTE = 'frente_verso_ausente';
    case OUTRO = 'outro';

    public function titulo(): string
    {
        return match ($this) {
            self::ILEGIVEL => 'Documento ilegível',
            self::INCOMPLETO => 'Documento incompleto',
            self::VENCIDO => 'Documento vencido',
            self::DADOS_DIVERGENTES => 'Dados divergentes',
            self::TIPO_INCORRETO => 'Tipo de documento incorreto',
            self::FRENTE_VERSO_AUSENTE => 'Frente ou verso ausente',
            self::OUTRO => 'Outro',
        };
    }

    public static function catalogo(): array
    {
        return array_map(fn (self $motivo): array => [
            'value' => $motivo->value,
            'label' => $motivo->titulo(),
            'exige_descricao' => $motivo === self::OUTRO,
        ], self::cases());
    }
}
