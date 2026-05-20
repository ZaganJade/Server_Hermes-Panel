<?php

namespace Tests\Unit\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Readers\ServiceReader;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class ServiceReaderTest extends TestCase
{
    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new Repository(new ArrayStore);
        config(['panel.monitoring.service_patterns' => [
            'nginx*', 'mysql*', 'redis*', 'docker',
        ]]);
    }

    public function test_systemd_discovery_filters_against_whitelist(): void
    {
        $reader = new FakeSystemdReader($this->cache, [
            'list-units' => "nginx.service     loaded active running\nmysql.service     loaded active running\nNetworkManager.service loaded active running\nbluetooth.service loaded inactive\n",
            'is-active.nginx.service' => 'active',
            'is-active.mysql.service' => 'active',
        ]);

        $rows = $reader->read(new ProcResolver(sys_get_temp_dir(), '/sys'));

        $units = array_column($rows, 'unit');
        $this->assertContains('nginx.service', $units);
        $this->assertContains('mysql.service', $units);
        $this->assertNotContains('NetworkManager.service', $units);
        $this->assertNotContains('bluetooth.service', $units);

        foreach ($rows as $row) {
            $this->assertSame('systemd', $row['detection']);
            $this->assertSame('active', $row['status']);
        }
    }

    public function test_falls_back_to_pgrep_when_systemctl_fails(): void
    {
        $reader = new FakePgrepReader($this->cache, [
            'pgrep.nginx' => 2,    // 2 nginx processes
            'pgrep.mysql' => 1,    // 1 mysql process
            'pgrep.redis' => 0,    // no redis
            'pgrep.docker' => 0,
        ]);

        $rows = $reader->read(new ProcResolver(sys_get_temp_dir(), '/sys'));

        $units = array_column($rows, 'unit');
        $this->assertContains('nginx', $units);
        $this->assertContains('mysql', $units);
        $this->assertNotContains('redis', $units);

        foreach ($rows as $row) {
            $this->assertSame('pgrep', $row['detection']);
            $this->assertSame('active', $row['status']);
        }
    }

    public function test_caches_discovery_for_60s(): void
    {
        $reader = new FakeSystemdReader($this->cache, [
            'list-units' => "nginx.service loaded active running\n",
            'is-active.nginx.service' => 'active',
        ]);

        // First call: triggers discovery + caches it.
        $reader->read(new ProcResolver(sys_get_temp_dir(), '/sys'));
        $cached = $this->cache->get(ServiceReader::CACHE_KEY);
        $this->assertNotNull($cached);
        $this->assertSame('nginx.service', $cached[0]['name']);

        // Second call: discovery returns null (simulate systemctl gone),
        // but cache is still warm so we still get nginx.
        $reader->setListUnitsOutput(null);
        $rows = $reader->read(new ProcResolver(sys_get_temp_dir(), '/sys'));
        $this->assertSame('nginx.service', $rows[0]['unit']);
    }
}

/**
 * Test double for the systemctl path.
 */
class FakeSystemdReader extends ServiceReader
{
    public function __construct(Repository $cache, private array $stubs)
    {
        parent::__construct($cache);
    }

    public function setListUnitsOutput(?string $output): void
    {
        $this->stubs['list-units'] = $output;
    }

    protected function discoverViaSystemd(): ?array
    {
        $output = $this->stubs['list-units'] ?? null;
        if ($output === null) {
            return null;
        }

        $patterns = $this->patterns();
        $units = [];
        foreach (preg_split('/\R/', trim($output)) as $line) {
            $unit = explode(' ', trim($line))[0] ?? '';
            if ($unit === '') {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern.'.service', $unit) || fnmatch($pattern, $unit)) {
                    $units[$unit] = ['name' => $unit, 'detection' => 'systemd'];
                    break;
                }
            }
        }

        return array_values($units);
    }

    protected function probeViaSystemctl(array $unit): array
    {
        return [
            'unit' => $unit['name'],
            'status' => $this->stubs['is-active.'.$unit['name']] ?? 'unknown',
            'detection' => 'systemd',
        ];
    }
}

/**
 * Test double for the pgrep fallback path.
 */
class FakePgrepReader extends ServiceReader
{
    public function __construct(Repository $cache, private array $pgrepCounts)
    {
        parent::__construct($cache);
    }

    protected function discoverViaSystemd(): ?array
    {
        return null; // Force pgrep fallback.
    }

    protected function discoverViaPgrep(): array
    {
        $units = [];
        foreach ($this->patterns() as $pattern) {
            $bare = rtrim($pattern, '*');
            if (($this->pgrepCounts['pgrep.'.$bare] ?? 0) > 0) {
                $units[] = ['name' => $bare, 'detection' => 'pgrep'];
            }
        }

        return $units;
    }

    protected function probeViaPgrep(array $unit): array
    {
        $count = (int) ($this->pgrepCounts['pgrep.'.$unit['name']] ?? 0);

        return [
            'unit' => $unit['name'],
            'status' => $count > 0 ? 'active' : 'inactive',
            'detection' => 'pgrep',
        ];
    }
}
