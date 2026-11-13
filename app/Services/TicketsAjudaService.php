<?php

namespace App\Services;

use App\Enums\StatusTicket;
use App\Events\TicketAtualizado;
use App\Events\TicketMensagemEnviada;
use App\Events\TicketTransferredEvent;
use App\Models\ChamadoAjuda;
use App\Models\ChamadoMensagem;
use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\TicketTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketsAjudaService
{
    public function __construct(private FilaTicketsService $fila, private TicketAnexosService $anexos) {}

    /** o chamado é do motorista ou do passageiro que o abre (ou para quem a gestão o abre) */
    public function validarContexto(Motorista|Passageiro $solicitante, array $dados): string
    {
        $tipo = $solicitante->perfilDoApp();
        if (! empty($dados['corrida_id'])) {
            abort_unless(Corrida::whereKey($dados['corrida_id'])->where($tipo.'_id', $solicitante->id)->exists(), 404, 'Corrida não encontrada para este solicitante.');
        }

        return $tipo;
    }

    public function criar(Motorista|Passageiro $solicitante, array $dados, ?Gestor $gestor = null): ChamadoAjuda
    {
        $tipo = $this->validarContexto($solicitante, $dados);

        return DB::transaction(function () use ($solicitante, $dados, $tipo, $gestor) {
            $ticket = ChamadoAjuda::create([
                'solicitante_id' => $solicitante->id, 'solicitante_tipo' => $tipo, 'corrida_id' => $dados['corrida_id'] ?? null,
                'motivo' => $dados['motivo'], 'descricao' => $dados['descricao'], 'status' => 'open',
            ]);
            $this->registrar($ticket, $gestor ? 'gestor' : 'usuario', $dados['descricao'], $gestor ?? $solicitante);
            event(new TicketAtualizado($ticket->id));

            return $ticket;
        });
    }

    private function registrar(ChamadoAjuda $ticket, string $tipo, string $texto, Motorista|Passageiro|Gestor|null $autor = null, ?string $uuid = null, array $imagens = [], array &$gravados = []): ChamadoMensagem
    {
        $message = $ticket->mensagens()->create([
            'autor_tipo' => $tipo,
            'gestor_id' => $autor instanceof Gestor ? $autor->id : null, 'autor_nome' => $autor?->name ?? 'Sistema',
            'conteudo' => $texto, 'client_message_id' => $uuid,
        ]);
        $this->anexos->salvar($message, $imagens, $gravados);
        $ticket->ultima_mensagem_em = $message->created_at;
        $ticket->save();
        event(new TicketMensagemEnviada($ticket->id, $message->dados()));

        return $message;
    }

    private function acesso(ChamadoAjuda $ticket, Motorista|Passageiro|Gestor $autor): void
    {
        // Reavalia após lockForUpdate: uma transferência pode ter ocorrido depois do controller.
        abort_unless($autor instanceof Gestor ? $autor->podeAcessarTicket($ticket) : $this->ehDoSolicitante($ticket, $autor), 404);
    }

    private function atribuir(ChamadoAjuda $ticket, ?Gestor $destino, Gestor $autor, string $tipo, ?string $nota = null): void
    {
        if ($ticket->gestor_responsavel_id === $destino?->id) {
            return;
        }
        $anteriorId = $ticket->gestor_responsavel_id;
        $anterior = $anteriorId ? Gestor::withTrashed()->find($anteriorId) : null;
        $ticket->gestor_responsavel_id = $destino?->id;
        $ticket->forceFill(['fila_posicao' => null, 'fila_entrada_em' => null]);
        $ticket->save();
        if ($destino) {
            $this->fila->entrar($ticket);
        }
        $transferencia = TicketTransfer::create([
            'ticket_id' => $ticket->id, 'transferred_by_user_id' => $autor->id,
            'from_user_id' => $anteriorId, 'to_user_id' => $destino?->id,
            'transferred_by_name' => $autor->name, 'from_user_name' => $anterior?->name,
            'to_user_name' => $destino?->name, 'type' => $tipo, 'note' => $nota, 'created_at' => now(),
        ]);
        $texto = match (true) {
            $destino === null => $autor->name.' devolveu o atendimento à fila geral.',
            $destino->id === $autor->id => $autor->name.' assumiu o atendimento'.($anterior ? ', antes com '.$anterior->name : '').'.',
            $anterior !== null => $autor->name.' transferiu o atendimento de '.$anterior->name.' para '.$destino->name.'.',
            default => $autor->name.' atribuiu o atendimento para '.$destino->name.'.',
        };
        $this->registrar($ticket, 'sistema', $texto);
        event(new TicketTransferredEvent($ticket->id, $transferencia->id, $anteriorId, $destino?->id));
    }

    /** o perfil logado é quem abriu o chamado (motorista 1 e passageiro 1 são pessoas diferentes) */
    public function ehDoSolicitante(ChamadoAjuda $ticket, Motorista|Passageiro $perfil): bool
    {
        return $ticket->solicitante_tipo === $perfil->perfilDoApp() && (int) $ticket->solicitante_id === (int) $perfil->id;
    }

    public function transferir(ChamadoAjuda $registro, Gestor $autor, int $destinoId, ?string $nota): ChamadoAjuda
    {
        return $this->fila->executar($registro, [$destinoId], function (ChamadoAjuda $ticket) use ($autor, $destinoId, $nota) {
            $this->acesso($ticket, $autor);
            abort_if(in_array($ticket->status, ['resolved', 'closed'], true), 409, 'Atendimento finalizado não pode ser transferido.');
            $destino = Gestor::whereKey($destinoId)->lockForUpdate()->first();
            if (! $destino || ! $destino->pode('ajuda.gerenciar')) {
                throw ValidationException::withMessages(['to_user_id' => 'Selecione um operador ativo com acesso à Central de ajuda.']);
            }
            if ($ticket->gestor_responsavel_id === $destino->id) {
                throw ValidationException::withMessages(['to_user_id' => 'Este operador já é responsável pelo atendimento.']);
            }
            $tipo = $ticket->gestor_responsavel_id === null ? 'queue_distribution' : 'manual_transfer';
            $this->atribuir($ticket, $destino, $autor, $tipo, $nota);
            event(new TicketAtualizado($ticket->id));

            return $ticket;
        });
    }

    public function enviar(ChamadoAjuda $registro, Motorista|Passageiro|Gestor $autor, string $texto, string $uuid, array $imagens = []): ChamadoMensagem
    {
        $preparadas = $this->anexos->preparar($imagens);
        $gravados = [];
        try {
            return $this->fila->executar($registro, $autor instanceof Gestor ? [$autor->id] : [], function (ChamadoAjuda $ticket) use ($autor, $texto, $uuid, $preparadas, &$gravados) {
                $this->acesso($ticket, $autor);
                $tipo = $autor instanceof Gestor ? 'gestor' : 'usuario';
                $anterior = $ticket->mensagens()->where('client_message_id', $uuid)->first();
                if ($anterior) {
                    abort_unless($anterior->autor_tipo === $tipo && ($tipo === 'gestor' ? $anterior->gestor_id === $autor->id : $anterior->gestor_id === null) && $anterior->conteudo === $texto && $this->anexos->corresponde($anterior, $preparadas), 409, 'Identificador de mensagem já utilizado.');

                    return $anterior;
                }
                abort_if(in_array($ticket->status, ['resolved', 'closed'], true), 409, 'Este atendimento foi finalizado. Abra um novo ticket para continuar.');
                if ($autor instanceof Gestor) {
                    abort_if($ticket->gestor_responsavel_id && $ticket->gestor_responsavel_id !== $autor->id, 409, 'Assuma o atendimento antes de enviar uma mensagem.');
                    abort_unless($ticket->gestor_responsavel_id === $autor->id && $ticket->status === 'in_progress',
                        409, 'Inicie o atendimento antes de responder este ticket.');
                }
                $message = $this->registrar($ticket, $tipo, $texto, $autor, $uuid, $preparadas, $gravados);
                event(new TicketAtualizado($ticket->id));

                return $message;
            });
        } finally {
            $this->anexos->limparNaoPersistidos($gravados);
        }
    }

    public function assumir(ChamadoAjuda $registro, Gestor $gestor): ChamadoAjuda
    {
        return $this->fila->executar($registro, [$gestor->id], function (ChamadoAjuda $ticket) use ($gestor) {
            $this->acesso($ticket, $gestor);
            abort_if(in_array($ticket->status, ['resolved', 'closed'], true), 409, 'Atendimento finalizado não pode ser iniciado novamente.');
            if ($ticket->gestor_responsavel_id !== $gestor->id) {
                $tipo = $ticket->gestor_responsavel_id ? 'takeover_by_manager' : 'queue_distribution';
                $this->atribuir($ticket, $gestor, $gestor, $tipo);
                event(new TicketAtualizado($ticket->id));
            }
            if (! in_array($ticket->status, ['waiting', 'in_progress'], true)) {
                $this->fila->entrar($ticket);
            }

            $this->fila->iniciar($ticket);
            event(new TicketAtualizado($ticket->id));

            return $ticket;
        });
    }

    public function status(ChamadoAjuda $registro, Gestor $gestor, StatusTicket $status): ChamadoAjuda
    {
        return $this->fila->executar($registro, [$gestor->id], function (ChamadoAjuda $ticket) use ($gestor, $status) {
            $this->acesso($ticket, $gestor);
            abort_if($status === StatusTicket::Waiting, 422, 'A posição na fila é definida automaticamente.');
            abort_if($ticket->status === 'waiting' && ! in_array($status, [StatusTicket::Open, StatusTicket::InProgress], true), 409, 'Aguarde sua vez na fila antes de alterar o atendimento.');
            if ($ticket->status === $status->value) {
                return $ticket;
            }
            abort_if(in_array($ticket->status, ['resolved', 'closed'], true),
                409, 'Este atendimento foi finalizado. Abra um novo ticket para continuar.');
            if ($status === StatusTicket::InProgress) {
                abort_if($ticket->gestor_responsavel_id && $ticket->gestor_responsavel_id !== $gestor->id, 409, 'Assuma o atendimento antes de iniciá-lo.');
                if (! $ticket->gestor_responsavel_id) {
                    $this->atribuir($ticket, $gestor, $gestor, 'queue_distribution');
                } elseif ($ticket->status !== 'waiting') {
                    $this->fila->entrar($ticket);
                }
                $this->fila->iniciar($ticket);
            } else {
                $ticket->status = $status->value;
            }
            $ticket->resolvido_em = $status === StatusTicket::Resolved ? now() : null;
            if ($status === StatusTicket::Open) {
                $this->atribuir($ticket, null, $gestor, 'queue_return');
            } elseif (! $ticket->gestor_responsavel_id) {
                $this->atribuir($ticket, $gestor, $gestor, 'queue_distribution');
                if ($status !== StatusTicket::InProgress) {
                    $ticket->status = $status->value;
                }
            }
            $this->registrar($ticket, 'sistema', $gestor->name.' alterou o status para '.$status->nome().'.');
            event(new TicketAtualizado($ticket->id));

            return $ticket;
        });
    }
}
