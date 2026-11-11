<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Avisa o painel da gestão que a fila de aprovação de documentos mudou. */
class FilaDocumentosAlterada implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public const ENVIADO = 'enviado';

    public const STATUS = 'status';

    public const REMOVIDO = 'removido';

    public function __construct(public int $motoristaId, public string $motivo) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('documentos.fila')];
    }

    public function broadcastAs(): string
    {
        return 'fila.alterada';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /** Sem dados do documento: o painel só recarrega a fila pela API, que checa a permissão. */
    public function broadcastWith(): array
    {
        return ['motorista_id' => $this->motoristaId, 'motivo' => $this->motivo];
    }
}
