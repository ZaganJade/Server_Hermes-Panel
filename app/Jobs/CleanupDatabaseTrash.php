<?php

namespace App\Jobs;

use App\Services\DatabaseService;
use App\Services\ProjectService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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
            if ($project['type'] !== 'laravel') {
                continue;
            }

            $env = $projectService->readEnvRaw($project['path']);
            if (empty($env['DB_DATABASE'])) {
                continue;
            }

            $connName = "panel_project_{$projectName}";
            $dbService->configureConnection($projectName, $env);

            $tables = $dbService->getTables($connName);

            foreach ($tables as $table) {
                $tableName = $table['name'];

                if (! $dbService->detectSoftDeletes($connName, $tableName)) {
                    continue;
                }

                try {
                    // Raw query builder: no onlyTrashed()/forceDelete() (those
                    // are Eloquent SoftDeletes helpers). Old trashed rows are
                    // `deleted_at` older than the cutoff; remove with delete().
                    $deleted = DB::connection($connName)
                        ->table($tableName)
                        ->whereNotNull('deleted_at')
                        ->where('deleted_at', '<', Carbon::now()->subDays(30));

                    $count = $deleted->count();

                    if ($count > 0) {
                        $deleted->delete();
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
        \Log::error('CleanupDatabaseTrash job failed: '.$exception->getMessage());
    }
}
