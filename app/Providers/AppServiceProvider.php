<?php

namespace App\Providers;

use App\Services\ProjectService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

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
        // Share project data with all panel views
        if (app()->runningInConsole() === false && request()->is('panel/*')) {
            $projectService = app(ProjectService::class);
            View::share('allProjects', $projectService->getAllProjects());
            View::share('activeProject', $projectService->getActiveProject());
        }
    }
}
