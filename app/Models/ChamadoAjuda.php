<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $solicitante_tipo motorista ou passageiro
 * @property int $solicitante_id id em motoristas ou passageiros, conforme o tipo
 */
class ChamadoAjuda extends Model
{
    protected $table = 'chamados_ajuda';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['solicitante_id', 'corrida_id', 'motivo', 'descricao', 'status', 'solicitante_tipo', 'gestor_responsavel_id', 'ultima_mensagem_em', 'resolvido_em', 'fechado_em', 'fila_entrada_em', 'fila_posicao'];

    protected function casts(): array
    {
        return ['fila_entrada_em' => 'datetime', 'fila_posicao' => 'integer', 'ultima_mensagem_em' => 'datetime', 'resolvido_em' => 'datetime', 'fechado_em' => 'datetime'];
    }

    public function scopeComNaoLidas(Builder $query, int $gestorId): void
    {
        $query->withCount(['mensagens as mensagens_nao_lidas' => fn ($mensagens) => $mensagens
            ->where('autor_tipo', 'usuario')
            ->whereRaw('chamado_mensagens.id > COALESCE((SELECT ultima_mensagem_id FROM ticket_leituras WHERE ticket_id = chamados_ajuda.id AND gestor_id = ?), 0)', [$gestorId])])
            ->withMax(['mensagens as ultima_mensagem_cliente_id' => fn ($mensagens) => $mensagens->where('autor_tipo', 'usuario')], 'id');
    }

    /**
     * O motorista ou o passageiro que abriu o chamado (solicitante_tipo diz qual).
     *
     * @return MorphTo<Model, $this>
     */
    public function solicitante(): MorphTo
    {
        return $this->morphTo('solicitante', 'solicitante_tipo', 'solicitante_id')->withTrashed();
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(Gestor::class, 'gestor_responsavel_id')->withTrashed();
    }

    public function corrida(): BelongsTo
    {
        return $this->belongsTo(Corrida::class);
    }

    public function transferencias(): HasMany
    {
        return $this->hasMany(TicketTransfer::class, 'ticket_id');
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(ChamadoMensagem::class, 'chamado_id');
    }
}
