<?php

namespace Tests\Feature\Console;

use App\Events\MonitoringSnapshot;
use App\Services\Monitoring\MetricCollector;
use App\Services\Monitoring\MetricStorage;
use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MonitoringTickLoopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Quiet the dedicated tick log channel during tests.
        config([
            'logging.channels.monitoring-tick' => [
                'driver' => 'single',
                'path' => sys_get_temp_dir().'/hermes-monitoring-tick-test.log',
                'level' => 'debug',
            ],
        ]);

        // Empty threshold list keeps the test focused on tick mechanics.
        config(['panel.monitoring.thresholds' => []]);

        // Swap MetricCollector and MetricStorage with test-friendly fakes
        // so the loop runs against deterministic data + in-memory SQLite.
        $fakeReaders = [
            new class implements Reader
            {
                public function key(): string
                {
                    return 'cpu';
                }

                public function read(ProcResolver $proc): array
                {
                    return [
                        'loadavg' => ['1m' => 0.5, '5m' => 0.4, '15m' => 0.3],
                        'cores' => 2,
                        'usage_pct_total' => 12.5,
                    ];
                }
            },
        ];

        $this->app->singleton(ProcResolver::class, fn () => new ProcResolver(sys_get_temp_dir(), sys_get_temp_dir()));
        $this->app->singleton(MetricCollector::class, function ($app) use ($fakeReaders) {
            return new MetricCollector($fakeReaders, $app->make(ProcResolver::class));
        });

        // In-memory SQLite for storage so tests don't touch disk.
        $capsule = new Capsule;
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ], 'monitoring');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->app->singleton(MetricStorage::class, fn () => new MetricStorage(
            $capsule->getConnection('monitoring'),
        ));

        Event::fake([MonitoringSnapshot::class]);
    }

    public function test_command_finishes_after_max_iterations(): void
    {
        $this->artisan('hermes:monitoring-tick', ['--max-iterations' => 2, '--sleep' => 1])
            ->assertExitCode(0);
    }

    public function test_dispatches_monitoring_snapshot_event_after_each_iteration(): void
    {
        $this->artisan('hermes:monitoring-tick', ['--max-iterations' => 1, '--sleep' => 1])
            ->assertExitCode(0);

        Event::assertDispatched(MonitoringSnapshot::class, function (MonitoringSnapshot $event) {
            return ($event->payload['entries']['cpu']['cores'] ?? null) === 2
                && ($event->payload['entries']['cpu']['usage_pct_total'] ?? null) === 12.5;
        });
    }

    public function test_payload_includes_alerts_and_errors_keys(): void
    {
        $this->artisan('hermes:monitoring-tick', ['--max-iterations' => 1, '--sleep' => 1])
            ->assertExitCode(0);

        Event::assertDispatched(MonitoringSnapshot::class, function (MonitoringSnapshot $event) {
            return array_key_exists('ts', $event->payload)
                && array_key_exists('entries', $event->payload)
                && array_key_exists('alerts', $event->payload)
                && array_key_exists('errors', $event->payload);
        });
    }

    public function test_reader_failure_isolated_with_errors_populated(): void
    {
        $brokenReader = new class implements Reader
        {
            public function key(): string
            {
                return 'broken';
            }

            public function read(ProcResolver $proc): array
            {
                throw new \RuntimeException('boom');
            }
        };

        // Replace the collector with one that has the broken reader alongside
        // the good one already registered.
        $this->app->singleton(MetricCollector::class, function ($app) use ($brokenReader) {
            $good = new class implements Reader
            {
                public function key(): string
                {
                    return 'cpu';
                }

                public function read(ProcResolver $proc): array
                {
                    return ['cores' => 1];
                }
            };

            return new MetricCollector([$good, $brokenReader], $app->make(ProcResolver::class));
        });

        $this->artisan('hermes:monitoring-tick', ['--max-iterations' => 1, '--sleep' => 1])
            ->assertExitCode(0);

        Event::assertDispatched(MonitoringSnapshot::class, function (MonitoringSnapshot $event) {
            return array_key_exists('cpu', $event->payload['entries'])
                && ! array_key_exists('broken', $event->payload['entries'])
                && ($event->payload['errors']['broken'] ?? null) === 'boom';
        });
    }

    public function test_storage_bootstrap_runs_on_handle(): void
    {
        // Storage is bound to in-memory SQLite; bootstrap should create
        // the three monitoring tables before the first tick writes.
        $this->artisan('hermes:monitoring-tick', ['--max-iterations' => 1, '--sleep' => 1])
            ->assertExitCode(0);

        $tables = app(MetricStorage::class)->latestSnapshot();
        // After 1 iteration the latest_snapshot row is populated.
        $this->assertNotNull($tables);
        $this->assertSame(2, $tables['entries']['cpu']['cores'] ?? null);
    }
}
