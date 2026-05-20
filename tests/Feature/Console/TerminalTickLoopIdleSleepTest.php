<?php

namespace Tests\Feature\Console;

use App\Events\TerminalOutput;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Verifies the EFF-3 idle / active sleep cadence on TerminalTickLoop.
 *
 * The loop now accepts --idle-sleep (default 1s) on top of --sleep
 * (default 100 ms). When no session is active the loop sleeps the
 * idle interval; when sessions are active it sleeps the active
 * interval. We only assert that the command tolerates both options,
 * exits cleanly under --max-iterations, and never broadcasts when
 * the active-set is empty. Real-time clock measurements are
 * intentionally avoided — they're flaky on shared CI runners.
 */
class TerminalTickLoopIdleSleepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'logging.channels.terminal-tick' => [
                'driver' => 'single',
                'path' => sys_get_temp_dir().'/hermes-terminal-tick-test.log',
                'level' => 'debug',
            ],
        ]);

        Event::fake([TerminalOutput::class]);
    }

    public function test_command_accepts_idle_sleep_option(): void
    {
        $this->artisan('hermes:terminal-tick', [
            '--max-iterations' => 1,
            '--sleep' => 1000,
            '--idle-sleep' => 1000,
        ])->assertExitCode(0);
    }

    public function test_idle_loop_does_not_dispatch_events(): void
    {
        $this->artisan('hermes:terminal-tick', [
            '--max-iterations' => 3,
            '--sleep' => 1000,
            '--idle-sleep' => 1000,
        ])->assertExitCode(0);

        Event::assertNothingDispatched();
    }

    public function test_idle_sleep_clamped_to_active_sleep_minimum(): void
    {
        // Operator passes a smaller idle than active; the command must
        // still complete without error. Internally idle_sleep is raised
        // to active_sleep so we never sleep less than the active cadence
        // when idle.
        $this->artisan('hermes:terminal-tick', [
            '--max-iterations' => 1,
            '--sleep' => 5000,
            '--idle-sleep' => 1000,
        ])->assertExitCode(0);
    }
}
