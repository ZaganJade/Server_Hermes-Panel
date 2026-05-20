<?php

namespace Tests\Unit\Services\Monitoring;

use App\Services\Monitoring\ProcResolver;
use Tests\TestCase;

class ProcResolverTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/hermes-procresolver-'.uniqid();
        mkdir($this->tmpRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpRoot);
        parent::tearDown();
    }

    public function test_explicit_constructor_keeps_supplied_roots(): void
    {
        $resolver = new ProcResolver('/proc', '/sys');

        $this->assertSame('/proc', $resolver->procRoot());
        $this->assertSame('/sys', $resolver->sysRoot());
    }

    public function test_proc_joins_paths_correctly(): void
    {
        $resolver = new ProcResolver('/host/proc', '/host/sys');

        $this->assertSame('/host/proc/loadavg', $resolver->proc('loadavg'));
        $this->assertSame('/host/proc/loadavg', $resolver->proc('/loadavg'));
        $this->assertSame('/host/proc/net/dev', $resolver->proc('net/dev'));
    }

    public function test_sys_joins_paths_correctly(): void
    {
        $resolver = new ProcResolver('/host/proc', '/host/sys');

        $this->assertSame('/host/sys/class/net', $resolver->sys('class/net'));
        $this->assertSame('/host/sys/class/net', $resolver->sys('/class/net'));
    }

    public function test_read_file_returns_content_for_existing_file(): void
    {
        file_put_contents($this->tmpRoot.'/loadavg', "0.10 0.20 0.30 1/200 12345\n");

        $resolver = new ProcResolver($this->tmpRoot, '/sys');

        $this->assertSame("0.10 0.20 0.30 1/200 12345\n", $resolver->readFile('loadavg'));
    }

    public function test_read_file_throws_with_full_path_when_missing(): void
    {
        $resolver = new ProcResolver($this->tmpRoot, '/sys');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(ltrim($this->tmpRoot, '/'), '/').'.+missing-file/');

        $resolver->readFile('missing-file');
    }

    public function test_autodetect_uses_proc_when_host_proc_missing(): void
    {
        // autodetect always returns either /host/proc or /proc as the
        // procRoot, and the matching /host/sys or /sys for sysRoot.
        // The actual paths only exist on Linux (host or container),
        // not on Windows dev boxes — we only assert the choice logic,
        // not filesystem reality.
        $resolver = ProcResolver::autodetect();

        $this->assertContains($resolver->procRoot(), ['/host/proc', '/proc']);
        $this->assertContains($resolver->sysRoot(), ['/host/sys', '/sys']);

        // /host/proc is picked iff it exists. Same for /host/sys.
        if (is_dir('/host/proc')) {
            $this->assertSame('/host/proc', $resolver->procRoot());
        } else {
            $this->assertSame('/proc', $resolver->procRoot());
        }

        if (is_dir('/host/sys')) {
            $this->assertSame('/host/sys', $resolver->sysRoot());
        } else {
            $this->assertSame('/sys', $resolver->sysRoot());
        }
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iter as $file) {
            $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
        }

        rmdir($path);
    }
}
