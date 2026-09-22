<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MotoristaMoveu implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $corridaId,
        public float $latitude,
        public float $longitude,
        public string $vistoEm
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('corrida.'.$this->corridaId)];
    }

    public function broadcastAs(): string
    {
        return 'motorista.moveu';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'visto_em' => $this->vistoEm,
        ];
    }
}
