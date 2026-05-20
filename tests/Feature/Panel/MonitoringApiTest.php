<?php

namespace Tests\Feature\Panel;

use App\Services\Monitoring\MetricStorage;
use App\Services\Monitoring\Snapshot;
use Illuminate\Database\Capsule\Manager as Capsule;
use Tests\TestCase;

class MonitoringApiTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.auth_enabled' => true,
            'panel.dev_bypass' => false,
            'panel.password' => 'test-password-secret',
            'panel.monitoring.thresholds' => [],
        ]);

        // In-memory SQLite for storage so the API tests never touch disk.
        $this->capsule = new Capsule;
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ], 'monitoring');
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->app->singleton(MetricStorage::class, fn () => new MetricStorage(
            $this->capsule->getConnection('monitoring'),
        ));
        app(MetricStorage::class)->bootstrap();
    }

    public function test_snapshot_requires_authentication(): void
    {
        $this->getJson('/panel/api/monitoring/snapshot')->assertStatus(401);
    }

    public function test_snapshot_returns_empty_with_storage_error_when_no_samples(): void
    {
        $this->withAuth()
            ->getJson('/panel/api/monitoring/snapshot')
            ->assertOk()
            ->assertJsonPath('ts', null)
            ->assertJsonPath('errors.storage', 'No samples yet — tick-loop may still be starting.');
    }

    public function test_snapshot_returns_latest_payload_after_record(): void
    {
        $snap = new Snapshot(
            ts: 1_700_000_000,
            entries: [
                'cpu' => ['cores' => 2, 'usage_pct_total' => 12.5],
                'services' => [['unit' => 'nginx.service', 'status' => 'active']],
            ],
        );
        app(MetricStorage::class)->recordSample($snap);

        $this->withAuth()
            ->getJson('/panel/api/monitoring/snapshot')
            ->assertOk()
            ->assertJsonPath('entries.cpu.cores', 2)
            ->assertJsonPath('entries.services.0.unit', 'nginx.service');
    }

    public function test_series_validates_metrics_array(): void
    {
        $this->withAuth()
            ->getJson('/panel/api/monitoring/series')
            ->assertStatus(422);

        $this->withAuth()
            ->getJson('/panel/api/monitoring/series?'.http_build_query([
                'metrics' => array_fill(0, 25, 'cpu.usage_pct'),
                'window' => '15m',
            ]))
            ->assertStatus(422);
    }

    public function test_series_returns_raw_resolution_for_short_windows(): void
    {
        $this->withAuth()
            ->getJson('/panel/api/monitoring/series?'.http_build_query([
                'metrics' => ['cpu.usage_pct'],
                'window' => '15m',
            ]))
            ->assertOk()
            ->assertJsonPath('resolution', 'raw')
            ->assertJsonPath('window', '15m');
    }

    public function test_series_returns_minute_resolution_for_long_windows(): void
    {
        $this->withAuth()
            ->getJson('/panel/api/monitoring/series?'.http_build_query([
                'metrics' => ['cpu.usage_pct'],
                'window' => '24h',
            ]))
            ->assertOk()
            ->assertJsonPath('resolution', '1m')
            ->assertJsonPath('window', '24h');
    }

    public function test_services_endpoint_returns_list_from_latest_snapshot(): void
    {
        $snap = new Snapshot(
            ts: 1_700_000_000,
            entries: [
                'services' => [
                    ['unit' => 'nginx.service', 'status' => 'active'],
                    ['unit' => 'mysql.service', 'status' => 'inactive'],
                ],
            ],
        );
        app(MetricStorage::class)->recordSample($snap);

        $this->withAuth()
            ->getJson('/panel/api/monitoring/services')
            ->assertOk()
            ->assertJsonPath('0.unit', 'nginx.service')
            ->assertJsonPath('1.status', 'inactive');
    }

    public function test_processes_endpoint_returns_top_list(): void
    {
        $snap = new Snapshot(
            ts: 1_700_000_000,
            entries: [
                'process' => [
                    ['pid' => 1, 'name' => 'init', 'rss_kb' => 1000, 'cpu_pct' => 0.1],
                ],
            ],
        );
        app(MetricStorage::class)->recordSample($snap);

        $this->withAuth()
            ->getJson('/panel/api/monitoring/processes')
            ->assertOk()
            ->assertJsonPath('0.pid', 1);
    }

    public function test_alerts_endpoint_returns_active_array(): void
    {
        $this->withAuth()
            ->getJson('/panel/api/monitoring/alerts')
            ->assertOk()
            ->assertJsonStructure(['active']);
    }

    public function test_refresh_services_clears_cache(): void
    {
        cache()->put('hermes:monitoring:services:units', [['name' => 'stale']], 60);

        $this->withAuth()
            ->postJson('/panel/api/monitoring/services/refresh')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull(cache()->get('hermes:monitoring:services:units'));
    }

    private function withAuth(): self
    {
        return $this->withHeaders([
            'X-Panel-Password' => 'test-password-secret',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ]);
    }
}
