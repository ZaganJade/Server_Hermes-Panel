<?php

namespace Tests\Unit\Services\Monitoring;

use App\Events\MonitoringAlert;
use App\Services\Monitoring\Snapshot;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ThresholdEvaluatorTest extends TestCase
{
    private Repository $cache;

    private ThresholdEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new Repository(new ArrayStore);

        // Compact rule set so each test asserts behavior on one rule at a time.
        config(['panel.monitoring.thresholds' => [
            [
                'id' => 'cpu_load',
                'metric' => 'cpu.loadavg.1m',
                'warning_factor_per_core' => 1.5,
                'critical_factor_per_core' => 2.0,
                'sustained_seconds' => 60,
            ],
            [
                'id' => 'mem_used',
                'metric' => 'memory.used_kb',
                'warning_pct' => 90,
                'critical_pct' => 95,
            ],
            [
                'id' => 'disk_used',
                'metric' => 'disk.*.used_pct',
                'warning' => 90,
                'critical' => 95,
            ],
            [
                'id' => 'service_down',
                'metric' => 'services',
                'critical_when' => 'expected_active_but_not',
            ],
        ]]);

        // Fake events BEFORE constructing the evaluator so it captures
        // the test dispatcher rather than the real one.
        Event::fake([MonitoringAlert::class]);
        $this->evaluator = new ThresholdEvaluator($this->cache, app('events'));
    }

    public function test_first_cross_emits_no_event_for_sustained_rule(): void
    {
        // CPU 5.0 on a 2-core box → critical (>= 2 cores * 2.0 = 4.0)
        // but sustained_seconds=60 hasn't elapsed yet → no emission.
        $snap = $this->buildCpuSnapshot(loadavg1m: 5.0, cores: 2, ts: 1000);

        $alerts = $this->evaluator->evaluate($snap);

        $this->assertEmpty($alerts);
        Event::assertNotDispatched(MonitoringAlert::class);
    }

    public function test_sustained_breach_emits_after_window_elapses(): void
    {
        $first = $this->buildCpuSnapshot(loadavg1m: 5.0, cores: 2, ts: 1000);
        $second = $this->buildCpuSnapshot(loadavg1m: 5.0, cores: 2, ts: 1000 + 90);

        $this->evaluator->evaluate($first);
        $alerts = $this->evaluator->evaluate($second);

        $this->assertCount(1, $alerts);
        $this->assertSame('cpu_load', $alerts[0]['rule_id']);
        $this->assertSame('critical', $alerts[0]['level']);
        Event::assertDispatched(MonitoringAlert::class);
    }

    public function test_recovery_emits_clear_event(): void
    {
        // Force the rule into critical state in the cache.
        $this->cache->put('hermes:monitoring:threshold:mem_used', 'critical', 600);

        // Snapshot reports 50% memory used → ok.
        $snap = $this->buildMemSnapshot(usedKb: 500_000, totalKb: 1_000_000, ts: 1000);

        $alerts = $this->evaluator->evaluate($snap);

        $clear = collect($alerts)->firstWhere('rule_id', 'mem_used');
        $this->assertNotNull($clear);
        $this->assertSame('ok', $clear['level']);
    }

    public function test_warning_to_critical_transition_emits(): void
    {
        $this->cache->put('hermes:monitoring:threshold:mem_used', 'warning', 600);

        // 96% used → critical.
        $snap = $this->buildMemSnapshot(usedKb: 960_000, totalKb: 1_000_000, ts: 1000);

        $alerts = $this->evaluator->evaluate($snap);

        $this->assertCount(1, $alerts);
        $this->assertSame('critical', $alerts[0]['level']);
    }

    public function test_no_emission_when_level_unchanged(): void
    {
        $this->cache->put('hermes:monitoring:threshold:mem_used', 'warning', 600);

        // 92% used → still warning, no transition.
        $snap = $this->buildMemSnapshot(usedKb: 920_000, totalKb: 1_000_000, ts: 1000);

        $alerts = $this->evaluator->evaluate($snap);

        $this->assertEmpty($alerts);
    }

    public function test_disk_glob_uses_worst_mount(): void
    {
        $snap = new Snapshot(
            ts: 1000,
            entries: [
                'disk_usage' => [
                    ['mount' => '/', 'used_pct' => 50.0],
                    ['mount' => '/var', 'used_pct' => 96.0],   // critical
                    ['mount' => '/home', 'used_pct' => 92.0],   // warning
                ],
            ],
        );

        $alerts = $this->evaluator->evaluate($snap);

        $disk = collect($alerts)->firstWhere('rule_id', 'disk_used');
        $this->assertSame('critical', $disk['level']);
    }

    public function test_service_down_only_fires_after_seen_active(): void
    {
        // First snapshot: nginx active → expected set populated.
        $first = new Snapshot(
            ts: 1000,
            entries: [
                'services' => [
                    ['unit' => 'nginx.service', 'status' => 'active', 'detection' => 'systemd'],
                ],
            ],
        );
        $this->evaluator->evaluate($first);

        // Second: nginx is inactive — must trigger critical.
        $second = new Snapshot(
            ts: 1100,
            entries: [
                'services' => [
                    ['unit' => 'nginx.service', 'status' => 'inactive', 'detection' => 'systemd'],
                ],
            ],
        );
        $alerts = $this->evaluator->evaluate($second);

        $svc = collect($alerts)->firstWhere('rule_id', 'service_down');
        $this->assertNotNull($svc);
        $this->assertSame('critical', $svc['level']);
    }

    public function test_active_alerts_returns_non_ok_rule_states(): void
    {
        $this->cache->put('hermes:monitoring:threshold:cpu_load', 'warning', 600);
        $this->cache->put('hermes:monitoring:threshold:mem_used', 'critical', 600);
        $this->cache->put('hermes:monitoring:threshold:disk_used', 'ok', 600);

        $active = $this->evaluator->activeAlerts();

        $ids = array_column($active, 'rule_id');
        $this->assertContains('cpu_load', $ids);
        $this->assertContains('mem_used', $ids);
        $this->assertNotContains('disk_used', $ids);
    }

    private function buildCpuSnapshot(float $loadavg1m, int $cores, int $ts): Snapshot
    {
        return new Snapshot(
            ts: $ts,
            entries: [
                'cpu' => [
                    'loadavg' => ['1m' => $loadavg1m, '5m' => 0, '15m' => 0],
                    'cores' => $cores,
                    'usage_pct_total' => 0,
                ],
            ],
        );
    }

    private function buildMemSnapshot(int $usedKb, int $totalKb, int $ts): Snapshot
    {
        return new Snapshot(
            ts: $ts,
            entries: [
                'memory' => [
                    'total_kb' => $totalKb,
                    'used_kb' => $usedKb,
                ],
            ],
        );
    }
}
