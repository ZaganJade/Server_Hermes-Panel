<?php

namespace App\Services;

use Illuminate\Support\Facades\Session;
use Symfony\Component\Process\Process;

class TerminalService
{
    public function __construct(
        protected ProjectService $projectService,
    ) {}

    /**
     * Get the current working directory for the terminal session.
     * Falls back to active project path or panel root.
     */
    public function getCwd(): string
    {
        $sessionCwd = Session::get('terminal_cwd');

        if ($sessionCwd && is_dir($sessionCwd)) {
            return $sessionCwd;
        }

        $project = $this->projectService->getActiveProject();
        $cwd = $project ? $project['path'] : base_path(config('panel.projects_dir', 'Project'));

        Session::put('terminal_cwd', $cwd);
        return $cwd;
    }

    /**
     * Reset terminal cwd to active project / panel root.
     */
    public function resetCwd(): string
    {
        Session::forget('terminal_cwd');
        return $this->getCwd();
    }

    /**
     * Execute a command. Handles `cd` specially (mutates session cwd).
     * All other commands run via Symfony Process in current cwd.
     */
    public function execute(string $command): array
    {
        $command = trim($command);

        if (empty($command)) {
            return [
                'output' => '',
                'error' => '',
                'cwd' => $this->getCwd(),
                'exit_code' => 0,
            ];
        }

        // Handle `cd` specially
        if (preg_match('/^cd(\s+(.*))?$/', $command, $matches)) {
            return $this->handleCd($matches[2] ?? '');
        }

        // Handle `clear` / `cls`
        if ($command === 'clear' || $command === 'cls') {
            return [
                'output' => '',
                'error' => '',
                'cwd' => $this->getCwd(),
                'exit_code' => 0,
                'clear' => true,
            ];
        }

        // Handle `pwd`
        if ($command === 'pwd') {
            return [
                'output' => $this->getCwd() . "\n",
                'error' => '',
                'cwd' => $this->getCwd(),
                'exit_code' => 0,
            ];
        }

        // Block dangerous interactive commands gracefully
        $blocked = ['vim', 'vi', 'nano', 'emacs', 'top', 'htop', 'less', 'more', 'man', 'ssh', 'mysql', 'psql', 'sudo'];
        $firstWord = strtolower(strtok($command, ' '));
        if (in_array($firstWord, $blocked)) {
            return [
                'output' => '',
                'error' => "[hermes] '{$firstWord}' adalah perintah interaktif yang tidak didukung di terminal panel ini.\n[hermes] Gunakan SSH langsung untuk perintah interaktif.\n",
                'cwd' => $this->getCwd(),
                'exit_code' => 1,
            ];
        }

        // Run via Process
        try {
            $cwd = $this->getCwd();

            // Use shell wrapper to support pipes, redirects, env expansion
            $isWindows = strncasecmp(PHP_OS, 'WIN', 3) === 0;
            $process = $isWindows
                ? Process::fromShellCommandline($command, $cwd)
                : new Process(['/bin/sh', '-c', $command], $cwd);

            $process->setTimeout(60);
            $process->setEnv(['TERM' => 'dumb', 'NO_COLOR' => '1']);
            $process->run();

            return [
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
                'cwd' => $cwd,
                'exit_code' => $process->getExitCode(),
            ];
        } catch (\Throwable $e) {
            return [
                'output' => '',
                'error' => '[hermes] ' . $e->getMessage() . "\n",
                'cwd' => $this->getCwd(),
                'exit_code' => 1,
            ];
        }
    }

    /**
     * Handle `cd` command — update session cwd.
     */
    protected function handleCd(string $target): array
    {
        $current = $this->getCwd();

        if (empty($target) || $target === '~') {
            $project = $this->projectService->getActiveProject();
            $newCwd = $project ? $project['path'] : base_path();
        } elseif ($target === '-') {
            // cd to previous (not supported — fallback to current)
            $newCwd = $current;
        } elseif (str_starts_with($target, '/')) {
            $newCwd = $target;
        } else {
            $target = trim($target, '"\'');
            $newCwd = $current . DIRECTORY_SEPARATOR . $target;
        }

        $resolved = realpath($newCwd);

        if (!$resolved || !is_dir($resolved)) {
            return [
                'output' => '',
                'error' => "cd: {$target}: tidak ada direktori tersebut\n",
                'cwd' => $current,
                'exit_code' => 1,
            ];
        }

        Session::put('terminal_cwd', $resolved);

        return [
            'output' => '',
            'error' => '',
            'cwd' => $resolved,
            'exit_code' => 0,
        ];
    }

    /**
     * Get a friendly display path (relative to project if applicable).
     */
    public function getDisplayPath(?string $cwd = null): string
    {
        $cwd = $cwd ?? $this->getCwd();
        $project = $this->projectService->getActiveProject();

        if ($project && str_starts_with($cwd, $project['path'])) {
            $relative = substr($cwd, strlen($project['path']));
            $relative = trim($relative, DIRECTORY_SEPARATOR);
            return $relative ? '~/' . str_replace('\\', '/', $relative) : '~';
        }

        // Shorten long paths
        return basename($cwd) ?: $cwd;
    }
}
