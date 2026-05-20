<?php

namespace App\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;
use Symfony\Component\Process\Process;

/**
 * Disk usage per mount via `df -P`.
 *
 * Output shape:
 *   [
 *     { mount, fs, total_bytes, used_bytes, free_bytes, used_pct },
 *     ...
 *   ]
 *
 * Pseudo filesystems (tmpfs, devtmpfs, proc, sysfs, cgroup*, overlay)
 * are skipped — they don't represent real disks. The reader treats
 * `df` failure as a hard error so MetricCollector captures it in
 * Snapshot::$errors.
 */
final class DiskUsageReader implements Reader
{
    public const SKIP_FILESYSTEMS = [
        'tmpfs', 'devtmpfs', 'proc', 'sysfs', 'cgroup', 'cgroup2',
        'overlay', 'overlay2', 'squashfs', 'mqueue', 'pstore',
        'autofs', 'binfmt_misc', 'configfs', 'debugfs', 'tracefs',
        'fusectl', 'hugetlbfs', 'rpc_pipefs', 'efivarfs',
    ];

    public function key(): string
    {
        return 'disk_usage';
    }

    public function read(ProcResolver $proc): array
    {
        $process = new Process(['df', '--block-size=1', '--output=source,target,fstype,size,used,avail']);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('df command failed: '.trim($process->getErrorOutput()));
        }

        $rows = [];
        $lines = preg_split('/\R/', trim($process->getOutput()));
        array_shift($lines); // drop header

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 6) {
                continue;
            }
            [$source, $mount, $fstype, $total, $used, $avail] = $parts;

            if ($this->shouldSkip($fstype)) {
                continue;
            }

            $totalBytes = (int) $total;
            $usedBytes = (int) $used;
            $freeBytes = (int) $avail;

            $rows[] = [
                'mount' => $mount,
                'fs' => $fstype,
                'total_bytes' => $totalBytes,
                'used_bytes' => $usedBytes,
                'free_bytes' => $freeBytes,
                'used_pct' => $totalBytes > 0
                    ? round(($usedBytes / $totalBytes) * 100, 2)
                    : 0.0,
            ];
        }

        return $rows;
    }

    protected function shouldSkip(string $fstype): bool
    {
        foreach (self::SKIP_FILESYSTEMS as $skip) {
            if (str_starts_with($fstype, $skip)) {
                return true;
            }
        }

        return false;
    }
}
