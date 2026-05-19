<?php

namespace App\Services;

use App\Support\TerminalSession;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Spawns and manages real-time terminal sessions.
 *
 * Stores ephemeral state in the cache (active metadata + output buffer)
 * and durable state too (per-project command history). Broadcasting,
 * idle/cap watchdogs, and orphan sweeps live in the tick-loop artisan
 * command (story v3.1-04). Controller wiring lands in story v3.1-05.
 *
 * Cache schema (see v3.1 design doc §5):
 *   hermes:term:active:{session_id}     5 min  metadata
 *   hermes:term:buffer:{session_id}     5 min  list of chunk arrays, 512 KB cap
 *   hermes:term:project:{project}       5 min  pointer to current session id
 *   hermes:term:history:{project}       30 day list of command arrays, 50 cap
 */
class TerminalSessionService
{
    /** Active session TTL in seconds. Refreshed on every chunk write. */
    public const ACTIVE_TTL = 300;        // 5 minutes

    /** Buffer TTL — kept aligned with ACTIVE_TTL so replay never outlives state. */
    public const BUFFER_TTL = 300;

    /** Project pointer TTL — same lifecycle as the active key. */
    public const PROJECT_POINTER_TTL = 300;

    /** History TTL — survives panel restarts and idle days. */
    public const HISTORY_TTL = 30 * 24 * 60 * 60;   // 30 days

    /** FIFO cap on the per-session output buffer. */
    public const BUFFER_BYTES_CAP = 512 * 1024;

    /** FIFO cap on the per-project history. */
    public const HISTORY_ITEM_CAP = 50;

    /** Long-running processes spawned via `start()` — explicit timeout. */
    public const SPAWN_TIMEOUT = 3600;            // 1 hour hard cap, watchdog enforced in tick-loop

    /** Process registry — `bash -c <cmd>`. */
    public const SHELL_BIN = 'bash';

    /**
     * Active Symfony Process objects keyed by session id. Lives only
     * inside the calling PHP process (e.g. the tick-loop). Cache keys
     * remain the persistent state across processes.
     *
     * @var array<string, Process>
     */
    protected array $processes = [];

    public function __construct(
        protected CacheRepository $cache,
        protected TerminalCommandPolicy $policy,
    ) {}

    /**
     * Spawn a new session. Returns the DTO so callers can immediately
     * reply with `session_id` to the client.
     */
    public function spawn(string $project, string $command, string $cwd, ?string $clientIp = null): TerminalSession
    {
        $command = trim($command);

        if ($command === '') {
            throw new \InvalidArgumentException('Cannot spawn empty command.');
        }

        if ($reason = $this->policy->reason($command)) {
            throw new \InvalidArgumentException($reason);
        }

        $process = $this->buildProcess($command, $cwd);
        $process->start();

        $now = $this->now();
        $sessionId = $this->generateSessionId($process);

        $session = new TerminalSession(
            sessionId: $sessionId,
            pid: (int) $process->getPid(),
            project: $project,
            command: $command,
            cwd: $cwd,
            startedAt: $now,
            lastChunkAt: $now,
            status: 'running',
        );

        $this->processes[$sessionId] = $process;

        $this->putActive($session);
        $this->setProjectPointer($project, $sessionId);
        $this->resetBuffer($sessionId);
        $this->appendChunk($sessionId, [
            'ts' => $now,
            'type' => 'meta',
            'data' => sprintf("[hermes] session %s spawned in %s\n", $sessionId, $cwd),
        ]);

        $this->audit($session, $clientIp);

        return $session;
    }

    /**
     * Stop a running session. Sends SIGTERM, waits 5 seconds, then
     * SIGKILL. Idempotent — calling on already-exited session is a no-op.
     *
     * Returns true when a process was actually signalled.
     */
    public function stop(string $sessionId): bool
    {
        $session = $this->getActive($sessionId);

        if (! $session || $session->status === 'done') {
            return false;
        }

        $this->putActive($session->withStatus('exiting'));

        $process = $this->processes[$sessionId] ?? null;

        if ($process instanceof Process && $process->isRunning()) {
            $process->signal(15);    // SIGTERM
            $process->stop(5, 9);    // wait 5s, then SIGKILL (signal 9)

            return true;
        }

        // Process is owned by a different PHP worker. Best effort: signal by PID.
        if ($session->pid > 0 && function_exists('posix_kill')) {
            @posix_kill($session->pid, 15);

            return true;
        }

        return false;
    }

    /**
     * Tick a single session: read whatever output is available, append
     * to buffer, refresh TTL. Called by the tick-loop ~10x/s. Cheap when
     * nothing changed.
     *
     * Detects process exit and finalises the buffer with an `exit` chunk.
     */
    public function tick(string $sessionId): void
    {
        $session = $this->getActive($sessionId);

        if (! $session || $session->status === 'done') {
            return;
        }

        $process = $this->processes[$sessionId] ?? null;

        if (! $process instanceof Process) {
            // Process belongs to another worker — we can't read its pipes.
            // Still touch the active key so its TTL doesn't expire.
            $this->putActive($session);

            return;
        }

        $stdout = $process->getIncrementalOutput();
        $stderr = $process->getIncrementalErrorOutput();
        $now = $this->now();
        $touched = false;

        if ($stdout !== '') {
            $this->appendChunk($sessionId, ['ts' => $now, 'type' => 'stdout', 'data' => $stdout]);
            $touched = true;
        }

        if ($stderr !== '') {
            $this->appendChunk($sessionId, ['ts' => $now, 'type' => 'stderr', 'data' => $stderr]);
            $touched = true;
        }

        if ($touched) {
            $session = $session->withLastChunkAt($now);
            $this->putActive($session);
        } else {
            // Refresh TTL on the active key even when there's nothing to write.
            $this->putActive($session);
        }

        if (! $process->isRunning()) {
            $exitCode = (int) ($process->getExitCode() ?? 0);
            $this->appendChunk($sessionId, [
                'ts' => $this->now(),
                'type' => 'exit',
                'data' => sprintf("[exit %d]\n", $exitCode),
                'exit_code' => $exitCode,
            ]);

            $this->putActive($session->withStatus('done', $exitCode));
            $this->pushHistory($session->project, $session->command, $exitCode);
            unset($this->processes[$sessionId]);
        }
    }

    /**
     * Replay the full state for a session: metadata + buffered chunks.
     * Used when the browser reconnects after a refresh.
     */
    public function replay(string $sessionId): array
    {
        $session = $this->getActive($sessionId);

        $chunks = $this->cache->get($this->bufferKey($sessionId), []);
        usort($chunks, fn ($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));

        return [
            'session' => $session?->toArray(),
            'chunks' => $chunks,
            'status' => $session?->status ?? 'idle',
        ];
    }

    /**
     * Drop active + buffer keys. Leaves history untouched.
     */
    public function destroy(string $sessionId): void
    {
        $session = $this->getActive($sessionId);

        $this->cache->forget($this->activeKey($sessionId));
        $this->cache->forget($this->bufferKey($sessionId));

        if ($session && $this->getProjectPointer($session->project) === $sessionId) {
            $this->cache->forget($this->projectKey($session->project));
        }

        unset($this->processes[$sessionId]);
    }

    /**
     * Per-project command history (last 50, newest first).
     *
     * @return array<int, array{ts:int, command:string, exit_code:?int}>
     */
    public function history(string $project): array
    {
        return (array) $this->cache->get($this->historyKey($project), []);
    }

    /**
     * Append a command to per-project history. FIFO at HISTORY_ITEM_CAP.
     */
    public function pushHistory(string $project, string $command, ?int $exitCode = null): void
    {
        $history = $this->history($project);

        array_unshift($history, [
            'ts' => $this->now(),
            'command' => $command,
            'exit_code' => $exitCode,
        ]);

        if (count($history) > self::HISTORY_ITEM_CAP) {
            $history = array_slice($history, 0, self::HISTORY_ITEM_CAP);
        }

        $this->cache->put($this->historyKey($project), $history, self::HISTORY_TTL);
    }

    /**
     * Clear per-project history. Used by the `DELETE /history` endpoint.
     */
    public function clearHistory(string $project): void
    {
        $this->cache->forget($this->historyKey($project));
    }

    /**
     * Look up the current session id for a project, if any.
     */
    public function activeSessionId(string $project): ?string
    {
        $id = $this->getProjectPointer($project);

        if (! $id) {
            return null;
        }

        // Stale pointer protection — pointer outliving its session.
        return $this->cache->has($this->activeKey($id)) ? $id : null;
    }

    /**
     * Build the Symfony Process. Wrapped here so tests can override.
     */
    protected function buildProcess(string $command, string $cwd): Process
    {
        $process = new Process([self::SHELL_BIN, '-c', $command], $cwd);
        $process->setTimeout(self::SPAWN_TIMEOUT);
        $process->setIdleTimeout(null);     // we own idle handling in tick-loop
        $process->setEnv([
            'TERM' => 'xterm-256color',     // xterm.js handles ANSI passthrough
            'NO_COLOR' => null,             // unset — let tools color
        ]);

        return $process;
    }

    /**
     * Append a chunk to the buffer with FIFO trim at BUFFER_BYTES_CAP.
     */
    protected function appendChunk(string $sessionId, array $chunk): void
    {
        $key = $this->bufferKey($sessionId);
        $buffer = (array) $this->cache->get($key, []);
        $buffer[] = $chunk;

        $totalBytes = 0;
        foreach ($buffer as $entry) {
            $totalBytes += strlen((string) ($entry['data'] ?? ''));
        }

        // FIFO drop oldest while we're over budget.
        while ($totalBytes > self::BUFFER_BYTES_CAP && count($buffer) > 1) {
            $dropped = array_shift($buffer);
            $totalBytes -= strlen((string) ($dropped['data'] ?? ''));
        }

        $this->cache->put($key, $buffer, self::BUFFER_TTL);
    }

    protected function resetBuffer(string $sessionId): void
    {
        $this->cache->put($this->bufferKey($sessionId), [], self::BUFFER_TTL);
    }

    protected function putActive(TerminalSession $session): void
    {
        $this->cache->put(
            $this->activeKey($session->sessionId),
            $session->toArray(),
            self::ACTIVE_TTL,
        );
    }

    protected function getActive(string $sessionId): ?TerminalSession
    {
        $data = $this->cache->get($this->activeKey($sessionId));

        return $data ? TerminalSession::fromArray($data) : null;
    }

    protected function setProjectPointer(string $project, string $sessionId): void
    {
        $this->cache->put($this->projectKey($project), $sessionId, self::PROJECT_POINTER_TTL);
    }

    protected function getProjectPointer(string $project): ?string
    {
        $value = $this->cache->get($this->projectKey($project));

        return is_string($value) ? $value : null;
    }

    /**
     * Audit log on every spawn. Dedicated channel for grep-friendly
     * forensics without polluting the main laravel.log.
     */
    protected function audit(TerminalSession $session, ?string $ip): void
    {
        Log::channel('terminal-audit')->info('terminal_command_run', [
            'session_id' => $session->sessionId,
            'project' => $session->project,
            'command' => $session->command,
            'cwd' => $session->cwd,
            'pid' => $session->pid,
            'ip' => $ip,
        ]);
    }

    /**
     * `{pid}-{6 random chars}` so forensics can correlate with `ps`.
     */
    protected function generateSessionId(Process $process): string
    {
        return $process->getPid().'-'.Str::random(6);
    }

    protected function now(): int
    {
        return (int) now()->timestamp;
    }

    protected function activeKey(string $sessionId): string
    {
        return "hermes:term:active:{$sessionId}";
    }

    protected function bufferKey(string $sessionId): string
    {
        return "hermes:term:buffer:{$sessionId}";
    }

    protected function projectKey(string $project): string
    {
        return "hermes:term:project:{$project}";
    }

    protected function historyKey(string $project): string
    {
        return "hermes:term:history:{$project}";
    }
}
