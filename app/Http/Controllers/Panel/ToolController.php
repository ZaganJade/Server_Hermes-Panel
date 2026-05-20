<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Jobs\CleanupDatabaseTrash;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ToolController extends Controller
{
    /**
     * Per-request memo of (`true` once configured) so the queue endpoints
     * stop re-reading `.env` and re-registering the `panel_queue`
     * connection on every AJAX call. Mirrors EFF-2 in DatabaseController.
     */
    protected bool $queueConnectionConfigured = false;

    public function __construct(
        protected ProjectService $projectService,
    ) {}

    protected function getProjectPath(): ?string
    {
        $project = $this->projectService->getActiveProject();

        return $project ? $project['path'] : null;
    }

    /**
     * Lazily register the project's DB credentials as the `panel_queue`
     * connection. Returns true on success, false when the project has
     * no DB configured. Idempotent within a single request.
     */
    protected function configureProjectQueueConnection(string $projectPath): bool
    {
        if ($this->queueConnectionConfigured) {
            return true;
        }

        $env = $this->projectService->readEnvRaw($projectPath);

        if (empty($env['DB_DATABASE'])) {
            return false;
        }

        config(['database.connections.panel_queue' => [
            'driver' => $env['DB_CONNECTION'] ?? 'mysql',
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'port' => $env['DB_PORT'] ?? 3306,
            'database' => $env['DB_DATABASE'] ?? '',
            'username' => $env['DB_USERNAME'] ?? 'root',
            'password' => $env['DB_PASSWORD'] ?? '',
        ]]);

        // New config means a stale connection might be cached — evict it
        // so the next DB::connection('panel_queue') picks up our writes.
        DB::purge('panel_queue');
        $this->queueConnectionConfigured = true;

        return true;
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
        $command = (string) $request->input('command', '');
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (! $projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project.']);
        }

        $command = trim($command);

        if ($command === '') {
            return response()->json(['success' => false, 'error' => 'Command is required.']);
        }

        // Reject shell metacharacters outright. The artisan runner is not a
        // shell — operators who need pipes, redirects, or chaining have the
        // panel terminal for that.
        if (preg_match('/[;&|`$<>\n\r]/', $command) || str_contains($command, '$(')) {
            return response()->json([
                'success' => false,
                'error' => 'Shell metacharacters are not permitted in artisan commands.',
            ]);
        }

        // Tokenise once, then audit the first token (the artisan subcommand).
        $tokens = preg_split('/\s+/', $command, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $subcommand = $tokens[0] ?? '';

        if (! $this->isArtisanCommandAllowed($subcommand)) {
            return response()->json([
                'success' => false,
                'error' => sprintf(
                    "'%s' is not permitted from the panel. Use SSH for unrestricted access.",
                    $subcommand,
                ),
            ]);
        }

        try {
            // Array form so Symfony Process spawns directly without going
            // through `/bin/sh -c …`. Each token becomes one argv entry —
            // shell injection via concat is impossible.
            $result = Process::path($projectPath)
                ->timeout(60)
                ->run(array_merge(['php', 'artisan'], $tokens));

            // Truncate output if too large (> 50KB)
            $output = $result->output();
            if (strlen($output) > 51200) {
                $output = substr($output, 0, 51200)."\n[output truncated]";
            }

            return response()->json([
                'success' => $result->successful(),
                'output' => $output,
                'error' => $result->errorOutput(),
                'exit_code' => $result->exitCode(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Command execution failed.']);
        }
    }

    /**
     * Allowlist of artisan subcommands that may be run from the panel UI.
     *
     * Anything destructive, secrets-rotating, or interactive lives outside
     * this list — operators who need them must SSH in. Override or extend via
     * `config('panel.allowed_artisan_commands', [])` without editing code.
     *
     * @return array<int, string>
     */
    protected function getAllowedArtisanCommands(): array
    {
        return array_values(array_unique(array_merge(
            [
                // Inspection — read-only, safe.
                'about', 'list', 'help',
                'route:list', 'route:cache', 'route:clear',
                'view:cache', 'view:clear',
                'config:cache', 'config:clear', 'config:show',
                'event:cache', 'event:clear', 'event:list',
                'optimize', 'optimize:clear',
                'cache:clear', 'cache:forget', 'cache:prune-stale-tags',
                'queue:listen', 'queue:work', 'queue:retry', 'queue:failed', 'queue:flush',
                'schedule:list', 'schedule:run',
                'storage:link',
                // Migrations — forward only. Rollback / fresh / wipe stay
                // off-list because they destroy data.
                'migrate', 'migrate:status', 'migrate:install',
                // Tests / pail — useful in dev panels.
                'test', 'pail',
            ],
            array_filter((array) config('panel.allowed_artisan_commands', []), 'is_string'),
        )));
    }

    /**
     * Subcommands that must never run via the panel, even if added to the
     * allowlist by accident.
     *
     * @return array<int, string>
     */
    protected function getBlockedArtisanCommands(): array
    {
        return [
            'tinker',                    // arbitrary code execution
            'key:generate',              // rotates APP_KEY — invalidates sessions
            'env:encrypt', 'env:decrypt',
            'down', 'up',                // toggles maintenance mode without UI confirmation
            'db:wipe', 'db:seed',
            'migrate:rollback', 'migrate:fresh', 'migrate:reset', 'migrate:refresh',
            'serve',                     // would bind another HTTP server
            'reverb:start', 'reverb:restart',
        ];
    }

    /**
     * Decide whether an artisan subcommand may run from the panel.
     *
     * Allow iff the subcommand is in the allowlist and not in the blocklist
     * (the blocklist always wins, so accidental additions to the allowlist
     * don't widen the surface).
     */
    protected function isArtisanCommandAllowed(string $subcommand): bool
    {
        $subcommand = strtolower(trim($subcommand));

        if ($subcommand === '') {
            return false;
        }

        if (in_array($subcommand, $this->getBlockedArtisanCommands(), true)) {
            return false;
        }

        return in_array($subcommand, $this->getAllowedArtisanCommands(), true);
    }

    public function getLogs(Request $request)
    {
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (! $projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        $logFile = $projectPath.'/storage/logs/laravel.log';

        if (! File::exists($logFile)) {
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

        if (! empty($search)) {
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

        if (! $projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        $logFile = $projectPath.'/storage/logs/laravel.log';

        if (File::exists($logFile)) {
            File::put($logFile, '');
        }

        return response()->json(['success' => true]);
    }

    public function queueStatus(Request $request)
    {
        $projectPath = $this->getProjectPath();

        if (! $projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        $env = $this->projectService->readEnvRaw($projectPath);

        if (! $this->configureProjectQueueConnection($projectPath)) {
            return response()->json(['success' => true, 'failed_jobs' => [], 'failed_count' => 0, 'queue_stats' => [], 'recent_jobs' => []]);
        }

        try {
            $failedJobs = DB::connection('panel_queue')->table('failed_jobs')->orderBy('failed_at', 'desc')->limit(50)->get();
            $jobsTable = DB::connection('panel_queue')->table('jobs')->limit(20)->orderBy('id', 'desc')->get();

            // Get recent jobs from jobs table if exists
            $recentJobs = [];
            foreach ($jobsTable as $job) {
                $payload = json_decode($job->payload ?? '{}', true);
                $recentJobs[] = [
                    'id' => $job->id,
                    'name' => $payload['displayName'] ?? 'Unknown',
                    'queue' => $job->queue ?? 'default',
                    'attempts' => $job->attempts ?? 0,
                    'status' => 'pending',
                    'runtime' => null,
                    'created_at' => $job->created_at ?? null,
                ];
            }

            // Get queue stats
            $queueSize = DB::connection('panel_queue')->table('jobs')->count();
            $failedCount = DB::connection('panel_queue')->table('failed_jobs')->count();

            return response()->json([
                'success' => true,
                'failed_jobs' => $failedJobs,
                'failed_count' => $failedCount,
                'queue_stats' => [
                    'driver' => $env['QUEUE_CONNECTION'] ?? 'database',
                    'connection' => 'default',
                    'workers' => 0, // Cannot detect from DB
                    'pid' => null,
                    'jobs_today' => $queueSize,
                ],
                'recent_jobs' => $recentJobs,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'failed_jobs' => [], 'failed_count' => 0, 'queue_stats' => [], 'recent_jobs' => [], 'error' => $e->getMessage()]);
        }
    }

    public function queueRetry(Request $request, $id)
    {
        $projectPath = $this->getProjectPath();

        if (! $projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        if (! $this->configureProjectQueueConnection($projectPath)) {
            return response()->json(['success' => false, 'error' => 'No database configured']);
        }

        try {
            $job = DB::connection('panel_queue')->table('failed_jobs')->find($id);

            if (! $job) {
                return response()->json(['success' => false, 'error' => 'Job not found']);
            }

            DB::connection('panel_queue')->table('failed_jobs')->delete($id);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function queueRestart(Request $request)
    {
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (! $projectPath) {
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

        if (! $projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        $result = Process::path($projectPath)->run('php artisan queue:flush');

        return response()->json([
            'success' => $result->successful(),
            'output' => $result->output(),
        ]);
    }

    public function dispatchCleanup(Request $request)
    {
        $projectPath = $this->getProjectPath();

        if (! $projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        // Dispatch CleanupDatabaseTrash directly through Laravel's bus.
        // The previous `php artisan queue:push` form silently failed
        // because that's not a real artisan command.
        try {
            CleanupDatabaseTrash::dispatch();

            return response()->json([
                'success' => true,
                'output' => 'Cleanup job dispatched.',
                'error' => '',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'output' => '',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function queueForget(Request $request, $id)
    {
        $projectPath = $this->getProjectPath();

        if (! $projectPath) {
            return response()->json(['success' => false, 'error' => 'No active project']);
        }

        if (! $this->configureProjectQueueConnection($projectPath)) {
            return response()->json(['success' => false, 'error' => 'No database configured']);
        }

        try {
            DB::connection('panel_queue')->table('failed_jobs')->where('id', $id)->delete();

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function runComposer(Request $request)
    {
        $command = $request->input('command', 'install');
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (! $projectPath) {
            return response()->json(['success' => false, 'error' => 'No project selected']);
        }

        $allowed = ['install', 'update', 'dump-autoload'];
        if (! in_array($command, $allowed)) {
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

        if (! $projectPath) {
            return response()->json(['success' => false, 'error' => 'No project selected']);
        }

        $allowed = ['install', 'run build', 'run dev'];
        if (! in_array($command, $allowed)) {
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

    /**
     * List available seeder files from the active project's database/seeders directory.
     */
    public function listSeeders(Request $request)
    {
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();

        if (! $projectPath) {
            return response()->json(['success' => false, 'seeders' => [], 'error' => 'No active project.']);
        }

        $seedersDir = $projectPath.'/database/seeders';

        if (! is_dir($seedersDir)) {
            return response()->json(['success' => true, 'seeders' => [], 'seeder_path' => $seedersDir]);
        }

        $files = scandir($seedersDir);
        $seeders = [];

        foreach ($files as $file) {
            if (is_file($seedersDir.'/'.$file) && preg_match('/^(?!.*Test\.php$).*Seeder\.php$/', $file)) {
                $seeders[] = [
                    'file' => $file,
                    'class' => pathinfo($file, PATHINFO_FILENAME),
                ];
            }
        }

        sort($seeders);

        return response()->json([
            'success' => true,
            'seeders' => $seeders,
            'seeder_path' => $seedersDir,
        ]);
    }

    /**
     * Run db:seed with optional specific seeder class.
     */
    public function dbSeed(Request $request)
    {
        $projectPath = $request->input('project_path') ?: $this->getProjectPath();
        $seederClass = $request->input('seeder', '');

        if (! $projectPath) {
            return response()->json(['success' => false, 'output' => '', 'error' => 'No active project.']);
        }

        $command = 'db:seed';
        if (! empty($seederClass) && $seederClass !== 'DatabaseSeeder') {
            $command .= ' --class='.$seederClass;
        }
        $command .= ' --force';

        try {
            $result = Process::path($projectPath)
                ->timeout(60)
                ->run('php artisan '.$command);

            $output = $result->output();
            if (strlen($output) > 51200) {
                $output = substr($output, 0, 51200)."\n[output truncated]";
            }

            return response()->json([
                'success' => $result->successful(),
                'output' => $output,
                'error' => $result->errorOutput(),
                'exit_code' => $result->exitCode(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'output' => '', 'error' => $e->getMessage()]);
        }
    }
}
