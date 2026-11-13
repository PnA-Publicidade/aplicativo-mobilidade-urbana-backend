<?php

use App\Models\ChamadoAjuda;
use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\SalaParticipante;
use App\Models\SaldoMotorista;
use App\Services\ChatCorridaService;
use App\Services\TicketsAjudaService;
use Illuminate\Support\Facades\Broadcast;

// os apps entram com o token do motorista ou o do passageiro
$apps = ['guards' => ['motoristas', 'passageiros']];

Broadcast::channel('corrida.{corridaId}', function (Motorista|Passageiro $perfil, int $corridaId) {
    $corrida = Corrida::find($corridaId);

    if ($corrida === null) {
        return false;
    }

    return $perfil instanceof Passageiro
        ? (int) $corrida->passageiro_id === (int) $perfil->id
        : (int) $corrida->motorista_id === (int) $perfil->id;
}, $apps);

Broadcast::channel('corridas-disponiveis', function (Motorista|Passageiro $perfil) {
    return $perfil instanceof Motorista && $perfil->status === 'ativo';
}, $apps);

Broadcast::channel('ajuda.ticket.{ticketId}.cliente', function (Motorista|Passageiro $perfil, int $ticketId) {
    $ticket = ChamadoAjuda::find($ticketId);

    return $ticket !== null && app(TicketsAjudaService::class)->ehDoSolicitante($ticket, $perfil);
}, $apps);

Broadcast::channel('ajuda.ticket.{ticketId}.suporte', function (Gestor $gestor, int $ticketId) {
    $ticket = ChamadoAjuda::find($ticketId);

    return $ticket && $gestor->podeAcessarTicket($ticket);
}, ['guards' => ['gestores']]);

Broadcast::channel('documentos.fila', fn (Gestor $gestor) => $gestor->pode('documentos.consultar'), ['guards' => ['gestores']]);

Broadcast::channel('ajuda.suporte', fn (Gestor $gestor) => $gestor->visualizaTodosTickets(), ['guards' => ['gestores']]);

Broadcast::channel('ajuda.entrada', fn (Gestor $gestor) => $gestor->visualizaEntradaTickets(), ['guards' => ['gestores']]);

Broadcast::channel('ajuda.operador.{operadorId}', fn (Gestor $gestor, int $operadorId) => $gestor->id === $operadorId && $gestor->pode('ajuda.gerenciar'), ['guards' => ['gestores']]);

Broadcast::channel('corrida.{corridaId}.chat', function (Motorista|Passageiro $perfil, int $corridaId) {
    $corrida = Corrida::find($corridaId);

    return $corrida && app(ChatCorridaService::class)->perfilParticipante($corrida, $perfil) !== null;
}, $apps);

Broadcast::channel('corrida.{corridaId}.chat.suporte', function (Gestor $gestor, int $corridaId) {
    return app(ChatCorridaService::class)->gestorPodeConsultar($gestor, $corridaId);
}, ['guards' => ['gestores']]);

Broadcast::channel('corrida.{corridaId}.chat.financeiro', function (Gestor $gestor, int $corridaId) {
    return $gestor->pode('financeiro.consultar') && SaldoMotorista::where('corrida_id', $corridaId)->exists();
}, ['guards' => ['gestores']]);

Broadcast::channel('sala-compartilhada.{salaId}', function (Motorista|Passageiro $perfil, int $salaId) {
    return $perfil instanceof Passageiro && SalaParticipante::where('sala_id', $salaId)
        ->where('passageiro_id', $perfil->id)
        ->where('status', '!=', SalaParticipante::SAIU)
        ->exists();
}, $apps);
