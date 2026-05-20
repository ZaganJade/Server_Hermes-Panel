<?php

namespace Tests\Unit\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Readers\DiskUsageReader;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class DiskUsageReaderTest extends TestCase
{
    public function test_returns_at_least_one_real_filesystem_when_df_available(): void
    {
        if (! $this->dfAvailable()) {
            $this->markTestSkipped('df command not available on this host.');
        }

        $reader = new DiskUsageReader;
        $resolver = new ProcResolver(sys_get_temp_dir(), sys_get_temp_dir());
        $rows = $reader->read($resolver);

        $this->assertNotEmpty($rows, 'df should return at least one usable filesystem');

        foreach ($rows as $row) {
            $this->assertArrayHasKey('mount', $row);
            $this->assertArrayHasKey('fs', $row);
            $this->assertArrayHasKey('total_bytes', $row);
            $this->assertArrayHasKey('used_pct', $row);
            $this->assertGreaterThanOrEqual(0, $row['used_pct']);
            $this->assertLessThanOrEqual(100, $row['used_pct']);
            // Make sure pseudo filesystems were filtered.
            $this->assertStringStartsNotWith('tmpfs', $row['fs']);
            $this->assertStringStartsNotWith('devtmpfs', $row['fs']);
            $this->assertStringStartsNotWith('proc', $row['fs']);
            $this->assertStringStartsNotWith('sysfs', $row['fs']);
        }
    }

    public function test_skip_filter_constants_cover_common_pseudo_fs(): void
    {
        $skip = DiskUsageReader::SKIP_FILESYSTEMS;

        $this->assertContains('tmpfs', $skip);
        $this->assertContains('devtmpfs', $skip);
        $this->assertContains('proc', $skip);
        $this->assertContains('sysfs', $skip);
        $this->assertContains('overlay', $skip);
    }

    private function dfAvailable(): bool
    {
        return (new ExecutableFinder)->find('df') !== null;
    }
}
