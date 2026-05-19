<?php

namespace Tests\Feature\Panel;

use App\Services\TerminalSessionService;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class TerminalApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests run with auth ON by default (matches the panel's safe
        // default). Individual tests opt out by toggling these.
        config([
            'panel.auth_enabled' => true,
            'panel.dev_bypass' => false,
            'panel.password' => 'test-password-secret',
        ]);
    }

    public function test_state_returns_idle_when_no_session(): void
    {
        $this->withAuth()
            ->getJson('/panel/api/terminal/state?project=desakta')
            ->assertOk()
            ->assertJsonPath('project', 'desakta')
            ->assertJsonPath('session', null)
            ->assertJsonPath('history', []);
    }

    public function test_state_includes_running_session_when_present(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        $session = app(TerminalSessionService::class)->spawn(
            project: 'desakta',
            command: 'sleep 2',
            cwd: sys_get_temp_dir(),
        );

        $this->withAuth()
            ->getJson('/panel/api/terminal/state?project=desakta')
            ->assertOk()
            ->assertJsonPath('session.session_id', $session->sessionId)
            ->assertJsonPath('session.project', 'desakta')
            ->assertJsonPath('session.status', 'running');
    }

    public function test_execute_requires_authentication(): void
    {
        $this->postJson('/panel/api/terminal/execute', [
            'project' => 'desakta',
            'command' => 'echo hi',
        ])->assertStatus(401);
    }

    public function test_execute_returns_session_id_when_authed(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        $this->withAuth()
            ->postJson('/panel/api/terminal/execute', [
                'project' => 'desakta',
                'command' => 'echo hi-async',
            ])
            ->assertStatus(202)
            ->assertJsonStructure(['session_id', 'started_at', 'cwd', 'display']);
    }

    public function test_execute_returns_422_when_project_missing(): void
    {
        $this->withAuth()
            ->postJson('/panel/api/terminal/execute', [
                'command' => 'echo missing-project',
            ])
            ->assertStatus(422);
    }

    public function test_execute_returns_422_when_command_blocked_by_policy(): void
    {
        $this->withAuth()
            ->postJson('/panel/api/terminal/execute', [
                'project' => 'desakta',
                'command' => 'rm --recursive --force /etc',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_execute_returns_409_when_session_already_running(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        $existing = app(TerminalSessionService::class)->spawn(
            project: 'desakta',
            command: 'sleep 5',
            cwd: sys_get_temp_dir(),
        );

        $this->withAuth()
            ->postJson('/panel/api/terminal/execute', [
                'project' => 'desakta',
                'command' => 'echo conflict',
            ])
            ->assertStatus(409)
            ->assertJsonPath('session_id', $existing->sessionId);
    }

    public function test_execute_is_rate_limited(): void
    {
        // 30 attempts allowed per minute per IP. The 31st must trip the
        // throttle middleware, regardless of whether the underlying call
        // would succeed.
        for ($i = 0; $i < 30; $i++) {
            $this->withAuth()->postJson('/panel/api/terminal/execute', [
                'project' => 'desakta',
                'command' => '',     // intentionally invalid → 422 quickly
            ]);
        }

        $this->withAuth()
            ->postJson('/panel/api/terminal/execute', [
                'project' => 'desakta',
                'command' => '',
            ])
            ->assertStatus(429);
    }

    public function test_stop_invokes_service(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        $session = app(TerminalSessionService::class)->spawn(
            project: 'desakta',
            command: 'sleep 5',
            cwd: sys_get_temp_dir(),
        );

        $this->withAuth()
            ->postJson("/panel/api/terminal/{$session->sessionId}/stop")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'exiting');
    }

    public function test_replay_returns_buffered_chunks(): void
    {
        if (! $this->bashAvailable()) {
            $this->markTestSkipped('bash not available on this host.');
        }

        $sessions = app(TerminalSessionService::class);
        $session = $sessions->spawn(
            project: 'desakta',
            command: 'echo replay-me',
            cwd: sys_get_temp_dir(),
        );

        $this->waitForExit($sessions, $session->sessionId);
        $sessions->tick($session->sessionId);

        $this->withAuth()
            ->getJson("/panel/api/terminal/{$session->sessionId}/replay")
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonStructure(['session', 'chunks', 'status']);
    }

    public function test_clear_history_drops_per_project_history(): void
    {
        $sessions = app(TerminalSessionService::class);
        $sessions->pushHistory('desakta', 'first-cmd', 0);
        $sessions->pushHistory('desakta', 'second-cmd', 1);

        $this->assertNotEmpty($sessions->history('desakta'));

        $this->withAuth()
            ->deleteJson('/panel/api/terminal/history', ['project' => 'desakta'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame([], $sessions->history('desakta'));
    }

    /**
     * Authenticate via the X-Panel-Password header (bypasses the login
     * form path; matches how external scripts auth).
     */
    private function withAuth(): self
    {
        return $this->withHeaders([
            'X-Panel-Password' => 'test-password-secret',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ]);
    }

    private function bashAvailable(): bool
    {
        return (new ExecutableFinder)->find('bash') !== null;
    }

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
