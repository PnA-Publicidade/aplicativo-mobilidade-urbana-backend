<?php

namespace App\Models;

use App\Enums\MotivoReprovacaoDocumento;
use App\Enums\TipoDocumentoMotorista;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MotoristaDocumento extends Model
{
    protected $table = 'motorista_documentos_anexos';

    protected $attributes = ['ordem' => 0];

    protected $fillable = [
        'motorista_id',
        'veiculo_id',
        'ordem',
        'informacoes_complementares',
        'solicitacao_veiculo',
        'tipo_documento',
        'name',
        'type',
        'mime_type',
        'size',
        'path',
        'status',
        'url',
        'motivo_reprovacao',
        'descricao_reprovacao',
    ];

    public function veiculo(): BelongsTo
    {
        return $this->belongsTo(Veiculo::class);
    }

    protected $appends = ['motivo_reprovacao_texto', 'verso'];

    protected $hidden = ['anexoVerso'];

    /** Os dois lados usam o mesmo nome gerado, com o sufixo _verso no segundo. */
    public static function caminhosDoEnvio(string $path): array
    {
        $base = dirname($path).'/'.preg_replace('/_verso$/', '', pathinfo($path, PATHINFO_FILENAME));
        $caminhos = [$path];
        foreach (['jpg', 'jpeg', 'png', 'pdf'] as $extensao) {
            $caminhos[] = $base.'.'.$extensao;
            $caminhos[] = $base.'_verso.'.$extensao;
        }

        return array_values(array_unique($caminhos));
    }

    public function anexosDoEnvio(): Builder
    {
        if ($this->tipo_documento !== TipoDocumentoMotorista::CNH) {
            return self::whereKey($this->id);
        }

        return self::where('motorista_id', $this->motorista_id)
            ->where('tipo_documento', TipoDocumentoMotorista::CNH)
            ->whereIn('path', self::caminhosDoEnvio($this->path));
    }

    public function principalDoEnvio(): self
    {
        return $this->ordem === 1 ? $this->anexosDoEnvio()->where('ordem', 0)->firstOrFail() : $this;
    }

    // Mantém a resposta usada pelos clientes, mas o verso é um registro próprio.
    protected function verso(): Attribute
    {
        return Attribute::get(function (): ?array {
            if ($this->ordem !== 0 || $this->tipo_documento !== TipoDocumentoMotorista::CNH) {
                return null;
            }
            if (! $this->relationLoaded('anexoVerso')) {
                $this->setRelation('anexoVerso', $this->anexosDoEnvio()->where('ordem', 1)->first());
            }

            return $this->getRelation('anexoVerso')?->toArray();
        });
    }

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
        return ['tipo_documento' => TipoDocumentoMotorista::class, 'informacoes_complementares' => 'array', 'solicitacao_veiculo' => 'array', 'ordem' => 'integer', 'veiculo_id' => 'integer', 'motivo_reprovacao' => MotivoReprovacaoDocumento::class];
    }
}
