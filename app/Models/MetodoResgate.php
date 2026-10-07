<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Para onde vão os saques do motorista: uma chave Pix e/ou uma conta
 * bancária, uma delas marcada como principal.
 *
 * @property int $id
 * @property int $motorista_id
 * @property string $tipo
 * @property string|null $pix_tipo
 * @property string|null $pix_chave
 * @property string $documento
 * @property string|null $titular_nome
 * @property string|null $banco_codigo
 * @property string|null $banco_nome
 * @property string|null $agencia
 * @property string|null $agencia_digito
 * @property string|null $conta
 * @property string|null $conta_digito
 * @property string|null $conta_tipo
 * @property bool $principal
 */
class MetodoResgate extends Model
{
    public const TIPOS = ['pix', 'conta'];

    public const TIPOS_PIX = ['cpf', 'cnpj', 'telefone', 'email', 'aleatoria'];

    public const TIPOS_CONTA = ['corrente', 'poupanca'];

    private const ROTULOS_PIX = [
        'cpf' => 'CPF',
        'cnpj' => 'CNPJ',
        'telefone' => 'Celular',
        'email' => 'E-mail',
        'aleatoria' => 'Chave aleatória',
    ];

    protected $table = 'metodos_resgate';

    protected $fillable = [
        'motorista_id',
        'tipo',
        'pix_tipo',
        'pix_chave',
        'documento',
        'titular_nome',
        'banco_codigo',
        'banco_nome',
        'agencia',
        'agencia_digito',
        'conta',
        'conta_digito',
        'conta_tipo',
        'principal',
    ];

    protected $hidden = ['motorista_id'];

    protected $appends = ['descricao'];

    protected function casts(): array
    {
        return [
            'principal' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Motorista, $this>
     */
    public function motorista(): BelongsTo
    {
        return $this->belongsTo(Motorista::class);
    }

    public function getDescricaoAttribute(): string
    {
        return $this->tipo === 'pix'
            ? 'Pix · '.(self::ROTULOS_PIX[$this->pix_tipo] ?? 'Chave').' '.$this->chaveMascarada()
            : trim((string) $this->banco_nome).' · Ag. '.$this->agencia.' · Conta •••'.substr((string) $this->conta, -3).'-'.$this->conta_digito;
    }

    private function chaveMascarada(): string
    {
        $chave = (string) $this->pix_chave;

        return match ($this->pix_tipo) {
            'cpf' => '•••.'.substr($chave, 3, 3).'.'.substr($chave, 6, 3).'-••',
            'cnpj' => '••.'.substr($chave, 2, 3).'.'.substr($chave, 5, 3).'/'.substr($chave, 8, 4).'-••',
            'telefone' => '('.substr($chave, 0, 2).') •••••-'.substr($chave, -4),
            'email' => substr($chave, 0, 2).'•••'.strstr($chave, '@'),
            default => '••••'.substr($chave, -4),
        };
    }
}
