<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class FileService
{
    protected ProjectService $projectService;

    public function __construct(ProjectService $projectService)
    {
        $this->projectService = $projectService;
    }

    /**
     * Get the base path for file operations.
     */
    public function getBasePath(): string
    {
        $project = $this->projectService->getActiveProject();

        if ($project) {
            return $project['path'];
        }

        return base_path(config('panel.projects_dir', 'Project'));
    }

    /**
     * List directory contents.
     */
    public function listDirectory(string $relativePath = '/'): array
    {
        $fullPath = $this->resolvePath($relativePath);
        $basePath = $this->getBasePath();

        if (! $fullPath || ! is_dir($fullPath)) {
            return ['directories' => [], 'files' => [], 'currentPath' => '/', 'breadcrumbs' => [['name' => 'root', 'path' => '/']]];
        }

        $directories = [];
        $files = [];
        $entries = scandir($fullPath);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $fullPath.'/'.$entry;
            $relativeEntryPath = rtrim($relativePath, '/').'/'.$entry;

            if (is_dir($entryPath)) {
                $directories[] = [
                    'name' => $entry,
                    'path' => $relativeEntryPath,
                    'size' => '—',
                    'modified' => date('d M Y H:i', filemtime($entryPath)),
                    'permissions' => substr(sprintf('%o', fileperms($entryPath)), -4),
                    'type' => 'directory',
                ];
            } else {
                $files[] = [
                    'name' => $entry,
                    'path' => $relativeEntryPath,
                    'size' => $this->formatFileSize(filesize($entryPath)),
                    'modified' => date('d M Y H:i', filemtime($entryPath)),
                    'permissions' => substr(sprintf('%o', fileperms($entryPath)), -4),
                    'extension' => strtolower(pathinfo($entry, PATHINFO_EXTENSION)),
                    'type' => 'file',
                ];
            }
        }

        usort($directories, fn ($a, $b) => strcmp($a['name'], $b['name']));
        usort($files, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $activeProject = $this->projectService->getActiveProject();

        return [
            'directories' => $directories,
            'files' => $files,
            'currentPath' => $relativePath,
            'breadcrumbs' => $this->getBreadcrumbs($relativePath),
            'basePath' => $this->getBasePath(),
            'activeProject' => $activeProject ? [
                'name' => $activeProject['name'],
                'displayName' => $activeProject['display_name'] ?? $activeProject['name'],
                'path' => $activeProject['path'],
            ] : null,
        ];
    }

    /**
     * Get file content.
     */
    public function getFileContent(string $relativePath): array
    {
        $fullPath = $this->resolvePath($relativePath);

        if (! $fullPath || ! File::exists($fullPath) || is_dir($fullPath)) {
            return ['error' => 'File not found'];
        }

        $content = File::get($fullPath);
        $extension = $this->getFileExtension($relativePath);
        $editable = in_array($extension, config('panel.editable_extensions', []));

        return [
            'content' => $content,
            'extension' => $extension,
            'editable' => $editable,
            'size' => $this->formatFileSize(filesize($fullPath)),
        ];
    }

    /**
     * Save file content.
     */
    public function saveFile(string $relativePath, string $content): array
    {
        $fullPath = $this->resolvePath($relativePath);

        if (! $fullPath) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        try {
            File::put($fullPath, $content);

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create file or directory.
     *
     * `$relativePath` is the parent directory and must already exist inside
     * the sandbox. `$name` is validated against {@see assertSafeName()} —
     * no slashes, no `..`, no leading dot, no shell-meta chars.
     */
    public function create(string $relativePath, string $name, string $type): array
    {
        $fullPath = $this->resolvePath($relativePath);

        if (! $fullPath || ! is_dir($fullPath)) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        if ($error = $this->assertSafeName($name)) {
            return ['success' => false, 'error' => $error];
        }

        if ($type === 'file' && ! $this->isAllowedFilename($name)) {
            return ['success' => false, 'error' => 'This file extension is not allowed.'];
        }

        $targetPath = rtrim($fullPath, '/').'/'.$name;

        // Defence-in-depth: even though `$name` is name-only, re-check that
        // the resolved parent stays inside the sandbox.
        if (! $this->isUnderBasePath(dirname($targetPath))) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        try {
            if ($type === 'directory') {
                mkdir($targetPath, 0755, true);
            } else {
                File::put($targetPath, '');
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Rename file or directory.
     */
    public function rename(string $relativePath, string $newName): array
    {
        $fullPath = $this->resolvePath($relativePath);

        if (! $fullPath) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        if ($error = $this->assertSafeName($newName)) {
            return ['success' => false, 'error' => $error];
        }

        if (! is_dir($fullPath) && ! $this->isAllowedFilename($newName)) {
            return ['success' => false, 'error' => 'This file extension is not allowed.'];
        }

        $parentPath = dirname($fullPath);
        $newPath = $parentPath.'/'.$newName;

        if (! $this->isUnderBasePath($parentPath)) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        try {
            rename($fullPath, $newPath);

            return ['success' => true, 'new_path' => dirname($relativePath).'/'.$newName];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Move file or directory.
     */
    public function move(string $sourceRelativePath, string $targetRelativePath): array
    {
        $sourcePath = $this->resolvePath($sourceRelativePath);
        $targetDir = $this->resolvePath($targetRelativePath);

        if (! $sourcePath || ! $targetDir) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        $fileName = basename($sourcePath);
        $targetFullPath = rtrim($targetDir, '/').'/'.$fileName;

        try {
            rename($sourcePath, $targetFullPath);

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Copy file or directory.
     */
    public function copy(string $sourceRelativePath, string $targetRelativePath): array
    {
        $sourcePath = $this->resolvePath($sourceRelativePath);
        $targetDir = $this->resolvePath($targetRelativePath);

        if (! $sourcePath || ! $targetDir) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        $fileName = basename($sourcePath);
        $targetFullPath = rtrim($targetDir, '/').'/'.$fileName;

        try {
            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetFullPath);
            } else {
                File::copy($sourcePath, $targetFullPath);
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete file or directory.
     */
    public function delete(string $relativePath): array
    {
        $fullPath = $this->resolvePath($relativePath);

        if (! $fullPath) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        try {
            if (is_dir($fullPath)) {
                File::deleteDirectory($fullPath);
            } else {
                File::delete($fullPath);
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upload file.
     */
    public function upload(string $relativePath, $uploadedFile): array
    {
        $fullPath = $this->resolvePath($relativePath);

        if (! $fullPath || ! is_dir($fullPath)) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        $maxSize = config('panel.max_upload_size', 10485760);

        if ($uploadedFile->getSize() > $maxSize) {
            return ['success' => false, 'error' => 'File exceeds maximum upload size'];
        }

        $originalName = (string) $uploadedFile->getClientOriginalName();

        if ($error = $this->assertSafeName($originalName)) {
            return ['success' => false, 'error' => $error];
        }

        if (! $this->isAllowedFilename($originalName)) {
            return ['success' => false, 'error' => 'This file extension is not allowed.'];
        }

        try {
            $uploadedFile->move($fullPath, $originalName);

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Download file (returns response).
     */
    public function download(string $relativePath): ?array
    {
        $fullPath = $this->resolvePath($relativePath);

        if (! $fullPath || ! File::exists($fullPath)) {
            return null;
        }

        return [
            'path' => $fullPath,
            'name' => basename($fullPath),
            'is_directory' => is_dir($fullPath),
        ];
    }

    /**
     * Create zip archive of a directory.
     */
    public function zipDirectory(string $relativePath): ?string
    {
        $fullPath = $this->resolvePath($relativePath);

        if (! $fullPath || ! is_dir($fullPath)) {
            return null;
        }

        $zipFile = tempnam(sys_get_temp_dir(), 'hermes_zip_').'.zip';
        $zip = new \ZipArchive;

        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $this->zipRecursive($zip, $fullPath, basename($fullPath));
        $zip->close();

        return $zipFile;
    }

    /**
     * Search files by name.
     */
    public function search(string $relativePath, string $query, bool $recursive = false): array
    {
        $fullPath = $this->resolvePath($relativePath);

        if (! $fullPath || ! is_dir($fullPath)) {
            return [];
        }

        $results = [];
        $pattern = '/'.preg_quote($query, '/').'/i';

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if (preg_match($pattern, $file->getFilename())) {
                    $relPath = str_replace($this->getBasePath(), '', $file->getPathname());
                    $results[] = [
                        'name' => $file->getFilename(),
                        'path' => $relPath,
                        'is_directory' => $file->isDir(),
                    ];
                }
            }
        } else {
            foreach (scandir($fullPath) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (preg_match($pattern, $entry)) {
                    $relPath = rtrim($relativePath, '/').'/'.$entry;
                    $results[] = [
                        'name' => $entry,
                        'path' => $relPath,
                        'is_directory' => is_dir($fullPath.'/'.$entry),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Update file permissions.
     */
    public function updatePermissions(string $relativePath, int $permissions): array
    {
        $fullPath = $this->resolvePath($relativePath);

        if (! $fullPath) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        try {
            chmod($fullPath, $permissions);

            return ['success' => true, 'permissions' => substr(sprintf('%o', $permissions), -4)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Resolve a relative path to an absolute path, with traversal protection.
     */
    public function resolvePath(string $relativePath): ?string
    {
        $basePath = $this->getBasePath();

        if ($relativePath === '/' || $relativePath === '') {
            return $basePath;
        }

        $fullPath = $basePath.'/'.ltrim($relativePath, '/');
        $realPath = realpath($fullPath);
        $realBase = realpath($basePath);

        if (! $realPath || ! $realBase || ! str_starts_with($realPath, $realBase)) {
            return null;
        }

        return $realPath;
    }

    /**
     * Validate a single path segment supplied by the user (file or directory
     * name). Returns null when safe, or an error message when rejected.
     *
     * Rules:
     *   - non-empty, ASCII printable, max 255 chars
     *   - no path separators (/, \)
     *   - no traversal segment (..)
     *   - no leading dot (avoids accidentally writing .htaccess, .env, .git/*)
     *   - no shell metacharacters that surface in URLs / shells
     */
    public function assertSafeName(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return 'Name is required.';
        }

        if (strlen($name) > 255) {
            return 'Name is too long (max 255 characters).';
        }

        if (str_contains($name, '/') || str_contains($name, '\\') || $name === '..' || str_starts_with($name, '..')) {
            return 'Name must not contain path separators or traversal segments.';
        }

        if (str_starts_with($name, '.')) {
            return 'Names starting with "." are not allowed (use SSH for hidden files).';
        }

        if (preg_match('/[\x00-\x1F\x7F"\*\?<>\|;`$]/', $name)) {
            return 'Name contains illegal characters.';
        }

        return null;
    }

    /**
     * Whether a filename's extension is permitted for write/upload. Refuses
     * server-executable types unless explicitly allowed in panel config.
     *
     * Operators who genuinely need to upload a `.php` file (e.g. patching a
     * project) should use the SSH terminal or temporarily extend
     * `panel.upload_allowed_extensions`.
     */
    public function isAllowedFilename(string $name): bool
    {
        $name = strtolower($name);

        // Reject Apache/Nginx config bombs by full filename.
        $blockedNames = ['.htaccess', '.htpasswd', 'web.config', '.user.ini'];

        foreach ($blockedNames as $blocked) {
            if ($name === $blocked) {
                return false;
            }
        }

        // Default executable blocklist. Override via
        // config('panel.upload_blocked_extensions', […]) if needed.
        $defaultBlocked = [
            'php', 'phar', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8',
            'pl', 'cgi', 'jsp', 'asp', 'aspx', 'sh', 'bash', 'zsh',
            'exe', 'bat', 'cmd', 'com', 'msi', 'dll', 'vbs',
        ];

        $blocked = array_map('strtolower', array_filter(
            (array) config('panel.upload_blocked_extensions', $defaultBlocked),
            'is_string',
        ));

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($extension === '') {
            return true;     // extension-less files (LICENSE, README) are fine
        }

        return ! in_array($extension, $blocked, true);
    }

    /**
     * Whether the supplied absolute path resolves to somewhere inside the
     * sandbox base. Used as defence-in-depth against off-by-one mistakes in
     * the per-call validators.
     */
    protected function isUnderBasePath(string $absolutePath): bool
    {
        $realBase = realpath($this->getBasePath());
        $realTarget = realpath($absolutePath);

        if (! $realBase || ! $realTarget) {
            return false;
        }

        return str_starts_with($realTarget, $realBase);
    }

    /**
     * Get breadcrumbs for a path.
     */
    public function getBreadcrumbs(string $path): array
    {
        $parts = array_filter(explode('/', trim($path, '/')));
        $breadcrumbs = [['name' => 'root', 'path' => '/']];
        $current = '';

        foreach ($parts as $part) {
            $current .= '/'.$part;
            $breadcrumbs[] = ['name' => $part, 'path' => $current];
        }

        return $breadcrumbs;
    }

    protected function getFileExtension(string $path): string
    {
        $name = basename($path);

        if (str_ends_with($name, '.blade.php')) {
            return 'blade.php';
        }

        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    protected function formatFileSize(int $bytes): string
    {
        return $this->projectService->formatFileSize($bytes);
    }

    protected function copyDirectory(string $source, string $target): void
    {
        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        foreach (scandir($source) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $src = $source.'/'.$entry;
            $dst = $target.'/'.$entry;

            if (is_dir($src)) {
                $this->copyDirectory($src, $dst);
            } else {
                File::copy($src, $dst);
            }
        }
    }

    protected function zipRecursive(\ZipArchive $zip, string $dir, string $baseName): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            $zipPath = $baseName.'/'.$entry;

            if (is_dir($path)) {
                $zip->addEmptyDir($zipPath);
                $this->zipRecursive($zip, $path, $zipPath);
            } else {
                $zip->addFile($path, $zipPath);
            }
        }
    }
}
