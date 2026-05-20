<?php

namespace App\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Top processes by CPU and RSS, deduped to 10 rows max.
 *
 * Output shape:
 *   [
 *     { pid, name, cmd, cpu_pct: ?float, rss_kb: int },
 *     ...
 *   ]
 *
 * Walks /proc/[pid]/stat (utime+stime cumulative ticks) and
 * /proc/[pid]/status (VmRSS). For CPU%, caches each pid's previous
 * cumulative ticks under hermes:monitoring:last:process and computes
 * delta against the cached system-wide CPU total ticks. First sample
 * for any pid returns cpu_pct=null.
 *
 * Sort: top 5 by cpu_pct + top 5 by rss_kb, deduped on pid → max 10.
 */
final class ProcessReader implements Reader
{
    public const CACHE_KEY = 'hermes:monitoring:last:process';

    public const CACHE_TTL = 60;

    public const TOP_N_PER_DIMENSION = 5;

    public function __construct(protected CacheRepository $cache) {}

    public function key(): string
    {
        return 'process';
    }

    public function read(ProcResolver $proc): array
    {
        // System-wide total ticks for CPU% denominator (delta).
        $systemTotalNow = $this->systemTotalTicks($proc->readFile('stat'));
        $previous = $this->cache->get(self::CACHE_KEY);
        $previousSystemTotal = is_array($previous) ? (int) ($previous['system_total'] ?? 0) : 0;
        $previousPids = is_array($previous) ? (array) ($previous['pids'] ?? []) : [];
        $systemTotalDelta = max(1, $systemTotalNow - $previousSystemTotal);

        $rows = [];
        $newPids = [];

        foreach ($this->iteratePidDirs($proc) as $pid => $statFile) {
            $stat = @file_get_contents($statFile);
            if ($stat === false) {
                continue;
            }

            $parsed = $this->parseStat($stat);
            if ($parsed === null) {
                continue;
            }

            $rss = $this->readRssKb($proc, $pid);

            $cumulativeTicks = $parsed['utime'] + $parsed['stime'];
            $newPids[$pid] = $cumulativeTicks;

            $cpuPct = null;
            if (isset($previousPids[$pid]) && $previousSystemTotal > 0) {
                $procDelta = max(0, $cumulativeTicks - (int) $previousPids[$pid]);
                $cpuPct = round(($procDelta / $systemTotalDelta) * 100, 2);
            }

            $rows[] = [
                'pid' => $pid,
                'name' => $parsed['name'],
                'cmd' => $this->readCmdline($proc, $pid) ?: $parsed['name'],
                'cpu_pct' => $cpuPct,
                'rss_kb' => $rss,
            ];
        }

        $this->cache->put(
            self::CACHE_KEY,
            ['system_total' => $systemTotalNow, 'pids' => $newPids],
            self::CACHE_TTL,
        );

        return $this->topNDeduped($rows);
    }

    /**
     * Top 5 by CPU + top 5 by RSS, deduped by pid → at most 10 rows.
     */
    protected function topNDeduped(array $rows): array
    {
        $byCpu = $rows;
        usort($byCpu, fn ($a, $b) => ($b['cpu_pct'] ?? -1) <=> ($a['cpu_pct'] ?? -1));
        $byCpu = array_slice($byCpu, 0, self::TOP_N_PER_DIMENSION);

        $byRss = $rows;
        usort($byRss, fn ($a, $b) => ($b['rss_kb'] ?? 0) <=> ($a['rss_kb'] ?? 0));
        $byRss = array_slice($byRss, 0, self::TOP_N_PER_DIMENSION);

        $merged = [];
        foreach (array_merge($byCpu, $byRss) as $row) {
            $merged[$row['pid']] = $row;
        }

        // Final order: by cpu_pct desc (with null treated as -1) then rss desc.
        $output = array_values($merged);
        usort($output, function ($a, $b) {
            $cmp = ($b['cpu_pct'] ?? -1) <=> ($a['cpu_pct'] ?? -1);

            return $cmp !== 0 ? $cmp : ($b['rss_kb'] ?? 0) <=> ($a['rss_kb'] ?? 0);
        });

        return array_slice($output, 0, self::TOP_N_PER_DIMENSION * 2);
    }

    /**
     * Iterate /proc/<pid>/stat files for numeric pid directories.
     *
     * @return iterable<int, string> pid => absolute path to stat file
     */
    protected function iteratePidDirs(ProcResolver $proc): iterable
    {
        $procRoot = $proc->procRoot();
        $dh = @opendir($procRoot);
        if (! $dh) {
            return;
        }

        try {
            while (($entry = readdir($dh)) !== false) {
                if (! ctype_digit($entry)) {
                    continue;
                }
                $statPath = $procRoot.'/'.$entry.'/stat';
                if (! is_readable($statPath)) {
                    continue;
                }
                yield (int) $entry => $statPath;
            }
        } finally {
            closedir($dh);
        }
    }

    /**
     * /proc/<pid>/stat columns we need:
     *   1=pid 2=(comm) 14=utime 15=stime
     * comm can contain spaces inside parens — extract it verbatim.
     */
    protected function parseStat(string $content): ?array
    {
        if (! preg_match('/^\d+\s+\((.+)\)\s+(.+)$/', trim($content), $matches)) {
            return null;
        }
        $name = $matches[1];
        $rest = preg_split('/\s+/', $matches[2]);

        // Field 3 is state ('R'/'S'/...), so utime is rest[11] and stime is rest[12]
        // (because we already consumed pid + comm before the state column).
        if (count($rest) < 13) {
            return null;
        }

        return [
            'name' => $name,
            'utime' => (int) ($rest[11] ?? 0),
            'stime' => (int) ($rest[12] ?? 0),
        ];
    }

    protected function readRssKb(ProcResolver $proc, int $pid): int
    {
        $statusPath = $proc->procRoot().'/'.$pid.'/status';
        $content = @file_get_contents($statusPath);
        if ($content === false) {
            return 0;
        }
        if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $content, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    protected function readCmdline(ProcResolver $proc, int $pid): string
    {
        $cmdlinePath = $proc->procRoot().'/'.$pid.'/cmdline';
        $content = @file_get_contents($cmdlinePath);
        if ($content === false || $content === '') {
            return '';
        }

        // /proc/<pid>/cmdline is null-separated argv.
        return trim(str_replace("\0", ' ', $content));
    }

    /**
     * Sum of all columns on the aggregate "cpu " line (idle + busy).
     */
    protected function systemTotalTicks(string $stat): int
    {
        if (! preg_match('/^cpu\s+(.+)$/m', $stat, $matches)) {
            return 0;
        }
        $columns = preg_split('/\s+/', trim($matches[1]));

        return array_sum(array_map('intval', $columns));
    }
}
