<?php

namespace Tests\Unit\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Readers\MemoryReader;
use Tests\TestCase;

class MemoryReaderTest extends TestCase
{
    private string $procRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procRoot = sys_get_temp_dir().'/hermes-mem-'.uniqid();
        mkdir($this->procRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->procRoot.'/meminfo');
        @rmdir($this->procRoot);
        parent::tearDown();
    }

    public function test_parses_full_meminfo_fixture(): void
    {
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/meminfo', $this->procRoot.'/meminfo');
        $reader = new MemoryReader;

        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertSame(8_138_364, $result['total_kb']);
        $this->assertSame(1_234_567, $result['free_kb']);
        $this->assertSame(3_456_789, $result['available_kb']);
        $this->assertSame(234_567, $result['buffers_kb']);
        $this->assertSame(2_345_678, $result['cached_kb']);
        $this->assertSame(2_097_148, $result['swap_total_kb']);
        $this->assertSame(97_148, $result['swap_used_kb']); // 2_097_148 - 2_000_000

        // used_kb = 8_138_364 - 1_234_567 - 234_567 - 2_345_678 = 4_323_552
        $this->assertSame(4_323_552, $result['used_kb']);
    }

    public function test_falls_back_to_free_when_mem_available_missing(): void
    {
        file_put_contents($this->procRoot.'/meminfo', "MemTotal:        1000 kB\nMemFree:         500 kB\nBuffers:           50 kB\nCached:            50 kB\n");
        $reader = new MemoryReader;

        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertSame(500, $result['available_kb']);
        $this->assertSame(400, $result['used_kb']); // 1000 - 500 - 50 - 50
    }

    public function test_returns_null_swap_when_swap_fields_missing(): void
    {
        file_put_contents($this->procRoot.'/meminfo', "MemTotal:        1000 kB\nMemFree:         500 kB\n");
        $reader = new MemoryReader;

        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertNull($result['swap_total_kb']);
        $this->assertNull($result['swap_used_kb']);
    }

    public function test_returns_null_used_when_total_missing(): void
    {
        file_put_contents($this->procRoot.'/meminfo', "MemFree:         500 kB\n");
        $reader = new MemoryReader;

        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertNull($result['used_kb']);
        $this->assertNull($result['total_kb']);
    }
}
