<?php

namespace Tests\Feature\Panel;

use App\Jobs\CleanupDatabaseTrash;
use App\Services\ProjectService;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Regression for the dispatchCleanup bug.
 *
 * The previous implementation shelled out to `php artisan queue:push
 * CleanupDatabaseTrash --queue=default`, but `queue:push` is not a
 * real artisan command in any Laravel release the panel targets. The
 * call always failed silently with "Command 'queue:push' is not
 * defined." while the JSON response said "Cleanup job dispatched."
 *
 * The fix dispatches the job directly through Laravel's bus.
 */
class ToolControllerDispatchCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.auth_enabled' => true,
            'panel.dev_bypass' => false,
            'panel.password' => 'test-password-secret',
        ]);
    }

    public function test_dispatch_cleanup_pushes_real_job_onto_the_bus(): void
    {
        Bus::fake();
        $this->stubActiveProject();

        $this->withAuth()
            ->postJson('/panel/api/queue/dispatch-cleanup')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('output', 'Cleanup job dispatched.');

        Bus::assertDispatched(CleanupDatabaseTrash::class);
    }

    public function test_dispatch_cleanup_returns_error_when_no_active_project(): void
    {
        Bus::fake();
        $this->stubNoActiveProject();

        $this->withAuth()
            ->postJson('/panel/api/queue/dispatch-cleanup')
            ->assertOk()
            ->assertJsonPath('success', false);

        Bus::assertNotDispatched(CleanupDatabaseTrash::class);
    }

    /**
     * Replace the bound ProjectService with a stub that returns a
     * synthetic active project. Avoids needing a real project tree on
     * disk during tests.
     */
    private function stubActiveProject(): void
    {
        $stub = new class extends ProjectService
        {
            public function getActiveProject(): ?array
            {
                return [
                    'name' => 'sandbox',
                    'folder' => 'sandbox',
                    'path' => sys_get_temp_dir(),
                    'type' => 'laravel',
                ];
            }
        };

        $this->app->instance(ProjectService::class, $stub);
    }

    private function stubNoActiveProject(): void
    {
        $stub = new class extends ProjectService
        {
            public function getActiveProject(): ?array
            {
                return null;
            }
        };

        $this->app->instance(ProjectService::class, $stub);
    }

    private function withAuth(): self
    {
        return $this->withHeaders([
            'X-Panel-Password' => 'test-password-secret',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ]);
    }
}
