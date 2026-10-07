<?php

namespace App\Models;

use App\Enums\MotivoReprovacaoDocumento;
use App\Enums\TipoDocumentoMotorista;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class MotoristaDocumento extends Model
{
    protected $table = 'motorista_documentos_anexos';

    protected $fillable = [
        'motorista_id',
        'tipo_documento',
        'name',
        'type',
        'mime_type',
        'size',
        'path',
        'status',
        'url',
        'verso',
        'motivo_reprovacao',
        'descricao_reprovacao',
    ];

    protected $appends = ['motivo_reprovacao_texto'];

    protected function motivoReprovacaoTexto(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->status !== 'reprovado' || $this->motivo_reprovacao === null) {
                return null;
            }

            return $this->motivo_reprovacao === MotivoReprovacaoDocumento::OUTRO
                ? $this->descricao_reprovacao
                : $this->motivo_reprovacao->titulo();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tipo_documento' => TipoDocumentoMotorista::class, 'verso' => 'array', 'motivo_reprovacao' => MotivoReprovacaoDocumento::class];
    }
}
