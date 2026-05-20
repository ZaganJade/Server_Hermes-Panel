<?php

namespace App\Providers;

use App\Services\Monitoring\MetricCollector;
use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Readers\ConnectionReader;
use App\Services\Monitoring\Readers\CpuReader;
use App\Services\Monitoring\Readers\DiskIoReader;
use App\Services\Monitoring\Readers\DiskUsageReader;
use App\Services\Monitoring\Readers\MemoryReader;
use App\Services\Monitoring\Readers\NetworkReader;
use App\Services\Monitoring\Readers\PortReader;
use App\Services\Monitoring\Readers\ProcessReader;
use App\Services\Monitoring\Readers\ServiceReader;
use App\Services\Monitoring\Readers\UptimeReader;
use App\Services\ProjectService;
use App\Services\TerminalCommandPolicy;
use App\Services\TerminalSessionService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // TerminalSessionService keeps in-memory state for active processes
        // (the Symfony Process objects). It MUST be a singleton inside the
        // tick-loop or the Process map gets recreated and the loop loses
        // its handles. Same reasoning when called from feature tests.
        $this->app->singleton(TerminalSessionService::class, fn ($app) => new TerminalSessionService(
            $app->make(CacheRepository::class),
            $app->make(TerminalCommandPolicy::class),
        ));

        // Single ProcResolver shared by every monitoring reader. The
        // resolver autodetects /host/proc inside the container and falls
        // back to /proc on host/dev runs.
        $this->app->singleton(ProcResolver::class, fn () => ProcResolver::autodetect());

        // Tag readers so MetricCollector can iterate them generically.
        // Story v3.2-02 starts the list with Cpu/Memory/Uptime; story
        // v3.2-03 adds disk + network + connection readers; story
        // v3.2-04 adds process/service/port readers without touching
        // the collector.
        $this->app->tag([
            CpuReader::class,
            MemoryReader::class,
            UptimeReader::class,
            DiskUsageReader::class,
            DiskIoReader::class,
            NetworkReader::class,
            ConnectionReader::class,
            ProcessReader::class,
            ServiceReader::class,
            PortReader::class,
        ], 'monitoring.readers');

        $this->app->singleton(MetricCollector::class, fn ($app) => new MetricCollector(
            $app->tagged('monitoring.readers'),
            $app->make(ProcResolver::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardPanelAuthInProduction();

        // Share project data with all panel views
        if (app()->runningInConsole() === false && request()->is('panel/*')) {
            $projectService = app(ProjectService::class);
            View::share('allProjects', $projectService->getAllProjects());
            View::share('activeProject', $projectService->getActiveProject());
        }
    }

    /**
     * Refuse to boot the panel in production when auth is disabled, unless
     * the operator explicitly opts in with PANEL_DEV_BYPASS=true.
     *
     * The panel can run a shell, edit files, and execute SQL. Letting it
     * serve traffic on a public domain without auth is almost always a
     * mistake — fail loud at startup instead of silently passing every
     * request through OwnerAccess.
     */
    protected function guardPanelAuthInProduction(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (! app()->environment('production')) {
            return;
        }

        $authEnabled = config('panel.auth_enabled', true);
        $devBypass = config('panel.dev_bypass', false);

        if (! $authEnabled && ! $devBypass) {
            throw new \RuntimeException(
                'Hermes Panel refuses to boot: PANEL_AUTH_ENABLED=false in '
                .'production without PANEL_DEV_BYPASS=true. Set '
                .'PANEL_AUTH_ENABLED=true for normal use, or set '
                .'PANEL_DEV_BYPASS=true if this instance really is on a '
                .'trusted network (SSH tunnel / VPN / private LAN).'
            );
        }
    }
}
