<?php

namespace Tests\Unit\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Readers\ProcessReader;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class ProcessReaderTest extends TestCase
{
    private string $procRoot;

    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procRoot = sys_get_temp_dir().'/hermes-proc-'.uniqid();
        mkdir($this->procRoot, 0o755, true);
        // System aggregate /proc/stat for CPU% denominator.
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/stat-first', $this->procRoot.'/stat');
        $this->cache = new Repository(new ArrayStore);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->procRoot);
        parent::tearDown();
    }

    public function test_first_sample_has_null_cpu_pct_for_every_pid(): void
    {
        $this->writeFakePid(100, 'nginx', utime: 1000, stime: 200, rssKb: 50_000);
        $this->writeFakePid(101, 'redis', utime: 5000, stime: 400, rssKb: 30_000);
        $this->writeFakePid(102, 'php-fpm', utime: 200, stime: 50, rssKb: 100_000);

        $reader = new ProcessReader($this->cache);
        $rows = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNull($row['cpu_pct']);
            $this->assertGreaterThan(0, $row['rss_kb']);
        }
    }

    public function test_top_n_dedup_caps_at_ten_when_overlap(): void
    {
        // Generate 12 pids with a mix where some are top-CPU and others
        // are top-RSS; verify the union dedup keeps it at most 10.
        for ($i = 1; $i <= 12; $i++) {
            $this->writeFakePid(
                pid: 100 + $i,
                name: "proc-{$i}",
                utime: $i * 100,
                stime: $i * 10,
                rssKb: 1_000 + $i * 100,
            );
        }

        $reader = new ProcessReader($this->cache);
        $rows = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $this->assertLessThanOrEqual(10, count($rows));
        // No duplicate pid in the result set.
        $pids = array_column($rows, 'pid');
        $this->assertSame(count($pids), count(array_unique($pids)));
    }

    public function test_second_sample_computes_cpu_pct(): void
    {
        // First sample establishes baseline for both system + per-pid.
        $this->writeFakePid(200, 'busy', utime: 0, stime: 0, rssKb: 10_000);
        $reader = new ProcessReader($this->cache);
        $reader->read(new ProcResolver($this->procRoot, '/sys'));

        // Bump system aggregate ticks AND the pid's cumulative ticks
        // so the second pass sees movement on both.
        copy(__DIR__.'/../../../../Fixtures/Monitoring/proc/stat-second', $this->procRoot.'/stat');
        $this->writeFakePid(200, 'busy', utime: 100, stime: 50, rssKb: 10_000);

        $rows = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $busy = collect($rows)->firstWhere('pid', 200);
        $this->assertNotNull($busy['cpu_pct']);
        $this->assertGreaterThan(0, $busy['cpu_pct']);
    }

    public function test_reads_cmdline_and_falls_back_to_comm_when_empty(): void
    {
        $this->writeFakePid(300, 'reddit', utime: 0, stime: 0, rssKb: 1_000, cmdline: '/usr/bin/server --port 9000');
        $this->writeFakePid(301, 'plain', utime: 0, stime: 0, rssKb: 1_000, cmdline: '');

        $reader = new ProcessReader($this->cache);
        $rows = $reader->read(new ProcResolver($this->procRoot, '/sys'));

        $reddit = collect($rows)->firstWhere('pid', 300);
        $plain = collect($rows)->firstWhere('pid', 301);

        $this->assertSame('/usr/bin/server --port 9000', $reddit['cmd']);
        $this->assertSame('plain', $plain['cmd']);
    }

    private function writeFakePid(
        int $pid,
        string $name,
        int $utime,
        int $stime,
        int $rssKb,
        string $cmdline = '',
    ): void {
        $dir = $this->procRoot.'/'.$pid;
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // /proc/<pid>/stat columns (1-indexed):
        //   1=pid 2=(comm) 3=state 4=ppid ... 14=utime 15=stime ...
        // After "(comm)" we want 11 columns of padding to land utime at
        // position 14 in the original spec; ProcessReader::parseStat
        // splits the post-comm tail and reads index [11] (utime) and
        // [12] (stime) — so we put 11 placeholder columns first.
        $padding = implode(' ', array_fill(0, 11, '0'));
        $stat = "{$pid} ({$name}) S {$padding} {$utime} {$stime} 0 0 0";
        file_put_contents($dir.'/stat', $stat);

        file_put_contents($dir.'/status', "Name:\t{$name}\nVmRSS:\t{$rssKb} kB\n");
        file_put_contents($dir.'/cmdline', str_replace(' ', "\0", $cmdline));
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iter as $file) {
            $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
        }

        rmdir($path);
    }
}
