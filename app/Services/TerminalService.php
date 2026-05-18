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
     * All other commands run via Symfony Process with security restrictions.
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

        // Block command chaining operators to prevent command injection
        // Only allow single commands (no semicolons, pipes, &&, ||, &, $, backticks, etc.)
        if ($this->containsCommandChaining($command)) {
            return [
                'output' => '',
                'error' => "[hermes] Command chaining (;, &&, ||, |, &) tidak diizinkan untuk keamanan.\n",
                'cwd' => $this->getCwd(),
                'exit_code' => 1,
            ];
        }

        // Block dangerous shell patterns
        if ($this->containsDangerousPatterns($command)) {
            return [
                'output' => '',
                'error' => "[hermes] Pola perintah berbahaya tidak diizinkan.\n",
                'cwd' => $this->getCwd(),
                'exit_code' => 1,
            ];
        }

        // Run via Process with individual arguments (not shell command)
        try {
            $cwd = $this->getCwd();
            $args = $this->parseCommandToArgs($command);

            if (empty($args)) {
                return [
                    'output' => '',
                    'error' => '',
                    'cwd' => $cwd,
                    'exit_code' => 0,
                ];
            }

            $process = new Process($args, $cwd);
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
     * Check if command contains command chaining operators.
     */
    protected function containsCommandChaining(string $command): bool
    {
        // Block: ; && || | & $() `` < > >> << and newlines in unexpected places
        $blockedPatterns = [
            '/[;\&\|]{2,}/',      // &&, ||, ;;
            '/[;\&\|]\s*[\&\|]/', // ;& ;| &| etc
            '/\$\(/',            // Command substitution $
            '/`[^`]+`/',          // Backtick command substitution
            '/\\\n/',            // Line continuation
            '/>/',                // Output redirect
            '/<\s*\//',          // Input redirect
        ];

        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for dangerous shell patterns.
     */
    protected function containsDangerousPatterns(string $command): bool
    {
        // Block dangerous commands that could be used for injection
        $blockedCommands = [
            '/\brm\s+-[rf]+\b/i',
            '/\bdel\s+\/[fqs]/i',
            '/\bmkfs\b/i',
            '/\bdd\s+/i',
            '/\bcat\s+/i',
            '/\bnc\s+/i',
            '/\bwget\s+/i',
            '/\bcurl\s+/i',
            '/\bnohup\s+/i',
            '/\beval\b/i',
            '/\bsource\b/i',
        ];

        foreach ($blockedCommands as $pattern) {
            if (preg_match($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse command string into individual arguments safely.
     * Uses shlex-style parsing to handle quoted arguments.
     */
    protected function parseCommandToArgs(string $command): array
    {
        $args = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($inQuote) {
                if ($char === $quoteChar) {
                    $inQuote = false;
                } else {
                    $current .= $char;
                }
            } elseif ($char === '"' || $char === "'") {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($char === ' ' || $char === '\t' || $char === '\n' || $char === '\r') {
                if ($current !== '') {
                    $args[] = $current;
                    $current = '';
                }
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $args[] = $current;
        }

        return $args;
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
