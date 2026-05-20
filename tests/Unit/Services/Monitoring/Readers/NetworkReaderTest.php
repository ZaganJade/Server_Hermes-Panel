<?php

namespace Tests\Unit\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Readers\NetworkReader;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class NetworkReaderTest extends TestCase
{
    private string $procRoot;

    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procRoot = sys_get_temp_dir().'/hermes-net-'.uniqid();
        mkdir($this->procRoot.'/net', 0o755, true);
        $this->cache = new Repository(new ArrayStore);
    }

    protected function tearDown(): void
    {
        @unlink($this->procRoot.'/net/dev');
        @rmdir($this->procRoot.'/net');
        @rmdir($this->procRoot);
        parent::tearDown();
    }

    public function test_first_sample_marks_unknown_rate(): void
    {
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/net-dev-first', $this->procRoot.'/net/dev');
        $reader = new NetworkReader($this->cache);

        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        // lo skipped; eth0 + eth1 remain on first call.
        $ifaces = array_column($result, 'iface');
        $this->assertNotContains('lo', $ifaces);
        $this->assertContains('eth0', $ifaces);

        $eth0 = collect($result)->firstWhere('iface', 'eth0');
        $this->assertTrue($eth0['first_sample']);
        $this->assertNull($eth0['rx_bytes_per_sec']);
        $this->assertNull($eth0['tx_bytes_per_sec']);
    }

    public function test_second_sample_computes_rate_and_filters_inactive(): void
    {
        $reader = new NetworkReader($this->cache);

        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/net-dev-first', $this->procRoot.'/net/dev');
        $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $cached = $this->cache->get(NetworkReader::CACHE_KEY);
        $cached['ts'] = microtime(true) - 1.0;
        $this->cache->put(NetworkReader::CACHE_KEY, $cached, 60);

        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/net-dev-second', $this->procRoot.'/net/dev');
        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $ifaces = array_column($result, 'iface');
        // eth1 has zero traffic across both samples → filtered.
        $this->assertNotContains('eth1', $ifaces);
        $this->assertContains('eth0', $ifaces);

        $eth0 = collect($result)->firstWhere('iface', 'eth0');
        $this->assertArrayNotHasKey('first_sample', $eth0);
        // 100000 → 200000 over 1s = 100_000 bytes/sec
        $this->assertGreaterThan(90_000, $eth0['rx_bytes_per_sec']);
        $this->assertLessThan(110_000, $eth0['rx_bytes_per_sec']);
        // 50000 → 100000 over 1s = 50_000 bytes/sec
        $this->assertGreaterThan(45_000, $eth0['tx_bytes_per_sec']);
        $this->assertLessThan(55_000, $eth0['tx_bytes_per_sec']);
    }
}
