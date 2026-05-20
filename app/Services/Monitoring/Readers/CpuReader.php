<?php

namespace App\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * CPU usage + load average from /proc/stat and /proc/loadavg.
 *
 * Output shape:
 *   {
 *     loadavg: { '1m': float, '5m': float, '15m': float },
 *     cores: int,
 *     usage_pct_total: ?float,           // null on first sample
 *     per_core: float[],                  // per-core busy percentage
 *     first_sample?: true,                // present when delta unknown
 *   }
 *
 * Delta strategy: cache previous /proc/stat cumulative columns under
 * `hermes:monitoring:last:cpu`. First call after panel boot has no prior
 * sample, so usage_pct_total + per_core are null and the reader marks
 * first_sample=true. The collector / storage layer treats first-sample
 * rate-based fields as "skip from time-series storage" (story 05).
 */
final class CpuReader implements Reader
{
    public const CACHE_KEY = 'hermes:monitoring:last:cpu';

    public const CACHE_TTL = 60;        // seconds; safe slack above 5s tick

    public function __construct(protected CacheRepository $cache) {}

    public function key(): string
    {
        return 'cpu';
    }

    public function read(ProcResolver $proc): array
    {
        $loadavg = $this->parseLoadavg($proc->readFile('loadavg'));
        $stat = $this->parseStat($proc->readFile('stat'));

        $previous = $this->cache->get(self::CACHE_KEY);
        $this->cache->put(self::CACHE_KEY, $stat, self::CACHE_TTL);

        if (! is_array($previous)) {
            return [
                'loadavg' => $loadavg,
                'cores' => count($stat['per_core']),
                'usage_pct_total' => null,
                'per_core' => array_fill(0, count($stat['per_core']), null),
                'first_sample' => true,
            ];
        }

        return [
            'loadavg' => $loadavg,
            'cores' => count($stat['per_core']),
            'usage_pct_total' => $this->busyPercent($previous['total'], $stat['total']),
            'per_core' => $this->perCorePercent($previous['per_core'], $stat['per_core']),
        ];
    }

    /**
     * /proc/loadavg → "0.10 0.20 0.30 1/200 12345"
     */
    protected function parseLoadavg(string $content): array
    {
        $parts = preg_split('/\s+/', trim($content));

        return [
            '1m' => (float) ($parts[0] ?? 0),
            '5m' => (float) ($parts[1] ?? 0),
            '15m' => (float) ($parts[2] ?? 0),
        ];
    }

    /**
     * Parse /proc/stat into total + per-core cumulative tick columns.
     *
     * Each cpu line: cpu  user nice system idle iowait irq softirq steal guest guest_nice
     * Total ticks  = sum of all columns. Idle ticks = idle + iowait.
     * Busy ticks   = total − idle.
     */
    protected function parseStat(string $content): array
    {
        $lines = preg_split('/\R/', trim($content));
        $total = ['total' => 0, 'idle' => 0];
        $perCore = [];

        foreach ($lines as $line) {
            if (! preg_match('/^cpu(\d*)\s+(.+)$/', trim($line), $matches)) {
                continue;
            }
            $coreLabel = $matches[1];     // '' = aggregate, '0' = core 0, etc.
            $columns = preg_split('/\s+/', trim($matches[2]));
            $columns = array_map('intval', $columns);

            $sum = array_sum($columns);
            $idle = ($columns[3] ?? 0) + ($columns[4] ?? 0);

            $entry = ['total' => $sum, 'idle' => $idle];

            if ($coreLabel === '') {
                $total = $entry;
            } else {
                $perCore[(int) $coreLabel] = $entry;
            }
        }

        // Normalize per-core list ordering (sorted by core index).
        ksort($perCore);

        return [
            'total' => $total,
            'per_core' => array_values($perCore),
        ];
    }

    /**
     * Compute busy percentage between two cumulative readings.
     * Returns 0..100 rounded to 2 decimals; 0 when ticks didn't advance.
     */
    protected function busyPercent(array $before, array $after): float
    {
        $totalDelta = max(0, ($after['total'] ?? 0) - ($before['total'] ?? 0));
        $idleDelta = max(0, ($after['idle'] ?? 0) - ($before['idle'] ?? 0));

        if ($totalDelta === 0) {
            return 0.0;
        }

        $busyDelta = $totalDelta - $idleDelta;

        return round(($busyDelta / $totalDelta) * 100, 2);
    }

    /**
     * Per-core delta percentages, aligned by index. Cores added between
     * samples (rare on a stable VPS) are reported as null.
     */
    protected function perCorePercent(array $before, array $after): array
    {
        $result = [];
        foreach ($after as $i => $current) {
            $previous = $before[$i] ?? null;
            $result[] = $previous ? $this->busyPercent($previous, $current) : null;
        }

        return $result;
    }
}
