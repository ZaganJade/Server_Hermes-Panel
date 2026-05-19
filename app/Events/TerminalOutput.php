<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time terminal output broadcast.
 *
 * Dispatched by the `hermes:terminal-tick` artisan command (story
 * v3.1-04) every time a session produces stdout/stderr or finalises
 * with an exit chunk. Channel auth lives in `routes/channels.php`
 * (story v3.1-05); this event simply describes the payload shape and
 * the private channel name.
 *
 * Wire format on the client (Echo/Pusher.js):
 *
 *   Echo.private(`terminal.${project}`).listen('.terminal.output', e => …)
 */
class TerminalOutput implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $project,
        public int $ts,
        public string $type,            // 'stdout' | 'stderr' | 'meta' | 'exit'
        public string $data,
        public ?int $exitCode = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("terminal.{$this->project}")];
    }

    public function broadcastAs(): string
    {
        return 'terminal.output';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = [
            'session_id' => $this->sessionId,
            'ts' => $this->ts,
            'type' => $this->type,
            'data' => $this->data,
        ];

        if ($this->exitCode !== null) {
            $payload['exit_code'] = $this->exitCode;
        }

        return $payload;
    }
}
