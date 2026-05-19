<?php

namespace App\Services;

/**
 * Decides whether a shell command is allowed to run in the panel terminal.
 *
 * Policy is intentionally permissive about chaining (`;`, `&&`, `||`, `|`)
 * because real-world workflows depend on it (`composer install && php
 * artisan migrate`). What we block instead are constructs that turn a
 * benign-looking string into arbitrary code execution paths the operator
 * did not type, plus a small set of bins that have no place in a
 * non-interactive HTTP-driven terminal.
 *
 * Rule families:
 *   - Code substitution        $(…), `…`
 *   - Background fork          trailing &
 *   - Newline injection        \n / \r anywhere
 *   - Interactive bins         vim, top, mysql, sudo, …
 *   - Recursive root deletes   rm -rf / rm --recursive --force on root paths
 *   - Disk wipe primitives     dd, mkfs
 *   - Network listeners        nc, ncat
 *   - Eval flags               php -r, python -c, node -e, …
 *   - Untrusted FS redirects   >/etc, </root, …
 *
 * The policy is consulted by TerminalService::execute() and (for the v3.1
 * real-time terminal) TerminalSessionService::spawn(). Tests live in
 * tests/Unit/Services/TerminalCommandPolicyTest.php.
 */
class TerminalCommandPolicy
{
    /**
     * Bins blocked outright as the first word of a command. These either
     * need a real PTY (vim, top, mysql>) or grant privileges the panel
     * deliberately does not expose (sudo, su).
     */
    public const INTERACTIVE_BINS = [
        'vim', 'vi', 'nano', 'emacs',
        'less', 'more', 'man',
        'top', 'htop',
        'ssh', 'scp',
        'sudo', 'su',
        'mysql', 'psql',
    ];

    /**
     * Roots that recursive deletes must not target. Matched literally
     * after argument boundaries.
     */
    public const PROTECTED_ROOTS = [
        '/', '/etc', '/home', '/root', '/var', '/usr', '/bin', '/sbin', '/lib',
    ];

    /**
     * Decide whether a command is allowed.
     */
    public function allow(string $command): bool
    {
        return $this->reason($command) === null;
    }

    /**
     * Returns the rejection reason, or null when the command is allowed.
     * UI surfaces this string so the operator knows what to fix.
     */
    public function reason(string $command): ?string
    {
        $command = trim($command);

        if ($command === '') {
            return null;
        }

        if (preg_match("/[\r\n]/", $command)) {
            return 'Newline characters are not permitted in commands.';
        }

        if (preg_match('/\$\(/', $command)) {
            return 'Command substitution `$(…)` is not allowed.';
        }

        if (preg_match('/`[^`]*`/', $command)) {
            return 'Backtick command substitution is not allowed.';
        }

        if (preg_match('/&\s*$/', $command)) {
            return 'Trailing `&` (background fork) is not allowed.';
        }

        if ($bin = $this->firstWord($command)) {
            if (in_array($bin, self::INTERACTIVE_BINS, true)) {
                return sprintf(
                    "'%s' is an interactive program and not supported in the panel terminal. Use SSH for interactive work.",
                    $bin
                );
            }
        }

        if ($this->matchesAny($command, $this->evalFlagPatterns())) {
            return 'Inline code execution flags (-r/-c/-e for php/python/node/perl/ruby) are not allowed.';
        }

        if ($this->matchesAny($command, $this->recursiveRootDeletePatterns())) {
            return 'Recursive deletion of system roots is not allowed.';
        }

        if ($this->matchesAny($command, $this->diskWipePatterns())) {
            return 'Disk-wipe utilities (dd, mkfs) are not allowed.';
        }

        if ($this->matchesAny($command, $this->networkListenerPatterns())) {
            return 'Network listener utilities (nc, ncat) are not allowed.';
        }

        if ($redirect = $this->disallowedRedirect($command)) {
            return $redirect;
        }

        return null;
    }

    /**
     * Lowercased first token of the command (binary name).
     */
    protected function firstWord(string $command): ?string
    {
        $first = strtok($command, " \t");

        return $first === false ? null : strtolower($first);
    }

    protected function matchesAny(string $command, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursive-root-delete coverage. Matches both short flags (`-rf`,
     * `-fr`, `-Rf`) and long forms (`--recursive --force`,
     * `--force --recursive`), and any of the protected roots as target.
     *
     * Examples blocked:
     *   rm -rf /
     *   rm --recursive --force /etc
     *   rm -fR /var/lib
     *   rm --force --recursive /root/whatever
     */
    protected function recursiveRootDeletePatterns(): array
    {
        $rootAlt = implode('|', array_map(
            fn ($r) => preg_quote($r, '/'),
            self::PROTECTED_ROOTS,
        ));

        return [
            // rm -rf / rm -fr / rm -Rf etc.
            '/\brm\b[^\n]*\s-[rRfd]+\b[^\n]*\s('.$rootAlt.')(\s|$|\/)/i',
            // rm with both --recursive and --force long flags (any order)
            '/\brm\b(?=[^\n]*?--recursive\b)(?=[^\n]*?--force\b)[^\n]*\s('.$rootAlt.')(\s|$|\/)/i',
        ];
    }

    protected function diskWipePatterns(): array
    {
        return [
            '/\bdd\s+(if|of)=/i',
            '/\bmkfs\.[a-z0-9]+\b/i',
            '/\bmkfs\b/i',
        ];
    }

    protected function networkListenerPatterns(): array
    {
        return [
            '/\bnc\s+/i',
            '/\bncat\s+/i',
        ];
    }

    /**
     * Inline-code execution flags for popular interpreters. These bypass
     * any blocklist on individual commands by stuffing arbitrary code
     * into a single argument.
     */
    protected function evalFlagPatterns(): array
    {
        return [
            '/\bphp\s+-r\b/i',
            '/\bphp\s+-e\b/i',
            '/\bpython3?\s+-c\b/i',
            '/\bnode\s+-e\b/i',
            '/\bperl\s+-e\b/i',
            '/\bruby\s+-e\b/i',
        ];
    }

    /**
     * Catch redirects (>, >>, <) targeting absolute filesystem paths
     * that aren't safe scratch space. We allow /tmp, /var/tmp, /dev/null,
     * /dev/zero, /dev/stdout, /dev/stderr; everything else absolute is
     * blocked. Relative paths and stdin/stdout fd redirects (e.g. 2>&1)
     * pass through.
     */
    protected function disallowedRedirect(string $command): ?string
    {
        $allowedAbsoluteRedirects = [
            '/tmp', '/var/tmp',
            '/dev/null', '/dev/zero', '/dev/stdout', '/dev/stderr',
        ];

        $matched = preg_match_all('/[<>]+\s*(\/[A-Za-z0-9_.\/\-]+)/', $command, $matches);

        if (! $matched) {
            return null;
        }

        foreach ($matches[1] as $target) {
            $allowed = false;
            foreach ($allowedAbsoluteRedirects as $prefix) {
                if ($target === $prefix || str_starts_with($target, $prefix.'/')) {
                    $allowed = true;
                    break;
                }
            }

            if (! $allowed) {
                return sprintf('Redirecting to %s is not allowed (only /tmp, /var/tmp, and /dev/null|zero|stdout|stderr).', $target);
            }
        }

        return null;
    }
}
