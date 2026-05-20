<?php

namespace App\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;

/**
 * Boot time + uptime in seconds.
 *
 * Output shape:
 *   { boot_time_unix: int, uptime_seconds: int }
 *
 * /proc/uptime gives uptime as a float; /proc/stat's `btime <epoch>`
 * line is the canonical boot timestamp. Computing one from the other is
 * imprecise (sample drift), so we read both and keep them as-reported.
 */
final class UptimeReader implements Reader
{
    public function key(): string
    {
        return 'uptime';
    }

    public function read(ProcResolver $proc): array
    {
        $uptime = $this->parseUptime($proc->readFile('uptime'));
        $bootTime = $this->parseBootTime($proc->readFile('stat'));

        return [
            'boot_time_unix' => $bootTime,
            'uptime_seconds' => $uptime,
        ];
    }

    /**
     * /proc/uptime → "12345.67 9876.54" (uptime, idle).
     */
    protected function parseUptime(string $content): int
    {
        $parts = preg_split('/\s+/', trim($content));

        return (int) ((float) ($parts[0] ?? 0));
    }

    /**
     * /proc/stat has a `btime <epoch>` line among the cpu / intr / ctxt
     * entries. Returns 0 if not present (handle gracefully — some
     * minimal containers omit it).
     */
    protected function parseBootTime(string $content): int
    {
        if (preg_match('/^btime\s+(\d+)/m', $content, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }
}
