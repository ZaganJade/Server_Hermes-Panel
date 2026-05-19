<?php

namespace Tests\Unit\Services;

use App\Services\TerminalCommandPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TerminalCommandPolicyTest extends TestCase
{
    private TerminalCommandPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new TerminalCommandPolicy;
    }

    public function test_empty_command_is_allowed(): void
    {
        $this->assertTrue($this->policy->allow(''));
        $this->assertTrue($this->policy->allow('   '));
        $this->assertNull($this->policy->reason(''));
    }

    #[DataProvider('allowedCommandsProvider')]
    public function test_normal_commands_are_allowed(string $command): void
    {
        $this->assertTrue(
            $this->policy->allow($command),
            sprintf('Expected "%s" to be allowed but got: %s', $command, $this->policy->reason($command) ?? 'null')
        );
    }

    public static function allowedCommandsProvider(): array
    {
        return [
            'simple ls' => ['ls -la'],
            'pwd' => ['pwd'],
            'git status' => ['git status'],
            'composer install' => ['composer install'],
            'npm run build' => ['npm run build'],
            'php artisan migrate' => ['php artisan migrate'],
            'chained with &&' => ['php artisan migrate && php artisan db:seed'],
            'chained with ||' => ['cmd1 || cmd2'],
            'chained with ;' => ['echo a; echo b'],
            'piped' => ['cat file.txt | grep foo'],
            'redirect to /tmp file' => ['echo hi > /tmp/out.log'],
            'redirect to relative path' => ['echo hi > out.log'],
            'redirect to /dev/null' => ['npm run build > /dev/null 2>&1'],
            'append to /tmp' => ['echo line >> /tmp/notes'],
            'quoted argument with semicolon' => ['echo "a; b"'],
            'rm safe relative' => ['rm -rf node_modules'],
            'rm safe relative deep' => ['rm -rf storage/framework/cache/data'],
            'find with chmod' => ['find . -type f -name "*.php"'],
            'tail log' => ['tail -f storage/logs/laravel.log'],
        ];
    }

    #[DataProvider('blockedCommandsProvider')]
    public function test_dangerous_commands_are_blocked(string $command, string $expectedSnippet): void
    {
        $reason = $this->policy->reason($command);
        $this->assertNotNull(
            $reason,
            sprintf('Expected "%s" to be blocked but it was allowed', $command)
        );
        $this->assertStringContainsStringIgnoringCase(
            $expectedSnippet,
            $reason,
            sprintf("Reason for blocking '%s' did not match expected snippet '%s'. Got: %s", $command, $expectedSnippet, $reason)
        );
    }

    public static function blockedCommandsProvider(): array
    {
        return [
            // Code substitution
            'dollar paren' => ['echo $(whoami)', 'substitution'],
            'backtick' => ['echo `whoami`', 'backtick'],
            'dollar paren in middle' => ['ls $(pwd)', 'substitution'],

            // Background fork
            'trailing ampersand' => ['npm run dev &', 'background'],
            'trailing ampersand with spaces' => ['sleep 5  &', 'background'],

            // Newline injection
            'literal newline' => ["ls\necho hi", 'newline'],
            'carriage return' => ["ls\rwhoami", 'newline'],

            // Interactive bins
            'vim' => ['vim file.txt', 'interactive'],
            'top' => ['top', 'interactive'],
            'htop' => ['htop -d 1', 'interactive'],
            'sudo' => ['sudo ls /', 'interactive'],
            'su' => ['su root', 'interactive'],
            'mysql client' => ['mysql -u root -p', 'interactive'],
            'ssh' => ['ssh user@host', 'interactive'],
            'less' => ['less file.txt', 'interactive'],
            'man' => ['man bash', 'interactive'],

            // Recursive root deletes — short flags
            'rm -rf /' => ['rm -rf /', 'recursive'],
            'rm -rf /etc' => ['rm -rf /etc', 'recursive'],
            'rm -fr /home' => ['rm -fr /home', 'recursive'],
            'rm -Rf /var' => ['rm -Rf /var', 'recursive'],

            // Recursive root deletes — long flags
            'rm --recursive --force /' => ['rm --recursive --force /', 'recursive'],
            'rm --force --recursive /etc' => ['rm --force --recursive /etc', 'recursive'],

            // Disk wipe
            'dd if=/dev/zero' => ['dd if=/dev/zero of=/dev/sda', 'disk'],
            'mkfs.ext4' => ['mkfs.ext4 /dev/sda1', 'disk'],
            'mkfs plain' => ['mkfs /dev/sda', 'disk'],

            // Network listeners
            'nc -l' => ['nc -l 4444', 'listener'],
            'ncat' => ['ncat -l 4444', 'listener'],

            // Eval flags
            'php -r' => ['php -r "system(\'id\');"', 'inline code'],
            'python -c' => ['python -c "import os; os.system(\'id\')"', 'inline code'],
            'python3 -c' => ['python3 -c "print(1)"', 'inline code'],
            'node -e' => ['node -e "console.log(1)"', 'inline code'],
            'perl -e' => ['perl -e "print 1"', 'inline code'],
            'ruby -e' => ['ruby -e "puts 1"', 'inline code'],

            // Disallowed redirects
            'redirect to /etc' => ['echo x > /etc/passwd', 'not allowed'],
            'redirect to /root' => ['echo x > /root/.ssh/authorized_keys', 'not allowed'],
            'append to /home' => ['echo x >> /home/user/.bashrc', 'not allowed'],
            'input from /etc' => ['cat < /etc/shadow', 'not allowed'],
        ];
    }

    public function test_relative_path_redirects_are_allowed(): void
    {
        // Stays inside the cwd — TerminalService handles cwd sandbox separately
        $this->assertTrue($this->policy->allow('echo hi > local.log'));
        $this->assertTrue($this->policy->allow('npm run build > build.log 2>&1'));
        $this->assertTrue($this->policy->allow('echo line >> ./notes.md'));
    }

    public function test_fd_redirects_are_allowed(): void
    {
        // 2>&1 and similar fd redirects aren't filesystem paths
        $this->assertTrue($this->policy->allow('cmd 2>&1'));
        $this->assertTrue($this->policy->allow('cmd 1>&2'));
    }

    public function test_safe_recursive_delete_is_allowed(): void
    {
        // Targeting non-protected directories is fine
        $this->assertTrue($this->policy->allow('rm -rf node_modules'));
        $this->assertTrue($this->policy->allow('rm --recursive --force build/'));
        $this->assertTrue($this->policy->allow('rm -rf vendor && composer install'));
    }
}
