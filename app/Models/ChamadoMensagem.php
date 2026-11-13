<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChamadoMensagem extends Model
{
    protected $table = 'chamado_mensagens';

    protected $fillable = ['chamado_id', 'autor_tipo', 'gestor_id', 'autor_nome', 'conteudo', 'client_message_id', 'tipo_sistema'];

    public function anexos(): HasMany
    {
        return $this->hasMany(ChamadoMensagemAnexo::class, 'mensagem_id')->orderBy('ordem');
    }

    public function dados(): array
    {
        $this->loadMissing('anexos');

        return $this->only(['id', 'chamado_id', 'autor_tipo', 'gestor_id', 'autor_nome', 'conteudo', 'client_message_id', 'tipo_sistema'])
            + ['anexos' => $this->anexos->map(fn ($anexo) => $anexo->dados($this->chamado_id))->all(), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
