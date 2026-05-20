<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\DatabaseService;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DashboardController extends Controller
{
    public function __construct(
        protected ProjectService $projectService,
        protected DatabaseService $dbService,
    ) {}

    public function index()
    {
        $activeProject = $this->projectService->getActiveProject();
        $allProjects = $this->projectService->getAllProjects();

        // Eagerly augment with file stats only for what the dashboard
        // actually displays — the active project (one panel widget) and
        // the listing cards. Cached per-project for an hour.
        if ($activeProject) {
            $activeProject = $this->projectService->withFileStats($activeProject);
        }

        $allProjects = array_map(
            fn ($p) => $this->projectService->withFileStats($p),
            $allProjects,
        );

        $stats = [
            'tables' => 0,
            'files' => $activeProject['file_count'] ?? 0,
            'storage_used' => $activeProject['storage_used'] ?? '0 B',
            'projects' => count($allProjects),
        ];

        if ($activeProject && $activeProject['type'] === 'laravel') {
            $stats['tables'] = $this->countProjectTables($activeProject['path']);
        }

        return view('panel.dashboard', [
            'activeProject' => $activeProject,
            'allProjects' => $allProjects,
            'stats' => $stats,
        ]);
    }

    public function cacheClear(Request $request)
    {
        $project = $this->projectService->getActiveProject();

        if (! $project) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        try {
            $result = Process::path($project['path'])->run('php artisan optimize:clear');
            $output = $result->output();

            return response()->json(['success' => true, 'output' => $output]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function recentLogs(Request $request)
    {
        $project = $this->projectService->getActiveProject();

        if (! $project) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        $logFile = $project['path'].'/storage/logs/laravel.log';

        if (! File::exists($logFile)) {
            return response()->json(['success' => true, 'logs' => []]);
        }

        $content = File::get($logFile);
        $lines = array_filter(explode("\n", $content));
        $recent = array_slice($lines, -5);

        return response()->json(['success' => true, 'logs' => array_values($recent)]);
    }

    /**
     * Count user tables in the project's primary database.
     *
     * Uses a dedicated `panel_project_primary` connection — same one the
     * Database tab uses — so we never overwrite Laravel's own default
     * connection (which used to leak the project's DB credentials into
     * any subsequent code path that called DB::connection() unqualified).
     */
    protected function countProjectTables(string $projectPath): int
    {
        try {
            $env = $this->projectService->readEnvRaw($projectPath);
            if (empty($env['DB_DATABASE'])) {
                return 0;
            }

            $this->dbService->configureConnection('primary', $env);

            $driver = strtolower($env['DB_CONNECTION'] ?? 'mysql');

            return match ($driver) {
                'pgsql' => count(DB::connection('panel_project_primary')->select(
                    "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
                )),
                default => count(DB::connection('panel_project_primary')->select('SHOW TABLES')),
            };
        } catch (\Throwable) {
            // DB not available — dashboard still renders without the count.
            return 0;
        }
    }
}
