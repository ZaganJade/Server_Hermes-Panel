<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\ProjectService;
use App\Services\DatabaseService;

class CleanupDatabaseTrash implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function handle(): void
    {
        $projectService = app(ProjectService::class);
        $dbService = app(DatabaseService::class);

        $projects = $projectService->getAllProjects();

        foreach ($projects as $projectName => $project) {
            if ($project['type'] !== 'laravel') continue;

            $env = $projectService->readEnv($project['path']);
            if (empty($env['DB_DATABASE'])) continue;

            $connName = "panel_project_{$projectName}";
            $dbService->configureConnection($projectName, $env);

            $tables = $dbService->getTables($connName);

            foreach ($tables as $table) {
                $tableName = $table['name'];

                if (!$dbService->detectSoftDeletes($connName, $tableName)) continue;

                try {
                    $deleted = DB::connection($connName)
                        ->table($tableName)
                        ->onlyTrashed()
                        ->where('deleted_at', '<', Carbon::now()->subDays(30));

                    $count = $deleted->count();

                    if ($count > 0) {
                        $deleted->forceDelete();
                        \Log::info("CleanupDatabaseTrash: {$projectName}.{$tableName} — {$count} rows deleted");
                    }
                } catch (\Throwable $e) {
                    \Log::error("CleanupDatabaseTrash: {$projectName}.{$tableName} failed — {$e->getMessage()}");
                }
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('CleanupDatabaseTrash job failed: ' . $exception->getMessage());
    }
}