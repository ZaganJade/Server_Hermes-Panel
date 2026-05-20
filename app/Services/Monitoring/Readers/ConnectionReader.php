<?php

namespace App\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;

/**
 * TCP connection counts from /proc/net/tcp and /proc/net/tcp6.
 *
 * Output shape:
 *   { tcp_established: int }
 *
 * /proc/net/tcp* state column is the 4th whitespace-separated field
 * (after sl, local_address, remote_address). State '01' means
 * ESTABLISHED. Other states (LISTEN '0A', TIME_WAIT '06', ...) are
 * ignored for now — could grow into per-state counts later.
 */
final class ConnectionReader implements Reader
{
    public function key(): string
    {
        return 'connections';
    }

    public function read(ProcResolver $proc): array
    {
        $count = 0;

        foreach (['net/tcp', 'net/tcp6'] as $path) {
            try {
                $count += $this->countEstablished($proc->readFile($path));
            } catch (\Throwable) {
                // tcp6 may be absent on minimal kernels; ignore.
                continue;
            }
        }

        return ['tcp_established' => $count];
    }

    protected function countEstablished(string $content): int
    {
        $count = 0;
        $lines = preg_split('/\R/', trim($content));
        array_shift($lines); // drop header

        foreach ($lines as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) < 4) {
                continue;
            }
            // cols[0]=sl: cols[1]=local cols[2]=remote cols[3]=state
            if (($cols[3] ?? '') === '01') {
                $count++;
            }
        }

        return $count;
    }
}
