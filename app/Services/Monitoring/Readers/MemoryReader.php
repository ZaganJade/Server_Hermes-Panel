<?php

namespace App\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;

/**
 * Memory + swap usage from /proc/meminfo.
 *
 * Output shape (KB):
 *   {
 *     total_kb, used_kb, free_kb, available_kb,
 *     buffers_kb, cached_kb,
 *     swap_total_kb, swap_used_kb,
 *   }
 *
 * "used" follows the htop convention: total − free − buffers − cached.
 * "available" is what /proc/meminfo reports under MemAvailable when the
 * kernel exposes it (Linux 3.14+); fall back to free when missing.
 */
final class MemoryReader implements Reader
{
    public function key(): string
    {
        return 'memory';
    }

    public function read(ProcResolver $proc): array
    {
        $fields = $this->parseMeminfo($proc->readFile('meminfo'));

        $total = $fields['MemTotal'] ?? null;
        $free = $fields['MemFree'] ?? null;
        $available = $fields['MemAvailable'] ?? $free;
        $buffers = $fields['Buffers'] ?? 0;
        $cached = $fields['Cached'] ?? 0;
        $swapTotal = $fields['SwapTotal'] ?? null;
        $swapFree = $fields['SwapFree'] ?? null;

        return [
            'total_kb' => $total,
            'used_kb' => $this->computeUsed($total, $free, $buffers, $cached),
            'free_kb' => $free,
            'available_kb' => $available,
            'buffers_kb' => $buffers,
            'cached_kb' => $cached,
            'swap_total_kb' => $swapTotal,
            'swap_used_kb' => ($swapTotal !== null && $swapFree !== null)
                ? max(0, $swapTotal - $swapFree)
                : null,
        ];
    }

    /**
     * Parse /proc/meminfo into ['MemTotal' => int_kb, ...].
     * Lines look like: "MemTotal:        8138364 kB"
     */
    protected function parseMeminfo(string $content): array
    {
        $fields = [];

        foreach (preg_split('/\R/', trim($content)) as $line) {
            if (! preg_match('/^([A-Za-z0-9_()]+):\s+(\d+)(?:\s+kB)?/', trim($line), $matches)) {
                continue;
            }
            $fields[$matches[1]] = (int) $matches[2];
        }

        return $fields;
    }

    protected function computeUsed(?int $total, ?int $free, int $buffers, int $cached): ?int
    {
        if ($total === null || $free === null) {
            return null;
        }

        return max(0, $total - $free - $buffers - $cached);
    }
}
