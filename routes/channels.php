<?php

use App\Models\ChamadoAjuda;
use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\SalaParticipante;
use App\Models\SaldoMotorista;
use App\Models\User;
use App\Services\ChatCorridaService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('corrida.{corridaId}', function (User $user, int $corridaId) {
    $corrida = Corrida::find($corridaId);

    if ($corrida === null) {
        return false;
    }

    $passageiroId = Passageiro::where('user_id', $user->id)->value('id');

    if ($passageiroId !== null && $corrida->passageiro_id === (int) $passageiroId) {
        return true;
    }

    $motoristaId = Motorista::where('user_id', $user->id)->value('id');

    return $motoristaId !== null && $corrida->motorista_id === (int) $motoristaId;
});

Broadcast::channel('corridas-disponiveis', function (User $user) {
    return Motorista::where('user_id', $user->id)->where('status', 'ativo')->exists();
});

Broadcast::channel('ajuda.ticket.{ticketId}.cliente', function (User $user, int $ticketId) {
    return ChamadoAjuda::whereKey($ticketId)->where('user_id', $user->id)->exists();
}, ['guards' => ['jwt']]);

Broadcast::channel('ajuda.ticket.{ticketId}.suporte', function (Gestor $gestor, int $ticketId) {
    $ticket = ChamadoAjuda::find($ticketId);

    return $ticket && $gestor->podeAcessarTicket($ticket);
}, ['guards' => ['gestores']]);

Broadcast::channel('documentos.fila', fn (Gestor $gestor) => $gestor->pode('documentos.consultar'), ['guards' => ['gestores']]);

Broadcast::channel('ajuda.suporte', fn (Gestor $gestor) => $gestor->visualizaTodosTickets(), ['guards' => ['gestores']]);

Broadcast::channel('ajuda.entrada', fn (Gestor $gestor) => $gestor->visualizaEntradaTickets(), ['guards' => ['gestores']]);

Broadcast::channel('ajuda.operador.{operadorId}', fn (Gestor $gestor, int $operadorId) => $gestor->id === $operadorId && $gestor->pode('ajuda.gerenciar'), ['guards' => ['gestores']]);

Broadcast::channel('corrida.{corridaId}.chat', function (User $usuario, int $corridaId) {
    $corrida = Corrida::find($corridaId);

    return $corrida && app(ChatCorridaService::class)->perfilParticipante($corrida, $usuario) !== null;
}, ['guards' => ['jwt']]);

Broadcast::channel('corrida.{corridaId}.chat.suporte', function (Gestor $gestor, int $corridaId) {
    return app(ChatCorridaService::class)->gestorPodeConsultar($gestor, $corridaId);
}, ['guards' => ['gestores']]);

Broadcast::channel('corrida.{corridaId}.chat.financeiro', function (Gestor $gestor, int $corridaId) {
    return $gestor->pode('financeiro.consultar') && SaldoMotorista::where('corrida_id', $corridaId)->exists();
}, ['guards' => ['gestores']]);

Broadcast::channel('sala-compartilhada.{salaId}', function (User $user, int $salaId) {
    $passageiroId = Passageiro::where('user_id', $user->id)->value('id');

    return $passageiroId !== null && SalaParticipante::where('sala_id', $salaId)
        ->where('passageiro_id', $passageiroId)
        ->where('status', '!=', SalaParticipante::SAIU)
        ->exists();
}, ['guards' => ['jwt']]);
