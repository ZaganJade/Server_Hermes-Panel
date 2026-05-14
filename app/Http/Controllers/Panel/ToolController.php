<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ToolController extends Controller
{
    public function __construct(
        protected ProjectService $projectService,
    ) {}

    protected function getProjectPath(): ?string
    {
        $project = $this->projectService->getActiveProject();
        return $project ? $project['path'] : null;
    }

    public function index()
    {
        $activeProject = $this->projectService->getActiveProject();

        return view('panel.tools', [
            'activeProject' => $activeProject,
            'suggestedCommands' => config('panel.suggested_artisan_commands', []),
            'allProjects' => $this->projectService->getAllProjects(),
        ]);
    }

    public function runArtisan(Request $request)
    {
        $command = $request->input('command', '');
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (!$projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        if (empty(trim($command))) {
            return response()->json(['success' => false, 'error' => 'Command is required']);
        }

        try {
            $result = Process::path($projectPath)
                ->timeout(120)
                ->run('php artisan ' . $command);

            return response()->json([
                'success' => $result->successful(),
                'output' => $result->output(),
                'error' => $result->errorOutput(),
                'exit_code' => $result->exitCode(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function getLogs(Request $request)
    {
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (!$projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        $logFile = $projectPath . '/storage/logs/laravel.log';

        if (!File::exists($logFile)) {
            return response()->json(['success' => true, 'logs' => []]);
        }

        $lines = (int) $request->get('lines', 100);
        $offset = (int) $request->get('offset', 0);
        $filter = $request->get('filter', 'all');
        $search = $request->get('search', '');

        $content = File::get($logFile);
        $logLines = array_filter(explode("\n", $content));

        // Apply filters
        if ($filter !== 'all') {
            $level = strtoupper($filter);
            $logLines = array_filter($logLines, fn ($line) => str_contains(strtoupper($line), $level));
        }

        if (!empty($search)) {
            $logLines = array_filter($logLines, fn ($line) => str_contains($line, $search));
        }

        $logLines = array_values($logLines);
        $total = count($logLines);

        return response()->json([
            'success' => true,
            'logs' => array_slice($logLines, $offset, $lines),
            'total' => $total,
        ]);
    }

    public function clearLogs(Request $request)
    {
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (!$projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        $logFile = $projectPath . '/storage/logs/laravel.log';

        if (File::exists($logFile)) {
            File::put($logFile, '');
        }

        return response()->json(['success' => true]);
    }

    public function queueStatus(Request $request)
    {
        $projectPath = $this->getProjectPath();

        if (!$projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        $env = $this->projectService->readEnv($projectPath);

        // Configure DB
        if (!empty($env['DB_DATABASE'])) {
            config([
                "database.connections.panel_queue" => [
                    'driver' => $env['DB_CONNECTION'] ?? 'mysql',
                    'host' => $env['DB_HOST'] ?? '127.0.0.1',
                    'port' => $env['DB_PORT'] ?? 3306,
                    'database' => $env['DB_DATABASE'] ?? '',
                    'username' => $env['DB_USERNAME'] ?? 'root',
                    'password' => $env['DB_PASSWORD'] ?? '',
                ],
            ]);

            try {
                $failedJobs = \DB::connection('panel_queue')->table('failed_jobs')->orderBy('failed_at', 'desc')->limit(50)->get();

                return response()->json([
                    'success' => true,
                    'failed_jobs' => $failedJobs,
                    'failed_count' => \DB::connection('panel_queue')->table('failed_jobs')->count(),
                ]);
            } catch (\Throwable $e) {
                return response()->json(['success' => true, 'failed_jobs' => [], 'failed_count' => 0, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['success' => true, 'failed_jobs' => [], 'failed_count' => 0]);
    }

    public function queueRetry(Request $request, $id)
    {
        $projectPath = $this->getProjectPath();

        if (!$projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        try {
            $env = $this->projectService->readEnv($projectPath);
            config(["database.connections.panel_queue" => [
                'driver' => $env['DB_CONNECTION'] ?? 'mysql',
                'host' => $env['DB_HOST'] ?? '127.0.0.1',
                'port' => $env['DB_PORT'] ?? 3306,
                'database' => $env['DB_DATABASE'] ?? '',
                'username' => $env['DB_USERNAME'] ?? 'root',
                'password' => $env['DB_PASSWORD'] ?? '',
            ]]);

            $job = \DB::connection('panel_queue')->table('failed_jobs')->find($id);

            if (!$job) {
                return response()->json(['success' => false, 'error' => 'Job not found']);
            }

            \DB::connection('panel_queue')->table('failed_jobs')->delete($id);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function queueRestart(Request $request)
    {
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (!$projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        $result = Process::path($projectPath)->run('php artisan queue:restart');

        return response()->json([
            'success' => $result->successful(),
            'output' => $result->output(),
        ]);
    }

    public function queueFlush(Request $request)
    {
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (!$projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        $result = Process::path($projectPath)->run('php artisan queue:flush');

        return response()->json([
            'success' => $result->successful(),
            'output' => $result->output(),
        ]);
    }

    public function runComposer(Request $request)
    {
        $command = $request->input('command', 'install');
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (!$projectPath) {
            return response()->json(['success' => false, 'error' => 'No project selected']);
        }

        $allowed = ['install', 'update', 'dump-autoload'];
        if (!in_array($command, $allowed)) {
            return response()->json(['success' => false, 'error' => 'Invalid composer command']);
        }

        try {
            $result = Process::path($projectPath)
                ->timeout(300)
                ->run("composer {$command} --no-interaction --ansi");

            return response()->json([
                'success' => $result->successful(),
                'output' => $result->output(),
                'error' => $result->errorOutput(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function runNpm(Request $request)
    {
        $command = $request->input('command', 'install');
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (!$projectPath) {
            return response()->json(['success' => false, 'error' => 'No project selected']);
        }

        $allowed = ['install', 'run build', 'run dev'];
        if (!in_array($command, $allowed)) {
            return response()->json(['success' => false, 'error' => 'Invalid npm command']);
        }

        try {
            $result = Process::path($projectPath)
                ->timeout(300)
                ->run("npm {$command}");

            return response()->json([
                'success' => $result->successful(),
                'output' => $result->output(),
                'error' => $result->errorOutput(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
