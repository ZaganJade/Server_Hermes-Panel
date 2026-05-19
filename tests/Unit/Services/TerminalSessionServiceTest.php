<?php

namespace Tests\Unit\Services;

use App\Services\TerminalCommandPolicy;
use App\Services\TerminalSessionService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class TerminalSessionServiceTest extends TestCase
{
    private Repository $cache;

    private TerminalSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Fresh in-memory cache per test so we never bleed state.
        $this->cache = new Repository(new ArrayStore);
        $this->service = new TerminalSessionService(
            $this->cache,
            new TerminalCommandPolicy,
        );

        // No-op logger so spawn() doesn't try to write to disk during tests.
        // Individual tests can override (see test_spawn_writes_audit_log).
        config(['logging.channels.terminal-audit' => ['driver' => 'null']]);
        Log::extend('null', fn () => new NullLogger);
    }

    public function test_spawn_creates_active_metadata_and_meta_chunk(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        $session = $this->service->spawn(
            project: 'desakta',
            command: 'echo hello',
            cwd: sys_get_temp_dir(),
            clientIp: '203.0.113.7',
        );

        $this->assertNotEmpty($session->sessionId);
        $this->assertGreaterThan(0, $session->pid);
        $this->assertSame('desakta', $session->project);
        $this->assertSame('echo hello', $session->command);
        $this->assertSame('running', $session->status);

        $this->assertTrue($this->cache->has("hermes:term:active:{$session->sessionId}"));
        $this->assertSame(
            $session->sessionId,
            $this->cache->get('hermes:term:project:desakta')
        );

        $buffer = $this->cache->get("hermes:term:buffer:{$session->sessionId}", []);
        $this->assertCount(1, $buffer);
        $this->assertSame('meta', $buffer[0]['type']);
        $this->assertStringContainsString($session->sessionId, $buffer[0]['data']);
    }

    public function test_spawn_writes_audit_log(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        Log::shouldReceive('channel')
            ->with('terminal-audit')
            ->once()
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->with('terminal_command_run', \Mockery::on(function (array $context) {
                return ($context['command'] ?? null) === 'echo hi'
                    && ($context['project'] ?? null) === 'desakta'
                    && ($context['ip'] ?? null) === '203.0.113.7'
                    && isset($context['session_id'], $context['pid'], $context['cwd']);
            }));

        $this->service->spawn(
            project: 'desakta',
            command: 'echo hi',
            cwd: sys_get_temp_dir(),
            clientIp: '203.0.113.7',
        );
    }

    public function test_spawn_rejects_blocked_command(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/interactive/i');

        $this->service->spawn(
            project: 'desakta',
            command: 'vim file.txt',
            cwd: sys_get_temp_dir(),
        );
    }

    public function test_spawn_rejects_empty_command(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->spawn(
            project: 'desakta',
            command: '   ',
            cwd: sys_get_temp_dir(),
        );
    }

    public function test_tick_appends_stdout_and_finalises_with_exit_chunk(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        $session = $this->service->spawn(
            project: 'desakta',
            command: 'echo hello-tick',
            cwd: sys_get_temp_dir(),
        );

        // Wait for the short command to finish, then drain pipes.
        $this->waitForExit($session->sessionId);
        $this->service->tick($session->sessionId);

        $replay = $this->service->replay($session->sessionId);

        $this->assertSame('done', $replay['status']);
        $this->assertNotEmpty($replay['chunks']);

        $types = array_column($replay['chunks'], 'type');
        $this->assertContains('stdout', $types, 'stdout chunk should be present');
        $this->assertContains('exit', $types, 'exit chunk should be present');

        $stdoutChunk = collect($replay['chunks'])->firstWhere('type', 'stdout');
        $this->assertStringContainsString('hello-tick', $stdoutChunk['data']);

        $exitChunk = collect($replay['chunks'])->firstWhere('type', 'exit');
        $this->assertSame(0, $exitChunk['exit_code']);
    }

    public function test_replay_returns_idle_status_for_unknown_session(): void
    {
        $replay = $this->service->replay('nonexistent-id');

        $this->assertNull($replay['session']);
        $this->assertSame([], $replay['chunks']);
        $this->assertSame('idle', $replay['status']);
    }

    public function test_destroy_removes_active_and_buffer_keys(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        $session = $this->service->spawn(
            project: 'desakta',
            command: 'echo bye',
            cwd: sys_get_temp_dir(),
        );

        $this->service->destroy($session->sessionId);

        $this->assertFalse($this->cache->has("hermes:term:active:{$session->sessionId}"));
        $this->assertFalse($this->cache->has("hermes:term:buffer:{$session->sessionId}"));
        $this->assertFalse($this->cache->has('hermes:term:project:desakta'));
    }

    public function test_history_pushes_and_caps_at_50(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->service->pushHistory('desakta', "cmd-{$i}", $i % 2 === 0 ? 0 : 1);
        }

        $history = $this->service->history('desakta');

        $this->assertCount(50, $history);
        // Newest first
        $this->assertSame('cmd-55', $history[0]['command']);
        $this->assertSame(1, $history[0]['exit_code']);
        // Oldest 5 dropped
        $commands = array_column($history, 'command');
        $this->assertNotContains('cmd-1', $commands);
        $this->assertNotContains('cmd-5', $commands);
        $this->assertContains('cmd-6', $commands);
    }

    public function test_clear_history_drops_the_key(): void
    {
        $this->service->pushHistory('desakta', 'cmd-1', 0);
        $this->assertNotEmpty($this->service->history('desakta'));

        $this->service->clearHistory('desakta');

        $this->assertSame([], $this->service->history('desakta'));
    }

    public function test_active_session_id_returns_pointer_when_session_alive(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        $session = $this->service->spawn(
            project: 'desakta',
            command: 'echo ok',
            cwd: sys_get_temp_dir(),
        );

        $this->assertSame($session->sessionId, $this->service->activeSessionId('desakta'));
    }

    public function test_active_session_id_ignores_stale_pointer(): void
    {
        // Pointer set without a corresponding active key
        $this->cache->put('hermes:term:project:ghost', 'ghost-id', 60);

        $this->assertNull($this->service->activeSessionId('ghost'));
    }

    public function test_buffer_fifo_caps_at_512kb(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        $session = $this->service->spawn(
            project: 'desakta',
            command: 'echo seed',
            cwd: sys_get_temp_dir(),
        );

        // Flush meta chunk so the size budget is clean
        $this->waitForExit($session->sessionId);
        $this->service->tick($session->sessionId);

        // Force append big chunks via reflection on the protected method
        $appendChunk = (new \ReflectionClass($this->service))->getMethod('appendChunk');
        $appendChunk->setAccessible(true);

        $payload = str_repeat('A', 100 * 1024);    // 100 KB per chunk
        for ($i = 0; $i < 8; $i++) {     // 800 KB total
            $appendChunk->invoke($this->service, $session->sessionId, [
                'ts' => time(),
                'type' => 'stdout',
                'data' => $payload,
            ]);
        }

        $buffer = $this->cache->get("hermes:term:buffer:{$session->sessionId}", []);
        $totalBytes = array_sum(array_map(fn ($c) => strlen($c['data'] ?? ''), $buffer));

        $this->assertLessThanOrEqual(
            TerminalSessionService::BUFFER_BYTES_CAP,
            $totalBytes,
            'Buffer must be FIFO-trimmed to within the byte cap.'
        );
    }

    public function test_stop_marks_status_exiting_for_active_session(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        $session = $this->service->spawn(
            project: 'desakta',
            command: 'sleep 5',
            cwd: sys_get_temp_dir(),
        );

        $result = $this->service->stop($session->sessionId);

        $this->assertTrue($result);

        // Drain final state
        $this->service->tick($session->sessionId);

        $replay = $this->service->replay($session->sessionId);
        // Either 'exiting' (still tearing down) or 'done' (already gone)
        $this->assertContains($replay['status'], ['exiting', 'done']);
    }

    public function test_stop_returns_false_for_unknown_session(): void
    {
        $this->assertFalse($this->service->stop('nope-123'));
    }

    /**
     * Bash is the documented shell for the panel terminal. On Windows
     * dev hosts without WSL these tests are skipped rather than failed.
     */
    private function bashAvailable(): bool
    {
        $finder = new ExecutableFinder;

        return $finder->find('bash') !== null;
    }

    /**
     * Spin until either the underlying process exits or the timeout
     * passes. Tests use very short commands so this never blocks long.
     */
    private function waitForExit(string $sessionId, float $maxSeconds = 2.0): void
    {
        $reflection = new \ReflectionClass($this->service);
        $processes = $reflection->getProperty('processes');
        $processes->setAccessible(true);
        $map = $processes->getValue($this->service);
        $process = $map[$sessionId] ?? null;

        if (! $process) {
            return;
        }

        $deadline = microtime(true) + $maxSeconds;

        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }
    }
}
