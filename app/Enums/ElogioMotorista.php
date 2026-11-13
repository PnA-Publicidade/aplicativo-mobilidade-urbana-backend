<?php

namespace App\Enums;

/** Elogios que o passageiro pode marcar ao avaliar o motorista. */
enum ElogioMotorista: string
{
    case BOM_CONDUTOR = 'bom_condutor';
    case SABE_O_CAMINHO = 'sabe_o_caminho';
    case VEICULO_LIMPO = 'veiculo_limpo';
    case PONTUAL = 'pontual';
    case EDUCADO = 'educado';
    case SIMPATICO = 'simpatico';

    public function titulo(): string
    {
        return match ($this) {
            self::BOM_CONDUTOR => 'Bom condutor',
            self::SABE_O_CAMINHO => 'Sabe o caminho',
            self::VEICULO_LIMPO => 'Veículo limpo',
            self::PONTUAL => 'Pontual',
            self::EDUCADO => 'Educado',
            self::SIMPATICO => 'Simpático',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::BOM_CONDUTOR => 'Obedece às regras de trânsito, dirige com habilidade e permanece atento(a)',
            self::SABE_O_CAMINHO => 'Familiarizado(a) com as condições de trânsito e evita desvios de rota desnecessários',
            self::VEICULO_LIMPO => 'Mantém o interior do veículo limpo e sem odores desagradáveis',
            self::PONTUAL => 'Viagem feita dentro do horário previsto para embarque e desembarque',
            self::EDUCADO => 'Respeitoso(a) e atencioso(a)',
            self::SIMPATICO => 'Torna a viagem mais agradável',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(fn (self $elogio): string => $elogio->value, self::cases());
    }
}
