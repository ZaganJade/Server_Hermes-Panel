<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PanelController extends Controller
{
    protected $currentProject;

    public function __construct()
    {
        $this->loadProjects();
    }

    public function loadProjects()
    {
        $configPath = base_path('config/panel.php');
        if (File::exists($configPath)) {
            $projects = config('panel.projects', []);
            $defaultProject = config('panel.default_project', 'desakta');
            $projectPath = $projects[$defaultProject] ?? null;
            
            if ($projectPath && File::exists($projectPath)) {
                $this->currentProject = [
                    'name' => $defaultProject,
                    'path' => $projectPath,
                    'env' => $this->loadEnv($projectPath),
                ];
            }
        }
    }

    private function loadEnv($projectPath)
    {
        $envFile = $projectPath . '/.env';
        $env = [];
        if (File::exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && !str_starts_with(trim($line), '#')) {
                    [$key, $value] = explode('=', $line, 2);
                    $env[trim($key)] = trim($value);
                }
            }
        }
        return $env;
    }

    // ==================== DASHBOARD ====================

    public function index()
    {
        $stats = [
            'tables' => $this->currentProject ? count($this->currentProject['tables'] ?? []) : 0,
            'files' => $this->countProjectFiles(),
            'storage_used' => $this->getStorageUsed(),
            'last_activity' => now()->format('d M Y, H:i'),
        ];

        return view('panel.index', [
            'currentProject' => $this->currentProject,
            'stats' => $stats,
        ]);
    }

    // ==================== DATABASE MANAGER ====================

    public function database()
    {
        $tables = [];
        if ($this->currentProject && isset($this->currentProject['env']['DB_CONNECTION'])) {
            try {
                config([
                    'database.default' => $this->currentProject['env']['DB_CONNECTION'],
                    'database.connections.mysql.host' => $this->currentProject['env']['DB_HOST'] ?? '127.0.0.1',
                    'database.connections.mysql.port' => $this->currentProject['env']['DB_PORT'] ?? '3306',
                    'database.connections.mysql.database' => $this->currentProject['env']['DB_DATABASE'] ?? '',
                    'database.connections.mysql.username' => $this->currentProject['env']['DB_USERNAME'] ?? 'root',
                    'database.connections.mysql.password' => $this->currentProject['env']['DB_PASSWORD'] ?? '',
                ]);
                $tables = DB::connection('mysql')->getSchemaBuilder()->getTableListing();
            } catch (\Exception $e) {
                $tables = [];
            }
        }

        return view('panel.database', [
            'currentProject' => $this->currentProject,
            'tables' => $tables,
        ]);
    }

    public function tableData(Request $request, $table)
    {
        try {
            $perPage = $request->get('per_page', 50);
            $page = $request->get('page', 1);
            $sort = $request->get('sort', 'id');
            $order = $request->get('order', 'desc');
            $search = $request->get('search', '');

            if (!Schema::connection('mysql')->hasTable($table)) {
                return response()->json(['error' => 'Table not found', 'data' => [], 'total' => 0]);
            }

            $query = DB::connection('mysql')->table($table);

            if ($search) {
                $columns = Schema::connection('mysql')->getColumnListing($table);
                $query->where(function($q) use ($columns, $search) {
                    foreach ($columns as $col) {
                        $q->orWhere($col, 'LIKE', "%{$search}%");
                    }
                });
            }

            $total = $query->count();
            $offset = ($page - 1) * $perPage;
            $data = $query->orderBy($sort, $order)->skip($offset)->take($perPage)->get()->toArray();

            return response()->json([
                'data' => $data,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => ceil($total / $perPage),
                'columns' => Schema::connection('mysql')->getColumnListing($table),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage(), 'data' => [], 'total' => 0]);
        }
    }

    public function runQuery(Request $request)
    {
        $sql = trim($request->get('sql', ''));
        $result = ['success' => false, 'data' => [], 'message' => '', 'rows_affected' => 0, 'error' => null];

        if (!$sql) {
            $result['error'] = 'SQL query is empty';
            return response()->json($result);
        }

        // Security: only allow SELECT, INSERT, UPDATE, DELETE
        $allowed = ['select', 'insert', 'update', 'delete', 'show', 'describe', 'explain'];
        $firstWord = strtolower(explode(' ', trim($sql))[0]);
        
        if (!in_array($firstWord, $allowed)) {
            $result['error'] = 'Only SELECT, INSERT, UPDATE, DELETE allowed';
            return response()->json($result);
        }

        try {
            config([
                'database.default' => $this->currentProject['env']['DB_CONNECTION'] ?? 'mysql',
                'database.connections.mysql.host' => $this->currentProject['env']['DB_HOST'] ?? '127.0.0.1',
                'database.connections.mysql.port' => $this->currentProject['env']['DB_PORT'] ?? '3306',
                'database.connections.mysql.database' => $this->currentProject['env']['DB_DATABASE'] ?? '',
                'database.connections.mysql.username' => $this->currentProject['env']['DB_USERNAME'] ?? 'root',
                'database.connections.mysql.password' => $this->currentProject['env']['DB_PASSWORD'] ?? '',
            ]);

            if (in_array($firstWord, ['select', 'show', 'describe', 'explain'])) {
                $data = DB::connection('mysql')->select(DB::raw($sql));
                $result['data'] = json_decode(json_encode($data), true);
                $result['rows_affected'] = count($data);
                $result['success'] = true;
            } else {
                $rows = DB::connection('mysql')->affectingStatement($sql);
                $result['rows_affected'] = $rows;
                $result['success'] = true;
                $result['message'] = "Query executed successfully. {$rows} row(s) affected.";
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return response()->json($result);
    }

    public function exportTable(Request $request, $table)
    {
        try {
            config([
                'database.default' => $this->currentProject['env']['DB_CONNECTION'] ?? 'mysql',
                'database.connections.mysql.host' => $this->currentProject['env']['DB_HOST'] ?? '127.0.0.1',
                'database.connections.mysql.port' => $this->currentProject['env']['DB_PORT'] ?? '3306',
                'database.connections.mysql.database' => $this->currentProject['env']['DB_DATABASE'] ?? '',
                'database.connections.mysql.username' => $this->currentProject['env']['DB_USERNAME'] ?? 'root',
                'database.connections.mysql.password' => $this->currentProject['env']['DB_PASSWORD'] ?? '',
            ]);

            $data = DB::connection('mysql')->table($table)->get();
            $filename = $table . '_' . date('Ymd_His') . '.json';
            
            $content = json_encode(['table' => $table, 'exported_at' => now()->toIso8601String(), 'row_count' => count($data), 'data' => $data], JSON_PRETTY_PRINT);
            
            return response()->streamDownload(function () use ($content) {
                echo $content;
            }, $filename, ['Content-Type' => 'application/json']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    // ==================== FILE MANAGER ====================

    public function files(Request $request)
    {
        $path = $request->get('path', '/');
        $fullPath = $this->currentProject ? rtrim($this->currentProject['path'], '/') . '/' . ltrim($path, '/') : base_path();

        // Security: prevent path traversal
        $realBase = $this->currentProject ? realpath($this->currentProject['path']) : realpath(base_path());
        $realPath = realpath($fullPath);
        
        if (!$realPath || !str_starts_with($realPath, $realBase)) {
            $fullPath = $this->currentProject ? $this->currentProject['path'] : base_path();
            $path = '/';
            $realPath = $realBase;
        }

        $items = [];
        $directories = [];
        $files = [];

        if (is_dir($fullPath)) {
            $entries = scandir($fullPath);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                
                $entryPath = $fullPath . '/' . $entry;
                $relativePath = str_replace($this->currentProject['path'], '', $entryPath);
                
                if (is_dir($entryPath)) {
                    $directories[] = [
                        'name' => $entry,
                        'path' => $relativePath,
                        'size' => '-',
                        'modified' => date('d M Y H:i', filemtime($entryPath)),
                    ];
                } else {
                    $files[] = [
                        'name' => $entry,
                        'path' => $relativePath,
                        'size' => $this->formatFileSize(filesize($entryPath)),
                        'modified' => date('d M Y H:i', filemtime($entryPath)),
                        'extension' => pathinfo($entry, PATHINFO_EXTENSION),
                    ];
                }
            }
        }

        // Sort
        usort($directories, fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

        $breadcrumbs = $this->getBreadcrumbs($path);
        
        return view('panel.files', [
            'currentProject' => $this->currentProject,
            'directories' => $directories,
            'files' => $files,
            'currentPath' => $path,
            'breadcrumbs' => $breadcrumbs,
            'parentPath' => $this->getParentPath($path),
        ]);
    }

    public function fileContent(Request $request, $path)
    {
        $fullPath = $this->currentProject ? rtrim($this->currentProject['path'], '/') . '/' . ltrim($path, '/') : base_path($path);

        $realBase = $this->currentProject ? realpath($this->currentProject['path']) : realpath(base_path());
        $realFilePath = realpath($fullPath);

        if (!$realFilePath || !str_starts_with($realFilePath, $realBase)) {
            return response()->json(['error' => 'Access denied']);
        }

        if (!File::exists($realFilePath)) {
            return response()->json(['error' => 'File not found']);
        }

        $content = File::get($realFilePath);
        $extension = pathinfo($realFilePath, PATHINFO_EXTENSION);
        $editableExtensions = ['php', 'js', 'css', 'blade.php', 'html', 'txt', 'json', 'env', 'md', 'yaml', 'yml', 'xml', 'sql'];

        return response()->json([
            'content' => $content,
            'extension' => $extension,
            'editable' => in_array($extension, $editableExtensions),
            'size' => $this->formatFileSize(filesize($realFilePath)),
            'modified' => date('d M Y H:i', filemtime($realFilePath)),
            'name' => basename($realFilePath),
        ]);
    }

    public function saveFile(Request $request, $path)
    {
        $fullPath = $this->currentProject ? rtrim($this->currentProject['path'], '/') . '/' . ltrim($path, '/') : base_path($path);

        $realBase = $this->currentProject ? realpath($this->currentProject['path']) : realpath(base_path());
        $realFilePath = realpath($fullPath);

        if (!$realFilePath || !str_starts_with($realFilePath, $realBase)) {
            return response()->json(['success' => false, 'error' => 'Access denied']);
        }

        $content = $request->get('content', '');
        
        try {
            File::put($realFilePath, $content);
            return response()->json(['success' => true, 'message' => 'File saved successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function createFile(Request $request)
    {
        $path = $request->get('path', '/');
        $name = $request->get('name', '');
        $type = $request->get('type', 'file');
        $fullPath = $this->currentProject ? rtrim($this->currentProject['path'], '/') . '/' . ltrim($path, '/') : base_path($path);

        if (!$name) {
            return response()->json(['success' => false, 'error' => 'Name is required']);
        }

        $targetPath = $fullPath . '/' . $name;

        try {
            if ($type === 'directory') {
                mkdir($targetPath, 0755, true);
            } else {
                File::put($targetPath, '');
            }
            return response()->json(['success' => true, 'message' => ucfirst($type) . ' created successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function deleteFile(Request $request)
    {
        $path = $request->get('path', '');
        $fullPath = $this->currentProject ? rtrim($this->currentProject['path'], '/') . '/' . ltrim($path, '/') : base_path($path);

        $realBase = $this->currentProject ? realpath($this->currentProject['path']) : realpath(base_path());
        $realFilePath = realpath($fullPath);

        if (!$realFilePath || !str_starts_with($realFilePath, $realBase)) {
            return response()->json(['success' => false, 'error' => 'Access denied']);
        }

        try {
            if (is_dir($fullPath)) {
                File::deleteDirectory($fullPath);
            } else {
                File::delete($fullPath);
            }
            return response()->json(['success' => true, 'message' => 'Deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function uploadFile(Request $request)
    {
        $path = $request->get('path', '/');
        $fullPath = $this->currentProject ? rtrim($this->currentProject['path'], '/') . '/' . ltrim($path, '/') : base_path($path);

        $file = $request->file('file');
        
        if (!$file) {
            return response()->json(['success' => false, 'error' => 'No file uploaded']);
        }

        try {
            $file->move($fullPath, $file->getClientOriginalName());
            return response()->json(['success' => true, 'message' => 'File uploaded successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ==================== LARAVEL TOOLS ====================

    public function tools()
    {
        return view('panel.tools', [
            'currentProject' => $this->currentProject,
        ]);
    }

    public function runArtisan(Request $request)
    {
        $command = $request->get('command', '');
        $projectPath = $this->currentProject['path'] ?? base_path();

        if (!$command) {
            return response()->json(['success' => false, 'error' => 'Command is required']);
        }

        // Security: whitelist specific commands
        $allowedCommands = [
            'cache:clear', 'config:clear', 'view:clear', 'route:clear', 'event:clear',
            'optimize:clear', 'migrate', 'migrate:fresh', 'migrate:rollback',
            'db:seed', 'queue:restart', 'queue:work', 'queue:flush', 'queue:prune-batches',
            'make:seeder', 'make:migration', 'make:model', 'make:controller',
            'key:generate', 'route:list', 'config:cache', 'route:cache',
            'storage:link', 'vendor:publish', 'package:discover',
        ];

        $baseCommand = explode(' ', $command)[0];
        if (!in_array($baseCommand, $allowedCommands)) {
            return response()->json(['success' => false, 'error' => "Command '{$baseCommand}' is not allowed"]);
        }

        try {
            chdir($projectPath);
            $process = Process::fromShellCommandline("php artisan {$command}", $projectPath);
            $process->setTimeout(120);
            $output = $process->run();

            return response()->json([
                'success' => $output->isSuccessful(),
                'output' => $output->getOutput(),
                'error' => $output->getErrorOutput(),
                'exit_code' => $process->getExitCode(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function getLogs(Request $request)
    {
        $projectPath = $this->currentProject['path'] ?? base_path();
        $logFile = $projectPath . '/storage/logs/laravel.log';

        if (!File::exists($logFile)) {
            return response()->json(['logs' => [], 'error' => 'Log file not found']);
        }

        $lines = $request->get('lines', 100);
        $content = File::get($logFile);
        $logLines = array_filter(explode("\n", $content));
        $recentLines = array_slice($logLines, -$lines);

        return response()->json([
            'logs' => array_values($recentLines),
        ]);
    }

    public function getProjectStatus()
    {
        $projectPath = $this->currentProject['path'] ?? base_path();
        $artisanPath = $projectPath . '/artisan';

        return response()->json([
            'project_exists' => File::exists($projectPath),
            'artisan_exists' => File::exists($artisanPath),
            'env_exists' => File::exists($projectPath . '/.env'),
            'storage_exists' => File::exists($projectPath . '/storage'),
            'vendor_exists' => File::exists($projectPath . '/vendor'),
        ]);
    }

    // ==================== PROJECT MANAGEMENT ====================

    public function projects()
    {
        $projects = config('panel.projects', []);
        return view('panel.projects', [
            'currentProject' => $this->currentProject,
            'projects' => $projects,
        ]);
    }

    public function addProject(Request $request)
    {
        $name = $request->get('name', '');
        $path = $request->get('path', '');

        if (!$name || !$path) {
            return response()->json(['success' => false, 'error' => 'Name and path are required']);
        }

        if (!File::exists($path)) {
            return response()->json(['success' => false, 'error' => 'Path does not exist']);
        }

        $configPath = base_path('config/panel.php');
        $config = File::exists($configPath) ? include($configPath) : [];

        $config['projects'] = $config['projects'] ?? [];
        $config['projects'][$name] = $path;

        $configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        File::put($configPath, $configContent);

        return response()->json(['success' => true, 'message' => 'Project added successfully']);
    }

    public function removeProject(Request $request)
    {
        $name = $request->get('name', '');

        $configPath = base_path('config/panel.php');
        $config = File::exists($configPath) ? include($configPath) : [];

        unset($config['projects'][$name]);

        $configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        File::put($configPath, $configContent);

        return response()->json(['success' => true, 'message' => 'Project removed successfully']);
    }

    // ==================== HELPER METHODS ====================

    private function countProjectFiles()
    {
        if (!$this->currentProject) return 0;
        
        $path = $this->currentProject['path'];
        $count = 0;
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) $count++;
        }

        return $count;
    }

    private function getStorageUsed()
    {
        if (!$this->currentProject) return '0 B';
        
        $path = $this->currentProject['path'];
        $size = 0;
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) $size += $file->getSize();
        }

        return $this->formatFileSize($size);
    }

    private function formatFileSize($bytes)
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }

    private function getBreadcrumbs($path)
    {
        $parts = explode('/', trim($path, '/'));
        $breadcrumbs = [['name' => 'root', 'path' => '/']];
        $current = '';

        foreach ($parts as $part) {
            if ($part) {
                $current .= '/' . $part;
                $breadcrumbs[] = ['name' => $part, 'path' => $current];
            }
        }

        return $breadcrumbs;
    }

    private function getParentPath($path)
    {
        $parts = explode('/', trim($path, '/'));
        array_pop($parts);
        return '/' . implode('/', $parts);
    }
}