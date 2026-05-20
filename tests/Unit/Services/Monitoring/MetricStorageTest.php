<?php

namespace Tests\Unit\Services\Monitoring;

use App\Services\Monitoring\MetricStorage;
use App\Services\Monitoring\Snapshot;
use Illuminate\Database\Capsule\Manager as Capsule;
use Tests\TestCase;

class MetricStorageTest extends TestCase
{
    private Capsule $capsule;

    private MetricStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // Build a standalone Capsule against :memory: SQLite so the test
        // doesn't touch the real storage/monitoring.sqlite path or any
        // Laravel-bound config; mirrors the production WAL/synchronous
        // pragmas as best we can on an in-memory database.
        $this->capsule = new Capsule;
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ], 'monitoring');
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->storage = new MetricStorage(
            $this->capsule->getConnection('monitoring'),
        );
        $this->storage->bootstrap();
    }

    public function test_bootstrap_creates_three_tables_idempotently(): void
    {
        // Second call must not throw — bootstrap is idempotent.
        $this->storage->bootstrap();

        $tables = $this->capsule->getConnection('monitoring')
            ->select("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        $names = array_column($tables, 'name');

        $this->assertContains('samples_raw', $names);
        $this->assertContains('samples_1m', $names);
        $this->assertContains('latest_snapshot', $names);
    }

    public function test_record_sample_flattens_numeric_metrics_and_stores_blob(): void
    {
        $snapshot = new Snapshot(
            ts: 1_700_000_000,
            entries: [
                'cpu' => [
                    'loadavg' => ['1m' => 0.5, '5m' => 0.6, '15m' => 0.7],
                    'cores' => 4,
                    'usage_pct_total' => 25.5,
                ],
                'memory' => [
                    'used_kb' => 4_000_000,
                    'swap_used_kb' => 100_000,
                ],
                'connections' => ['tcp_established' => 17],
                // Discrete state — must NOT land in samples_raw
                'process' => [['pid' => 1, 'name' => 'init']],
                'services' => [['unit' => 'nginx.service', 'status' => 'active']],
            ],
        );

        $this->storage->recordSample($snapshot);

        $rawRows = $this->capsule->getConnection('monitoring')
            ->table('samples_raw')->orderBy('metric')->get();

        $metrics = $rawRows->pluck('metric')->all();
        $this->assertContains('cpu.loadavg.1m', $metrics);
        $this->assertContains('cpu.loadavg.5m', $metrics);
        $this->assertContains('cpu.loadavg.15m', $metrics);
        $this->assertContains('cpu.usage_pct', $metrics);
        $this->assertContains('mem.used_kb', $metrics);
        $this->assertContains('mem.swap_used_kb', $metrics);
        $this->assertContains('net.tcp_established', $metrics);

        // Discrete state stays out of samples_raw.
        foreach ($metrics as $metric) {
            $this->assertStringNotContainsString('process', $metric);
            $this->assertStringNotContainsString('services', $metric);
        }

        // latest_snapshot stores the complete payload.
        $latest = $this->storage->latestSnapshot();
        $this->assertSame(1_700_000_000, $latest['ts']);
        $this->assertSame(17, $latest['entries']['connections']['tcp_established']);
        $this->assertSame('init', $latest['entries']['process'][0]['name']);
    }

    public function test_record_sample_skips_first_sample_rate_fields(): void
    {
        $snapshot = new Snapshot(
            ts: 1_700_000_000,
            entries: [
                'disk_io' => [
                    'first_sample' => true,
                    'read_bytes_per_sec' => null,
                    'write_bytes_per_sec' => null,
                ],
            ],
        );

        $this->storage->recordSample($snapshot);

        $count = $this->capsule->getConnection('monitoring')
            ->table('samples_raw')->count();
        $this->assertSame(0, $count, 'first_sample disk_io must not insert raw rate rows');
    }

    public function test_aggregate_minute_computes_avg_min_max(): void
    {
        $boundary = 1_700_000_060; // minute boundary
        // Insert 12 raw samples in the [1700000000..1700000055] window
        // for two metrics.
        for ($i = 0; $i < 12; $i++) {
            $ts = $boundary - 60 + ($i * 5);
            $this->capsule->getConnection('monitoring')->table('samples_raw')->insert([
                ['ts' => $ts, 'metric' => 'cpu.usage_pct', 'value' => 10.0 + $i],
                ['ts' => $ts, 'metric' => 'mem.used_kb', 'value' => 1_000_000.0 + ($i * 1000)],
            ]);
        }

        $this->storage->aggregateMinute($boundary);

        $rows = $this->capsule->getConnection('monitoring')
            ->table('samples_1m')
            ->where('ts', $boundary)
            ->get();

        $this->assertCount(2, $rows);

        $cpu = $rows->firstWhere('metric', 'cpu.usage_pct');
        // values 10..21 → avg 15.5, min 10, max 21
        $this->assertEqualsWithDelta(15.5, (float) $cpu->avg, 0.01);
        $this->assertSame(10.0, (float) $cpu->min);
        $this->assertSame(21.0, (float) $cpu->max);
    }

    public function test_prune_removes_only_rows_past_retention(): void
    {
        $now = 1_700_010_000;
        $cutoffRaw = $now - MetricStorage::RAW_RETENTION_SECONDS;
        $cutoffAgg = $now - MetricStorage::AGG_RETENTION_SECONDS;

        $this->capsule->getConnection('monitoring')->table('samples_raw')->insert([
            ['ts' => $cutoffRaw - 1, 'metric' => 'old', 'value' => 1],
            ['ts' => $cutoffRaw + 1, 'metric' => 'fresh', 'value' => 2],
            ['ts' => $now, 'metric' => 'now', 'value' => 3],
        ]);
        $this->capsule->getConnection('monitoring')->table('samples_1m')->insert([
            ['ts' => $cutoffAgg - 1, 'metric' => 'old_agg', 'avg' => 1, 'min' => 1, 'max' => 1],
            ['ts' => $cutoffAgg + 1, 'metric' => 'fresh_agg', 'avg' => 2, 'min' => 2, 'max' => 2],
        ]);

        $this->storage->prune($now);

        $remainingRaw = $this->capsule->getConnection('monitoring')
            ->table('samples_raw')->pluck('metric')->all();
        $this->assertEqualsCanonicalizing(['fresh', 'now'], $remainingRaw);

        $remainingAgg = $this->capsule->getConnection('monitoring')
            ->table('samples_1m')->pluck('metric')->all();
        $this->assertSame(['fresh_agg'], $remainingAgg);
    }

    public function test_range_raw_returns_ordered_keyed_results(): void
    {
        $rows = [
            ['ts' => 100, 'metric' => 'cpu', 'value' => 10],
            ['ts' => 110, 'metric' => 'cpu', 'value' => 20],
            ['ts' => 105, 'metric' => 'cpu', 'value' => 15],
            ['ts' => 110, 'metric' => 'mem', 'value' => 999], // different metric
        ];
        $this->capsule->getConnection('monitoring')->table('samples_raw')->insert($rows);

        $result = $this->storage->rangeRaw('cpu', 100, 110);

        $this->assertSame([100, 105, 110], array_keys($result));
        $this->assertSame(10.0, $result[100]);
        $this->assertSame(15.0, $result[105]);
        $this->assertSame(20.0, $result[110]);
    }

    public function test_range_minute_returns_keyed_avg_min_max(): void
    {
        $this->capsule->getConnection('monitoring')->table('samples_1m')->insert([
            ['ts' => 1000, 'metric' => 'cpu.usage_pct', 'avg' => 10, 'min' => 5, 'max' => 15],
            ['ts' => 1060, 'metric' => 'cpu.usage_pct', 'avg' => 20, 'min' => 10, 'max' => 30],
        ]);

        $result = $this->storage->rangeMinute('cpu.usage_pct', 1000, 1060);

        $this->assertCount(2, $result);
        $this->assertSame(['avg' => 10.0, 'min' => 5.0, 'max' => 15.0], $result[1000]);
        $this->assertSame(['avg' => 20.0, 'min' => 10.0, 'max' => 30.0], $result[1060]);
    }

    public function test_latest_snapshot_returns_null_when_empty(): void
    {
        $this->assertNull($this->storage->latestSnapshot());
    }
}
