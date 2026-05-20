<?php

namespace App\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Disk I/O bytes/sec from /proc/diskstats.
 *
 * /proc/diskstats columns of interest:
 *   col[5] = sectors read   (×512 bytes)
 *   col[9] = sectors written (×512 bytes)
 *
 * Loop and ram devices are skipped — they don't represent real
 * physical I/O. First call after panel boot has no prior reading;
 * we mark first_sample=true and return raw cumulative counters.
 *
 * Output shape:
 *   {
 *     read_bytes_per_sec: ?float,
 *     write_bytes_per_sec: ?float,
 *     per_device: [
 *       { device, read_bytes_per_sec, write_bytes_per_sec },
 *       ...
 *     ],
 *     first_sample?: true,
 *   }
 */
final class DiskIoReader implements Reader
{
    public const CACHE_KEY = 'hermes:monitoring:last:disk_io';

    public const CACHE_TTL = 60;

    public const SECTOR_BYTES = 512;

    public function __construct(protected CacheRepository $cache) {}

    public function key(): string
    {
        return 'disk_io';
    }

    public function read(ProcResolver $proc): array
    {
        $rows = $this->parseDiskstats($proc->readFile('diskstats'));

        $previous = $this->cache->get(self::CACHE_KEY);
        $now = microtime(true);
        $this->cache->put(self::CACHE_KEY, ['ts' => $now, 'rows' => $rows], self::CACHE_TTL);

        if (! is_array($previous) || ! isset($previous['rows'])) {
            return [
                'read_bytes_per_sec' => null,
                'write_bytes_per_sec' => null,
                'per_device' => array_map(
                    fn ($row) => [
                        'device' => $row['device'],
                        'read_bytes_per_sec' => null,
                        'write_bytes_per_sec' => null,
                    ],
                    $rows,
                ),
                'first_sample' => true,
            ];
        }

        $elapsed = max(0.001, $now - (float) $previous['ts']);
        $previousRows = $this->indexByDevice($previous['rows']);

        $perDevice = [];
        $totalRead = 0.0;
        $totalWrite = 0.0;

        foreach ($rows as $row) {
            $prev = $previousRows[$row['device']] ?? null;

            if (! $prev) {
                $perDevice[] = [
                    'device' => $row['device'],
                    'read_bytes_per_sec' => null,
                    'write_bytes_per_sec' => null,
                ];

                continue;
            }

            $readBps = max(0, ($row['sectors_read'] - $prev['sectors_read']) * self::SECTOR_BYTES) / $elapsed;
            $writeBps = max(0, ($row['sectors_written'] - $prev['sectors_written']) * self::SECTOR_BYTES) / $elapsed;

            $perDevice[] = [
                'device' => $row['device'],
                'read_bytes_per_sec' => round($readBps, 2),
                'write_bytes_per_sec' => round($writeBps, 2),
            ];

            $totalRead += $readBps;
            $totalWrite += $writeBps;
        }

        return [
            'read_bytes_per_sec' => round($totalRead, 2),
            'write_bytes_per_sec' => round($totalWrite, 2),
            'per_device' => $perDevice,
        ];
    }

    /**
     * Parse /proc/diskstats. Columns:
     *   major minor name reads readsMerged sectorsRead readMs
     *   writes writesMerged sectorsWritten writeMs ... (+ extras)
     */
    protected function parseDiskstats(string $content): array
    {
        $rows = [];

        foreach (preg_split('/\R/', trim($content)) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 10) {
                continue;
            }
            $device = $parts[2];

            if ($this->shouldSkip($device)) {
                continue;
            }

            $rows[] = [
                'device' => $device,
                'sectors_read' => (int) $parts[5],
                'sectors_written' => (int) $parts[9],
            ];
        }

        return $rows;
    }

    protected function shouldSkip(string $device): bool
    {
        return str_starts_with($device, 'loop')
            || str_starts_with($device, 'ram')
            || str_starts_with($device, 'fd');
    }

    protected function indexByDevice(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['device']] = $row;
        }

        return $indexed;
    }
}
