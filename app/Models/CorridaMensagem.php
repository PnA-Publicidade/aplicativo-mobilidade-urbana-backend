<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorridaMensagem extends Model
{
    protected $table = 'corrida_mensagens';

    protected $fillable = ['corrida_id', 'autor_tipo', 'autor_nome', 'conteudo', 'client_message_id'];

    public function corrida(): BelongsTo
    {
        return $this->belongsTo(Corrida::class);
    }

    public function dados(): array
    {
        return $this->only(['id', 'corrida_id', 'autor_tipo', 'autor_nome', 'conteudo', 'client_message_id'])
            + ['created_at' => $this->created_at?->toISOString()];
    }
}
