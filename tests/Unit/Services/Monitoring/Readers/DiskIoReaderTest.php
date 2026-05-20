<?php

namespace Tests\Unit\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Readers\DiskIoReader;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class DiskIoReaderTest extends TestCase
{
    private string $procRoot;

    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procRoot = sys_get_temp_dir().'/hermes-diskio-'.uniqid();
        mkdir($this->procRoot, 0o755, true);
        $this->cache = new Repository(new ArrayStore);
    }

    protected function tearDown(): void
    {
        @unlink($this->procRoot.'/diskstats');
        @rmdir($this->procRoot);
        parent::tearDown();
    }

    public function test_first_sample_returns_null_rates_with_flag(): void
    {
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/diskstats-first', $this->procRoot.'/diskstats');
        $reader = new DiskIoReader($this->cache);

        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertTrue($result['first_sample']);
        $this->assertNull($result['read_bytes_per_sec']);
        $this->assertNull($result['write_bytes_per_sec']);
        // sda + sda1 only — loop0 and ram0 filtered.
        $this->assertCount(2, $result['per_device']);
        $this->assertSame(['sda', 'sda1'], array_column($result['per_device'], 'device'));
    }

    public function test_second_sample_computes_bytes_per_sec(): void
    {
        $reader = new DiskIoReader($this->cache);

        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/diskstats-first', $this->procRoot.'/diskstats');
        $reader->read(new ProcResolver($this->procRoot, '/sys'));

        // Manually backdate the cache timestamp 1 second ago to make the
        // delta calculation deterministic.
        $cached = $this->cache->get(DiskIoReader::CACHE_KEY);
        $cached['ts'] = microtime(true) - 1.0;
        $this->cache->put(DiskIoReader::CACHE_KEY, $cached, 60);

        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/diskstats-second', $this->procRoot.'/diskstats');
        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertArrayNotHasKey('first_sample', $result);
        // sda: sectors_read 600000 → 700000 = 100000 sectors × 512 = 51_200_000 bytes / 1s
        // sda1: sectors_read 5000 → 5500 = 500 × 512 = 256_000 bytes
        // total ≈ 51_456_000 (give or take elapsed jitter)
        $this->assertGreaterThan(50_000_000, $result['read_bytes_per_sec']);
        $this->assertLessThan(60_000_000, $result['read_bytes_per_sec']);
    }

    public function test_loop_and_ram_devices_are_skipped(): void
    {
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/diskstats-first', $this->procRoot.'/diskstats');
        $reader = new DiskIoReader($this->cache);

        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));
        $devices = array_column($result['per_device'], 'device');

        $this->assertNotContains('loop0', $devices);
        $this->assertNotContains('ram0', $devices);
    }
}
