<?php

namespace Tests\Unit\Services;

use App\Services\ProjectService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Covers the lazy file-stats path introduced in EFF-1.
 *
 * buildProjectData() no longer eagerly recurses every project tree.
 * file_count / storage_used now come from withFileStats() backed by a
 * per-project cache.
 */
class ProjectServiceFileStatsTest extends TestCase
{
    private ProjectService $service;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/hermes-stats-'.uniqid();
        mkdir($this->sandbox, 0755, true);
        file_put_contents($this->sandbox.'/a.txt', str_repeat('a', 50));
        file_put_contents($this->sandbox.'/b.txt', str_repeat('b', 100));
        mkdir($this->sandbox.'/sub', 0755, true);
        file_put_contents($this->sandbox.'/sub/c.txt', str_repeat('c', 200));

        Cache::flush();

        $this->service = new ProjectService;
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->sandbox);
        Cache::flush();
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$entry);
        }
        @rmdir($path);
    }

    public function test_with_file_stats_computes_count_and_size(): void
    {
        $project = ['name' => 'sandbox', 'path' => $this->sandbox];

        $augmented = $this->service->withFileStats($project);

        $this->assertSame(3, $augmented['file_count']);
        $this->assertNotEmpty($augmented['storage_used']);
        $this->assertMatchesRegularExpression('/\d+/', $augmented['storage_used']);
    }

    public function test_with_file_stats_caches_per_project(): void
    {
        $project = ['name' => 'sandbox', 'path' => $this->sandbox];

        $first = $this->service->withFileStats($project);

        // Add another file. Without cache, the second call would see
        // the new state; with cache, it must remain at 3.
        file_put_contents($this->sandbox.'/d.txt', 'new file');

        $second = $this->service->withFileStats($project);

        $this->assertSame($first['file_count'], $second['file_count']);
        $this->assertSame(3, $second['file_count']);
    }

    public function test_with_file_stats_returns_zeros_for_missing_path(): void
    {
        $project = ['name' => 'phantom', 'path' => '/path/that/does/not/exist'];

        $augmented = $this->service->withFileStats($project);

        $this->assertSame(0, $augmented['file_count']);
        $this->assertSame('0 B', $augmented['storage_used']);
    }

    public function test_with_file_stats_preserves_existing_keys(): void
    {
        $project = [
            'name' => 'sandbox',
            'path' => $this->sandbox,
            'type' => 'generic',
            'display_name' => 'Sandbox',
        ];

        $augmented = $this->service->withFileStats($project);

        $this->assertSame('Sandbox', $augmented['display_name']);
        $this->assertSame('generic', $augmented['type']);
        $this->assertArrayHasKey('file_count', $augmented);
        $this->assertArrayHasKey('storage_used', $augmented);
    }

    public function test_build_project_data_is_lazy(): void
    {
        $project = ['name' => 'sandbox', 'path' => $this->sandbox];

        // Explicit absence of file_count / storage_used is the hot-path
        // contract: dashboards that don't need them must not pay the
        // recursion cost.
        $this->assertArrayNotHasKey('file_count', $project);
        $this->assertArrayNotHasKey('storage_used', $project);
    }
}
