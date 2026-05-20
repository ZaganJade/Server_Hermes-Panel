<?php

namespace Tests\Unit\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Readers\UptimeReader;
use Tests\TestCase;

class UptimeReaderTest extends TestCase
{
    private string $procRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procRoot = sys_get_temp_dir().'/hermes-uptime-'.uniqid();
        mkdir($this->procRoot, 0o755, true);
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/uptime', $this->procRoot.'/uptime');
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/stat-first', $this->procRoot.'/stat');
    }

    protected function tearDown(): void
    {
        @unlink($this->procRoot.'/uptime');
        @unlink($this->procRoot.'/stat');
        @rmdir($this->procRoot);
        parent::tearDown();
    }

    public function test_parses_uptime_seconds_and_boot_time(): void
    {
        $reader = new UptimeReader;

        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        // Fixture uptime: 12345.67 → integer 12345
        $this->assertSame(12_345, $result['uptime_seconds']);

        // Fixture stat btime line: 1715000000
        $this->assertSame(1_715_000_000, $result['boot_time_unix']);
    }

    public function test_returns_zero_boot_time_when_btime_missing(): void
    {
        file_put_contents($this->procRoot.'/stat', "cpu  100 0 100 0 0 0 0 0 0 0\n");
        $reader = new UptimeReader;

        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertSame(0, $result['boot_time_unix']);
        $this->assertSame(12_345, $result['uptime_seconds']);
    }
}
