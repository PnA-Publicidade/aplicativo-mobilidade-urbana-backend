<?php

use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\User;
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
    return Motorista::where('user_id', $user->id)->exists();
});
