<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

class ProjectService
{
    protected string $projectsDir;
    protected string $projectsJsonPath;

    public function __construct()
    {
        $this->projectsDir = base_path(config('panel.projects_dir', 'Project'));
        $this->projectsJsonPath = base_path('projects.json');
    }

    /**
     * Get all discovered and manual projects.
     */
    public function getAllProjects(bool $includeHidden = false): array
    {
        $discovered = $this->discoverProjects();
        $manual = $this->getManualProjects();
        $hidden = $this->getHiddenProjects();

        $projects = array_merge($discovered, $manual);

        if (!$includeHidden) {
            $projects = array_diff_key($projects, $hidden);
        }

        return $projects;
    }

    /**
     * Scan the projects directory for Laravel projects.
     */
    public function discoverProjects(): array
    {
        return Cache::remember('panel.discovered_projects', config('panel.discovery_cache_ttl', 300), function () {
            $projects = [];

            if (!is_dir($this->projectsDir)) {
                return $projects;
            }

            $entries = scandir($this->projectsDir);

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $this->projectsDir . '/' . $entry;

                if (!is_dir($path)) {
                    continue;
                }

                $isLaravel = file_exists($path . '/artisan');
                $projects[$entry] = $this->buildProjectData($entry, $path, $isLaravel ? 'laravel' : 'generic');
            }

            return $projects;
        });
    }

    /**
     * Build project metadata array.
     */
    protected function buildProjectData(string $name, string $path, string $type): array
    {
        $composerJson = $this->readComposerJson($path);
        $env = $this->readEnv($path);

        return [
            'name' => $composerJson['name'] ?? $name,
            'display_name' => $env['APP_NAME'] ?? $name,
            'folder' => $name,
            'path' => $path,
            'type' => $type,
            'laravel_version' => $this->extractLaravelVersion($composerJson),
            'php_version' => $composerJson['require']['php'] ?? 'unknown',
            'status' => [
                'env' => file_exists($path . '/.env'),
                'vendor' => is_dir($path . '/vendor'),
                'storage' => is_dir($path . '/storage'),
                'db_connected' => false, // Checked on-demand
            ],
            'file_count' => $this->countFiles($path),
            'storage_used' => $this->getStorageUsed($path),
            'last_modified' => filemtime($path),
        ];
    }

    /**
     * Get manual projects from projects.json.
     */
    protected function getManualProjects(): array
    {
        $data = $this->readProjectsJson();
        $projects = [];

        foreach ($data['manual'] ?? [] as $name => $path) {
            if (!is_dir($path)) {
                continue;
            }

            $isLaravel = file_exists($path . '/artisan');
            $projects[$name] = $this->buildProjectData($name, $path, $isLaravel ? 'laravel' : 'generic');
            $projects[$name]['manual'] = true;
        }

        return $projects;
    }

    /**
     * Get hidden project names.
     */
    public function getHiddenProjects(): array
    {
        $data = $this->readProjectsJson();
        return $data['hidden'] ?? [];
    }

    /**
     * Get the currently active project.
     */
    public function getActiveProject(): ?array
    {
        $activeName = session('active_project');
        if (!$activeName) {
            return null;
        }

        $projects = $this->getAllProjects();
        return $projects[$activeName] ?? null;
    }

    /**
     * Get project by name.
     */
    public function getProject(string $name): ?array
    {
        return $this->getAllProjects()[$name] ?? null;
    }

    /**
     * Switch the active project.
     */
    public function switchProject(?string $name): bool
    {
        if ($name) {
            $project = $this->getProject($name);
            if (!$project) {
                return false;
            }
        }

        session(['active_project' => $name]);
        Cache::forget('panel.discovered_projects');
        return true;
    }

    /**
     * Add a manual project.
     */
    public function addManualProject(string $name, string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        $data = $this->readProjectsJson();
        $data['manual'] = $data['manual'] ?? [];
        $data['manual'][$name] = $path;
        $this->writeProjectsJson($data);

        Cache::forget('panel.discovered_projects');
        return true;
    }

    /**
     * Hide a project.
     */
    public function hideProject(string $name): bool
    {
        $data = $this->readProjectsJson();
        $data['hidden'] = $data['hidden'] ?? [];
        $data['hidden'][$name] = true;
        $this->writeProjectsJson($data);

        Cache::forget('panel.discovered_projects');
        return true;
    }

    /**
     * Un-hide a project.
     */
    public function unhideProject(string $name): bool
    {
        $data = $this->readProjectsJson();
        unset($data['hidden'][$name]);
        $this->writeProjectsJson($data);

        Cache::forget('panel.discovered_projects');
        return true;
    }

    /**
     * Delete a project permanently (rm -rf).
     */
    public function deleteProject(string $name): bool
    {
        $projects = $this->getAllProjects(true);
        $project = $projects[$name] ?? null;

        if (!$project) {
            return false;
        }

        $path = $project['path'];

        if (!str_starts_with(realpath($path), realpath($this->projectsDir))) {
            return false;
        }

        File::deleteDirectory($path);

        // Also remove from manual list if present
        $data = $this->readProjectsJson();
        unset($data['manual'][$name], $data['hidden'][$name]);
        $this->writeProjectsJson($data);

        Cache::forget('panel.discovered_projects');

        return true;
    }

    /**
     * Test DB connection for a project.
     */
    public function testDbConnection(array $env): bool
    {
        try {
            $connection = $env['DB_CONNECTION'] ?? 'mysql';
            $host = $env['DB_HOST'] ?? '127.0.0.1';
            $port = $env['DB_PORT'] ?? 3306;
            $database = $env['DB_DATABASE'] ?? '';
            $username = $env['DB_USERNAME'] ?? 'root';
            $password = $env['DB_PASSWORD'] ?? '';

            // Try a simple connection test
            config([
                "database.connections.{$connection}_test" => [
                    'driver' => $connection,
                    'host' => $host,
                    'port' => $port,
                    'database' => $database,
                    'username' => $username,
                    'password' => $password,
                ],
            ]);

            \DB::connection("{$connection}_test")->getPdo();
            \DB::disconnect("{$connection}_test");

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Read project's .env file.
     */
    public function readEnv(string $projectPath): array
    {
        $envFile = $projectPath . '/.env';
        $env = [];

        if (!File::exists($envFile)) {
            return $env;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value);
        }

        return $env;
    }

    /**
     * Read project's composer.json.
     */
    protected function readComposerJson(string $projectPath): array
    {
        $file = $projectPath . '/composer.json';

        if (!File::exists($file)) {
            return [];
        }

        $content = json_decode(File::get($file), true);

        return is_array($content) ? $content : [];
    }

    /**
     * Extract Laravel version from composer.json.
     */
    protected function extractLaravelVersion(array $composerJson): string
    {
        $laravel = $composerJson['require']['laravel/framework'] ?? null;

        if (!$laravel) {
            return 'unknown';
        }

        // Strip version constraints: "^11.0" → "11.0"
        return preg_replace('/[^0-9.]/', '', $laravel);
    }

    /**
     * Count files in a directory (recursive).
     */
    protected function countFiles(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get total storage used by a directory.
     */
    protected function getStorageUsed(string $path): string
    {
        if (!is_dir($path)) {
            return '0 B';
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $this->formatFileSize($size);
    }

    /**
     * Format bytes to human-readable size.
     */
    public function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * Read projects.json.
     */
    protected function readProjectsJson(): array
    {
        if (!File::exists($this->projectsJsonPath)) {
            return [];
        }

        $content = json_decode(File::get($this->projectsJsonPath), true);

        return is_array($content) ? $content : [];
    }

    /**
     * Write projects.json.
     */
    protected function writeProjectsJson(array $data): void
    {
        File::put($this->projectsJsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Clear discovery cache.
     */
    public function clearCache(): void
    {
        Cache::forget('panel.discovered_projects');
    }
}
