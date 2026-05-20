<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time monitoring snapshot broadcast.
 *
 * One event per tick (5 s default). Payload mirrors the snapshot shape
 * plus inline alerts so the client can react in a single round-trip:
 *
 *   { ts, entries, alerts, errors }
 *
 * Channel: private-monitoring.host
 * Event:   monitoring.snapshot
 */
class MonitoringSnapshot implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public array $payload) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('monitoring.host')];
    }

    public function broadcastAs(): string
    {
        return 'monitoring.snapshot';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
