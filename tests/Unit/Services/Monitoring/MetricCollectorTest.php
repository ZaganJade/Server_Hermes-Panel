<?php

namespace Tests\Unit\Services\Monitoring;

use App\Services\Monitoring\MetricCollector;
use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;
use App\Services\Monitoring\Snapshot;
use Tests\TestCase;

class MetricCollectorTest extends TestCase
{
    private ProcResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProcResolver(sys_get_temp_dir(), sys_get_temp_dir());
    }

    public function test_sample_invokes_every_registered_reader(): void
    {
        $a = $this->fakeReader('foo', ['value' => 1]);
        $b = $this->fakeReader('bar', ['value' => 2]);

        $collector = new MetricCollector([$a, $b], $this->resolver);
        $snapshot = $collector->sample();

        $this->assertInstanceOf(Snapshot::class, $snapshot);
        $this->assertSame(1, $snapshot->entries['foo']['value']);
        $this->assertSame(2, $snapshot->entries['bar']['value']);
        $this->assertSame([], $snapshot->errors);
    }

    public function test_one_reader_throwing_is_captured_in_errors(): void
    {
        $good = $this->fakeReader('good', ['value' => 'ok']);
        $bad = new class implements Reader
        {
            public function key(): string
            {
                return 'bad';
            }

            public function read(ProcResolver $proc): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $collector = new MetricCollector([$good, $bad], $this->resolver);
        $snapshot = $collector->sample();

        $this->assertArrayHasKey('good', $snapshot->entries);
        $this->assertArrayNotHasKey('bad', $snapshot->entries);
        $this->assertArrayHasKey('bad', $snapshot->errors);
        $this->assertSame('boom', $snapshot->errors['bad']);
    }

    public function test_ordering_matches_registration(): void
    {
        $readers = [
            $this->fakeReader('first', []),
            $this->fakeReader('second', []),
            $this->fakeReader('third', []),
        ];

        $collector = new MetricCollector($readers, $this->resolver);
        $snapshot = $collector->sample();

        $this->assertSame(['first', 'second', 'third'], array_keys($snapshot->entries));
    }

    public function test_empty_iterable_yields_empty_entries(): void
    {
        $collector = new MetricCollector([], $this->resolver);
        $snapshot = $collector->sample();

        $this->assertSame([], $snapshot->entries);
        $this->assertSame([], $snapshot->errors);
        $this->assertGreaterThan(0, $snapshot->ts);
    }

    private function fakeReader(string $key, array $payload): Reader
    {
        return new class($key, $payload) implements Reader
        {
            public function __construct(private string $key, private array $payload) {}

            public function key(): string
            {
                return $this->key;
            }

            public function read(ProcResolver $proc): array
            {
                return $this->payload;
            }
        };
    }
}
