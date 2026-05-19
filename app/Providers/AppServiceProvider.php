<?php

namespace App\Providers;

use App\Services\ProjectService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
