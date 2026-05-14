<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DashboardController extends Controller
{
    public function __construct(
        protected ProjectService $projectService,
    ) {}

    public function index()
    {
        $activeProject = $this->projectService->getActiveProject();
        $allProjects = $this->projectService->getAllProjects();

        $stats = [
            'tables' => 0,
            'files' => $activeProject ? $activeProject['file_count'] : 0,
            'storage_used' => $activeProject ? $activeProject['storage_used'] : '0 B',
            'projects' => count($allProjects),
        ];

        // Try to count tables if project has DB config
        if ($activeProject && $activeProject['type'] === 'laravel') {
            try {
                $env = $this->projectService->readEnv($activeProject['path']);
                if (!empty($env['DB_DATABASE'])) {
                    $this->configureProjectDb($env);
                    $stats['tables'] = count(\DB::select('SHOW TABLES'));
                }
            } catch (\Throwable $e) {
                // DB not available
            }
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

        if (!$project) {
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

        if (!$project) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        $logFile = $project['path'] . '/storage/logs/laravel.log';

        if (!File::exists($logFile)) {
            return response()->json(['success' => true, 'logs' => []]);
        }

        $content = File::get($logFile);
        $lines = array_filter(explode("\n", $content));
        $recent = array_slice($lines, -5);

        return response()->json(['success' => true, 'logs' => array_values($recent)]);
    }

    protected function configureProjectDb(array $env): void
    {
        $connection = $env['DB_CONNECTION'] ?? 'mysql';

        config([
            "database.connections.{$connection}" => [
                'driver' => $connection,
                'host' => $env['DB_HOST'] ?? '127.0.0.1',
                'port' => $env['DB_PORT'] ?? 3306,
                'database' => $env['DB_DATABASE'] ?? '',
                'username' => $env['DB_USERNAME'] ?? 'root',
                'password' => $env['DB_PASSWORD'] ?? '',
                'charset' => $connection === 'mysql' ? 'utf8mb4' : 'utf8',
                'prefix' => '',
                'strict' => true,
            ],
        ]);

        \DB::setDefaultConnection($connection);
    }
}
