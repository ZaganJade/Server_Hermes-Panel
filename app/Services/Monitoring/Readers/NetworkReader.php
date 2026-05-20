<?php

namespace App\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Network throughput per interface from /proc/net/dev.
 *
 * Output shape:
 *   [
 *     {
 *       iface,
 *       rx_bytes_per_sec: ?float,
 *       tx_bytes_per_sec: ?float,
 *       rx_errs: int,
 *       tx_errs: int,
 *     },
 *     ...
 *   ]
 *
 * Loopback (lo) and interfaces with zero rx+tx across two consecutive
 * samples are filtered out so the UI doesn't render dead rows.
 *
 * Delta strategy mirrors DiskIoReader: cache previous sample under
 * `hermes:monitoring:last:network`, return null + first_sample=true on
 * first call.
 */
final class NetworkReader implements Reader
{
    public const CACHE_KEY = 'hermes:monitoring:last:network';

    public const CACHE_TTL = 60;

    public function __construct(protected CacheRepository $cache) {}

    public function key(): string
    {
        return 'network';
    }

    public function read(ProcResolver $proc): array
    {
        $rows = $this->parseNetDev($proc->readFile('net/dev'));

        $previous = $this->cache->get(self::CACHE_KEY);
        $now = microtime(true);
        $this->cache->put(self::CACHE_KEY, ['ts' => $now, 'rows' => $rows], self::CACHE_TTL);

        if (! is_array($previous) || ! isset($previous['rows'])) {
            return array_map(
                fn ($row) => [
                    'iface' => $row['iface'],
                    'rx_bytes_per_sec' => null,
                    'tx_bytes_per_sec' => null,
                    'rx_errs' => $row['rx_errs'],
                    'tx_errs' => $row['tx_errs'],
                    'first_sample' => true,
                ],
                $rows,
            );
        }

        $elapsed = max(0.001, $now - (float) $previous['ts']);
        $previousRows = $this->indexByIface($previous['rows']);

        $result = [];
        foreach ($rows as $row) {
            $prev = $previousRows[$row['iface']] ?? null;
            $rxBps = $prev ? max(0, ($row['rx_bytes'] - $prev['rx_bytes']) / $elapsed) : null;
            $txBps = $prev ? max(0, ($row['tx_bytes'] - $prev['tx_bytes']) / $elapsed) : null;

            // Filter inactive interfaces — zero across both samples.
            if ($prev && $row['rx_bytes'] === $prev['rx_bytes'] && $row['tx_bytes'] === $prev['tx_bytes']
                && $row['rx_bytes'] === 0 && $row['tx_bytes'] === 0) {
                continue;
            }

            $result[] = [
                'iface' => $row['iface'],
                'rx_bytes_per_sec' => $rxBps !== null ? round($rxBps, 2) : null,
                'tx_bytes_per_sec' => $txBps !== null ? round($txBps, 2) : null,
                'rx_errs' => $row['rx_errs'],
                'tx_errs' => $row['tx_errs'],
            ];
        }

        return $result;
    }

    /**
     * /proc/net/dev format:
     *   header lines (skip)
     *   "  ifaceN: rxBytes rxPkts rxErrs ... txBytes txPkts txErrs ..."
     */
    protected function parseNetDev(string $content): array
    {
        $rows = [];
        $lines = preg_split('/\R/', trim($content));
        // skip first 2 lines (Inter-| header + column header)
        array_shift($lines);
        array_shift($lines);

        foreach ($lines as $line) {
            if (! preg_match('/^\s*([^:]+):\s*(.+)$/', $line, $matches)) {
                continue;
            }
            $iface = trim($matches[1]);

            if ($iface === 'lo') {
                continue;
            }

            $cols = preg_split('/\s+/', trim($matches[2]));
            if (count($cols) < 16) {
                continue;
            }

            $rows[] = [
                'iface' => $iface,
                'rx_bytes' => (int) $cols[0],
                'rx_errs' => (int) $cols[2],
                'tx_bytes' => (int) $cols[8],
                'tx_errs' => (int) $cols[10],
            ];
        }

        return $rows;
    }

    protected function indexByIface(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['iface']] = $row;
        }

        return $indexed;
    }
}
