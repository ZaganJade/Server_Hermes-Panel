<?php

namespace Tests\Unit\Services;

use App\Services\ProjectService;
use App\Services\TerminalCommandPolicy;
use App\Services\TerminalService;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Sandbox-boundary tests for the legacy synchronous terminal.
 *
 * Phase 2 (HIGH-5) hardened TerminalService::handleCd to normalise both
 * sides of the comparison to forward slashes so a Windows realpath()
 * that returns mixed separators can no longer slip past the boundary.
 * These tests exercise the cd-only paths.
 */
class TerminalServiceSandboxTest extends TestCase
{
    private string $sandbox;

    private TerminalService $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/hermes-term-'.uniqid();
        mkdir($this->sandbox, 0755, true);
        mkdir($this->sandbox.'/inner', 0755, true);

        $project = new class($this->sandbox) extends ProjectService
        {
            public function __construct(public string $sandbox)
            {
                parent::__construct();
            }

            public function getActiveProject(): ?array
            {
                return [
                    'name' => 'sandbox',
                    'folder' => 'sandbox',
                    'path' => $this->sandbox,
                    'type' => 'generic',
                ];
            }
        };

        $this->terminal = new TerminalService($project, new TerminalCommandPolicy);

        // Force the terminal cwd into the sandbox so the cd tests have a
        // known starting point.
        Session::put('terminal_cwd', $this->sandbox);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->sandbox);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$entry);
        }
        @rmdir($path);
    }

    public function test_cd_into_sandbox_subdirectory_is_allowed(): void
    {
        $result = $this->terminal->execute('cd inner');

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('', $result['error']);
    }

    public function test_cd_to_absolute_path_is_rejected(): void
    {
        $result = $this->terminal->execute('cd /etc');

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('absolute paths', $result['error']);
    }

    public function test_cd_traversal_outside_sandbox_is_rejected(): void
    {
        $result = $this->terminal->execute('cd ../../..');

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('access denied', $result['error']);
    }

    public function test_cd_to_nonexistent_directory_is_rejected(): void
    {
        $result = $this->terminal->execute('cd does-not-exist');

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('tidak ada direktori', $result['error']);
    }

    public function test_cd_tilde_returns_to_project_root(): void
    {
        // Move into inner first, then `cd ~` should land back in the
        // sandbox root.
        $this->terminal->execute('cd inner');
        $result = $this->terminal->execute('cd ~');

        $this->assertSame(0, $result['exit_code']);
    }

    public function test_pwd_returns_current_cwd(): void
    {
        $result = $this->terminal->execute('pwd');

        $this->assertSame(0, $result['exit_code']);
        $this->assertNotEmpty(trim($result['output']));
    }
}
