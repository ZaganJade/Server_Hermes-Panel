<?php

namespace Tests\Unit\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Readers\CpuReader;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class CpuReaderTest extends TestCase
{
    private string $procRoot;

    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procRoot = sys_get_temp_dir().'/hermes-cpu-'.uniqid();
        mkdir($this->procRoot, 0o755, true);
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/loadavg', $this->procRoot.'/loadavg');
        $this->cache = new Repository(new ArrayStore);
    }

    protected function tearDown(): void
    {
        @unlink($this->procRoot.'/loadavg');
        @unlink($this->procRoot.'/stat');
        @rmdir($this->procRoot);
        parent::tearDown();
    }

    public function test_first_sample_marks_unknown_delta(): void
    {
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/stat-first', $this->procRoot.'/stat');
        $reader = new CpuReader($this->cache);

        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertTrue($result['first_sample'] ?? false);
        $this->assertNull($result['usage_pct_total']);
        $this->assertSame(['1m' => 0.10, '5m' => 0.20, '15m' => 0.30], $result['loadavg']);
        $this->assertSame(3, $result['cores']);
    }

    public function test_second_sample_computes_busy_percentage(): void
    {
        $reader = new CpuReader($this->cache);

        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/stat-first', $this->procRoot.'/stat');
        $reader->read(new ProcResolver($this->procRoot, '/sys'));

        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/stat-second', $this->procRoot.'/stat');
        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertArrayNotHasKey('first_sample', $result);
        $this->assertNotNull($result['usage_pct_total']);

        // First fixture total ticks: 3500+200+1200+90000+500+0+100 = 95500, idle = 90500
        // Second fixture total ticks:               3700+200+1300+90500+510+0+110 = 96320, idle = 91010
        // Busy delta = (96320-95500) - (91010-90500) = 820 - 510 = 310
        // Busy pct = 310 / 820 * 100 = 37.80
        $this->assertEqualsWithDelta(37.80, $result['usage_pct_total'], 0.5);

        $this->assertCount(3, $result['per_core']);
        foreach ($result['per_core'] as $core) {
            $this->assertGreaterThanOrEqual(0, $core);
            $this->assertLessThanOrEqual(100, $core);
        }
    }

    public function test_loadavg_is_parsed_independent_of_stat(): void
    {
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/stat-first', $this->procRoot.'/stat');
        $reader = new CpuReader($this->cache);
        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertSame(0.10, $result['loadavg']['1m']);
        $this->assertSame(0.20, $result['loadavg']['5m']);
        $this->assertSame(0.30, $result['loadavg']['15m']);
    }

    public function test_core_count_matches_fixture(): void
    {
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/stat-first', $this->procRoot.'/stat');
        $reader = new CpuReader($this->cache);
        $result = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        // Fixture has cpu0, cpu1, cpu2 → 3 cores.
        $this->assertSame(3, $result['cores']);
        $this->assertCount(3, $result['per_core']);
    }
}
