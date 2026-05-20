<?php

namespace App\Console\Commands;

use App\Events\TerminalOutput;
use App\Services\TerminalSessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Long-running tick-loop driving every active terminal session.
 *
 * Started by supervisord (`docker/supervisord.conf`):
 *   php artisan hermes:terminal-tick
 *
 * Responsibilities:
 *   - Boot sweep:    reap orphaned sessions (PID gone after panel restart)
 *   - Per-tick loop: call TerminalSessionService::tick() on each active id,
 *                    broadcast TerminalOutput for each produced chunk
 *   - Idle watchdog: SIGTERM sessions with no output for IDLE_TIMEOUT
 *   - Hard cap:      SIGTERM sessions running longer than HARD_CAP
 *   - Signal trap:   on SIGTERM, emit synthetic shutdown exit on every
 *                    active session, signal children, exit cleanly
 *
 * The command is intentionally chatty in the dedicated `terminal-tick`
 * log channel — supervisord output gets noisy fast across services.
 */
class TerminalTickLoop extends Command
{
    /** @var string */
    protected $signature = 'hermes:terminal-tick
        {--max-iterations=0 : Stop after N iterations (0 = run forever; useful for tests)}
        {--sleep=100000 : Microseconds to sleep between iterations when sessions are active (default 100ms)}
        {--idle-sleep=1000000 : Microseconds to sleep when no sessions are active (default 1s)}';

    /** @var string */
    protected $description = 'Long-running loop that streams terminal session output via Reverb broadcasts.';

    /** Idle watchdog: kill if no chunk for this many seconds. */
    public const IDLE_TIMEOUT = 600;     // 10 minutes

    /** Hard cap: kill if total runtime exceeds this many seconds. */
    public const HARD_CAP = 3600;        // 60 minutes

    /** Synthetic exit codes emitted by the watchdogs. */
    public const EXIT_IDLE = -1;

    public const EXIT_ORPHAN = -2;

    public const EXIT_SHUTDOWN = -3;

    /** Set true by SIGTERM handler so the next iteration drains and exits. */
    protected bool $shuttingDown = false;

    public function __construct(
        protected TerminalSessionService $sessions,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->registerSignalHandlers();
        $this->bootSweep();

        $activeSleep = max(1000, (int) $this->option('sleep'));
        $idleSleep = max($activeSleep, (int) $this->option('idle-sleep'));
        $maxIterations = (int) $this->option('max-iterations');
        $iteration = 0;

        $this->logInfo('terminal-tick loop started', [
            'sleep_us' => $activeSleep,
            'idle_sleep_us' => $idleSleep,
            'max_iterations' => $maxIterations,
        ]);

        while (! $this->shuttingDown) {
            $ticked = 0;

            try {
                $ticked = $this->tickAll();
            } catch (\Throwable $e) {
                $this->logError('tick iteration failed', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $iteration++;

            if ($maxIterations > 0 && $iteration >= $maxIterations) {
                $this->logInfo('terminal-tick reached max iterations', ['iteration' => $iteration]);
                break;
            }

            // Idle backoff: when nothing is running, sleep longer so the
            // CPU isn't waking 10 times per second for nothing. Active
            // sessions still get the snappy 100 ms cadence.
            usleep($ticked > 0 ? $activeSleep : $idleSleep);
        }

        if ($this->shuttingDown) {
            $this->drainOnShutdown();
        }

        $this->logInfo('terminal-tick loop exited cleanly', ['iterations' => $iteration]);

        return self::SUCCESS;
    }

    /**
     * Single iteration: tick every active session, broadcast produced
     * chunks, enforce idle / hard-cap watchdogs.
     *
     * Returns the number of sessions actually processed (non-stale,
     * non-finalised) so the outer loop can pick a sleep cadence.
     */
    protected function tickAll(): int
    {
        $ticked = 0;

        foreach ($this->sessions->listActiveSessionIds() as $sessionId) {
            $session = $this->sessions->getActive($sessionId);

            if (! $session) {
                // Tracker had a stale entry. Drop it.
                $this->sessions->untrackActive($sessionId);

                continue;
            }

            // Skip already-finalized sessions; cache TTL will collect them.
            if ($session->status === 'done') {
                $this->sessions->untrackActive($sessionId);

                continue;
            }

            $ticked++;

            $now = (int) now()->timestamp;

            if (($now - $session->startedAt) > self::HARD_CAP) {
                $chunk = $this->sessions->forceExit(
                    $sessionId,
                    self::EXIT_IDLE,    // shared with idle for client-side simplicity
                    "[runtime cap, killed after 60 min]\n",
                );
                if ($chunk) {
                    $this->dispatch($session->project, $sessionId, $chunk);
                }

                continue;
            }

            if (($now - $session->lastChunkAt) > self::IDLE_TIMEOUT) {
                $chunk = $this->sessions->forceExit(
                    $sessionId,
                    self::EXIT_IDLE,
                    "[idle timeout, killed after 10 min]\n",
                );
                if ($chunk) {
                    $this->dispatch($session->project, $sessionId, $chunk);
                }

                continue;
            }

            $produced = $this->sessions->tick($sessionId);

            foreach ($produced as $chunk) {
                $this->dispatch($session->project, $sessionId, $chunk);
            }
        }

        return $ticked;
    }

    /**
     * Reap orphans on boot: sessions whose PID has vanished since the
     * last panel run. Emits a synthetic exit so reconnecting clients
     * see the session ended cleanly.
     */
    protected function bootSweep(): void
    {
        foreach ($this->sessions->listActiveSessionIds() as $sessionId) {
            $session = $this->sessions->getActive($sessionId);

            if (! $session) {
                $this->sessions->untrackActive($sessionId);

                continue;
            }

            if ($session->status === 'done') {
                $this->sessions->untrackActive($sessionId);

                continue;
            }

            if ($this->pidAlive($session->pid)) {
                continue;
            }

            // PID is dead but we still have the Process handle locally
            // (e.g. session spawned earlier in this same PHP worker and
            // its echo command finished while we were starting up). Let
            // tick() drain the output buffer and finalise via the normal
            // exit chunk — don't reap as orphan.
            if ($this->sessions->hasProcessHandle($sessionId)) {
                continue;
            }

            $chunk = $this->sessions->reapOrphan($sessionId);

            if ($chunk) {
                $this->logInfo('reaped orphaned session', [
                    'session_id' => $sessionId,
                    'project' => $session->project,
                    'pid' => $session->pid,
                ]);
                $this->dispatch($session->project, $sessionId, $chunk);
            }
        }
    }

    /**
     * Drain on SIGTERM: emit synthetic shutdown exits on every still-live
     * session, signal children, return so handle() exits.
     */
    protected function drainOnShutdown(): void
    {
        $this->logInfo('terminal-tick draining for shutdown');

        foreach ($this->sessions->listActiveSessionIds() as $sessionId) {
            $session = $this->sessions->getActive($sessionId);

            if (! $session || $session->status === 'done') {
                $this->sessions->untrackActive($sessionId);

                continue;
            }

            $chunk = $this->sessions->forceExit(
                $sessionId,
                self::EXIT_SHUTDOWN,
                "[shutdown]\n",
            );

            if ($chunk) {
                $this->dispatch($session->project, $sessionId, $chunk);
            }
        }
    }

    /**
     * Dispatch one TerminalOutput broadcast event for a produced chunk.
     */
    protected function dispatch(string $project, string $sessionId, array $chunk): void
    {
        event(new TerminalOutput(
            $sessionId,
            $project,
            (int) ($chunk['ts'] ?? now()->timestamp),
            (string) ($chunk['type'] ?? 'stdout'),
            (string) ($chunk['data'] ?? ''),
            isset($chunk['exit_code']) ? (int) $chunk['exit_code'] : null,
        ));
    }

    /**
     * Wire pcntl signals if available. On Windows / unsupported builds,
     * gracefully degrade — no signal trap, but the loop still runs.
     */
    protected function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            $this->logInfo('pcntl not available; SIGTERM trap disabled');

            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signal): void {
            $this->logInfo('signal received, draining', ['signal' => $signal]);
            $this->shuttingDown = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    protected function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        // Best-effort fallback when posix is missing.
        return file_exists("/proc/{$pid}");
    }

    protected function logInfo(string $message, array $context = []): void
    {
        Log::channel('terminal-tick')->info($message, $context);
    }

    protected function logError(string $message, array $context = []): void
    {
        Log::channel('terminal-tick')->error($message, $context);
    }
}
