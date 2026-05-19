<?php

namespace Tests\Feature\Console;

use App\Console\Commands\TerminalTickLoop;
use App\Events\TerminalOutput;
use App\Services\TerminalSessionService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class TerminalTickLoopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Quiet the dedicated tick log channel during tests — redirect to
        // a temp file rather than the real storage/logs path so we don't
        // leave artifacts behind.
        config([
            'logging.channels.terminal-tick' => [
                'driver' => 'single',
                'path' => sys_get_temp_dir().'/hermes-terminal-tick-test.log',
                'level' => 'debug',
            ],
        ]);

        Event::fake([TerminalOutput::class]);
    }

    public function test_command_finishes_after_max_iterations_when_no_active_sessions(): void
    {
        $this->artisan('hermes:terminal-tick', ['--max-iterations' => 2, '--sleep' => 1000])
            ->assertExitCode(0);

        Event::assertNothingDispatched();
    }

    public function test_dispatches_terminal_output_when_session_produces_chunk(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        /** @var TerminalSessionService $sessions */
        $sessions = app(TerminalSessionService::class);

        $session = $sessions->spawn(
            project: 'desakta',
            command: 'echo hello-tick-loop',
            cwd: sys_get_temp_dir(),
        );

        // Spawn returns immediately. Wait for the underlying process to
        // produce output AND exit so the very next tick captures both
        // stdout and the synthetic exit chunk in one go.
        $this->waitForExit($sessions, $session->sessionId);

        // Sanity check: the same singleton must have the live process
        // handle so when the artisan command resolves the service it
        // sees the running pipes.
        $this->assertSame($sessions, app(TerminalSessionService::class), 'TerminalSessionService must be a singleton.');

        $this->artisan('hermes:terminal-tick', ['--max-iterations' => 2, '--sleep' => 1000])
            ->assertExitCode(0);

        Event::assertDispatched(TerminalOutput::class, function (TerminalOutput $event) use ($session) {
            return $event->sessionId === $session->sessionId
                && $event->project === 'desakta'
                && $event->type === 'stdout'
                && str_contains($event->data, 'hello-tick-loop');
        });

        Event::assertDispatched(TerminalOutput::class, function (TerminalOutput $event) use ($session) {
            return $event->sessionId === $session->sessionId
                && $event->type === 'exit'
                && $event->exitCode === 0;
        });
    }

    public function test_force_exits_idle_session(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        /** @var TerminalSessionService $sessions */
        $sessions = app(TerminalSessionService::class);

        $session = $sessions->spawn(
            project: 'desakta',
            command: 'sleep 5',
            cwd: sys_get_temp_dir(),
        );

        // Backdate last_chunk_at past the idle threshold.
        $cache = app(Repository::class);
        $key = "hermes:term:active:{$session->sessionId}";
        $data = $cache->get($key);
        $data['last_chunk_at'] = (int) now()->subSeconds(TerminalTickLoop::IDLE_TIMEOUT + 5)->timestamp;
        $cache->put($key, $data, 300);

        $this->artisan('hermes:terminal-tick', ['--max-iterations' => 1, '--sleep' => 1000])
            ->assertExitCode(0);

        Event::assertDispatched(TerminalOutput::class, function (TerminalOutput $event) use ($session) {
            return $event->sessionId === $session->sessionId
                && $event->type === 'exit'
                && $event->exitCode === TerminalTickLoop::EXIT_IDLE
                && str_contains($event->data, 'idle timeout');
        });
    }

    public function test_force_exits_session_past_hard_cap(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        /** @var TerminalSessionService $sessions */
        $sessions = app(TerminalSessionService::class);

        $session = $sessions->spawn(
            project: 'desakta',
            command: 'sleep 5',
            cwd: sys_get_temp_dir(),
        );

        $cache = app(Repository::class);
        $key = "hermes:term:active:{$session->sessionId}";
        $data = $cache->get($key);
        $data['started_at'] = (int) now()->subSeconds(TerminalTickLoop::HARD_CAP + 5)->timestamp;
        $cache->put($key, $data, 300);

        $this->artisan('hermes:terminal-tick', ['--max-iterations' => 1, '--sleep' => 1000])
            ->assertExitCode(0);

        Event::assertDispatched(TerminalOutput::class, function (TerminalOutput $event) use ($session) {
            return $event->sessionId === $session->sessionId
                && $event->type === 'exit'
                && str_contains($event->data, 'runtime cap');
        });
    }

    public function test_boot_sweep_reaps_orphaned_session(): void
    {
        $cache = app(Repository::class);

        // Inject a fake active session whose PID definitely doesn't exist.
        $fakeId = '99999999-orphan';
        $cache->put('hermes:term:active:'.$fakeId, [
            'session_id' => $fakeId,
            'pid' => 99_999_999,         // unlikely-to-exist PID
            'project' => 'desakta',
            'command' => 'echo orphan',
            'cwd' => sys_get_temp_dir(),
            'started_at' => (int) now()->timestamp,
            'last_chunk_at' => (int) now()->timestamp,
            'status' => 'running',
        ], 300);

        // Track it manually so the boot sweep finds it.
        /** @var TerminalSessionService $sessions */
        $sessions = app(TerminalSessionService::class);
        $sessions->trackActive($fakeId);

        $this->artisan('hermes:terminal-tick', ['--max-iterations' => 1, '--sleep' => 1000])
            ->assertExitCode(0);

        Event::assertDispatched(TerminalOutput::class, function (TerminalOutput $event) use ($fakeId) {
            return $event->sessionId === $fakeId
                && $event->type === 'exit'
                && $event->exitCode === TerminalTickLoop::EXIT_ORPHAN;
        });
    }

    private function bashAvailable(): bool
    {
        return (new ExecutableFinder)->find('bash') !== null;
    }

    /**
     * Spin until the underlying process exits or the deadline passes.
     * Reaches into the service's internal `$processes` map by reflection
     * so tests stay fast (no sleep heuristic required).
     */
    private function waitForExit(TerminalSessionService $sessions, string $sessionId, float $maxSeconds = 3.0): void
    {
        $reflection = new \ReflectionClass($sessions);
        $processes = $reflection->getProperty('processes');
        $processes->setAccessible(true);
        $process = ($processes->getValue($sessions))[$sessionId] ?? null;

        if (! $process) {
            return;
        }

        $deadline = microtime(true) + $maxSeconds;

        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }
    }
}
