<?php

namespace App\Services;

use Illuminate\Support\Facades\Session;
use Symfony\Component\Process\Process;

class TerminalService
{
    public function __construct(
        protected ProjectService $projectService,
        protected TerminalCommandPolicy $policy,
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
     * Execute a command. Handles `cd`/`clear`/`pwd` builtins; everything
     * else passes through TerminalCommandPolicy and then runs via
     * `bash -c` (Symfony Process). Chaining is allowed; substitution,
     * eval flags, and disallowed redirects are blocked by the policy.
     */
    public function execute(string $command): array
    {
        $command = trim($command);

        if ($command === '') {
            return [
                'output' => '',
                'error' => '',
                'cwd' => $this->getCwd(),
                'exit_code' => 0,
            ];
        }

        // Builtins: handled before the policy check so `cd`, `clear`,
        // and `pwd` always work, regardless of the rest of the policy.
        if (preg_match('/^cd(\s+(.*))?$/', $command, $matches)) {
            return $this->handleCd($matches[2] ?? '');
        }

        if ($command === 'clear' || $command === 'cls') {
            return [
                'output' => '',
                'error' => '',
                'cwd' => $this->getCwd(),
                'exit_code' => 0,
                'clear' => true,
            ];
        }

        if ($command === 'pwd') {
            return [
                'output' => $this->getCwd()."\n",
                'error' => '',
                'cwd' => $this->getCwd(),
                'exit_code' => 0,
            ];
        }

        if ($reason = $this->policy->reason($command)) {
            return [
                'output' => '',
                'error' => '[hermes] '.$reason."\n",
                'cwd' => $this->getCwd(),
                'exit_code' => 1,
            ];
        }

        try {
            $cwd = $this->getCwd();

            $process = new Process(['bash', '-c', $command], $cwd);
            $process->setTimeout((int) config('panel.terminal_timeout', 60));
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
                'error' => '[hermes] '.$e->getMessage()."\n",
                'cwd' => $this->getCwd(),
                'exit_code' => 1,
            ];
        }
    }

    /**
     * Handle `cd` command — update session cwd.
     * SECURITY: Denies absolute paths and enforces sandbox boundary.
     */
    protected function handleCd(string $target): array
    {
        $current = $this->getCwd();
        $project = $this->projectService->getActiveProject();
        $basePath = $project ? $project['path'] : base_path(config('panel.projects_dir', 'Project'));

        // Deny absolute paths entirely — prevents sandbox escape via /, /etc, /home, etc.
        if (! empty($target) && str_starts_with($target, '/')) {
            return [
                'output' => '',
                'error' => "cd: absolute paths are not permitted in panel terminal.\n",
                'cwd' => $current,
                'exit_code' => 1,
            ];
        }

        if (empty($target) || $target === '~') {
            $newCwd = $basePath;
        } elseif ($target === '-') {
            $newCwd = $current;
        } else {
            $target = trim($target, '"\'');
            $newCwd = $current.DIRECTORY_SEPARATOR.$target;
        }

        $resolved = realpath($newCwd);

        if (! $resolved || ! is_dir($resolved)) {
            return [
                'output' => '',
                'error' => "cd: {$target}: no such file or directory\n",
                'cwd' => $current,
                'exit_code' => 1,
            ];
        }

        // Enforce sandbox boundary: resolved path must be within allowed project directory.
        // This prevents escape via symlinks inside the project.
        // Cross-platform safe: realpath() can return either separator on
        // Windows depending on PHP build, so normalise both sides to `/`.
        $realBase = realpath($basePath);

        if (! $realBase) {
            return [
                'output' => '',
                'error' => "cd: access denied: path outside project boundary.\n",
                'cwd' => $current,
                'exit_code' => 1,
            ];
        }

        $resolvedNorm = str_replace('\\', '/', $resolved);
        $realBaseNorm = str_replace('\\', '/', $realBase);

        if (! str_starts_with($resolvedNorm, rtrim($realBaseNorm, '/').'/')) {
            return [
                'output' => '',
                'error' => "cd: access denied: path outside project boundary.\n",
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

            return $relative ? '~/'.str_replace('\\', '/', $relative) : '~';
        }

        // Shorten long paths
        return basename($cwd) ?: $cwd;
    }
}
