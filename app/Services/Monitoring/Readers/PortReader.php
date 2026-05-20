<?php

namespace App\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;
use Symfony\Component\Process\Process;

/**
 * Listening ports (TCP + UDP) via `ss -tlnp` then `ss -ulnp`.
 * Falls back to `netstat -tlnp` + `-ulnp` when ss is unavailable.
 *
 * Output shape:
 *   [
 *     {
 *       port: int,
 *       proto: 'tcp'|'udp',
 *       address: string,
 *       pid: ?int,
 *       process_name: ?string,
 *     },
 *     ...
 *   ]
 *
 * Each binding line includes a `users:(("nginx",pid=1234,fd=6))` style
 * column when running with appropriate privileges. Without privileges,
 * pid/process_name come back null — still useful to know which ports
 * are listening.
 */
class PortReader implements Reader
{
    public function key(): string
    {
        return 'ports';
    }

    public function read(ProcResolver $proc): array
    {
        $tcp = $this->probe('tcp');
        $udp = $this->probe('udp');

        return [...$tcp, ...$udp];
    }

    protected function probe(string $proto): array
    {
        $output = $this->runSs($proto);

        if ($output === null) {
            $output = $this->runNetstat($proto);
        }

        if ($output === null) {
            return [];
        }

        return $this->parse($output, $proto);
    }

    protected function runSs(string $proto): ?string
    {
        $flag = $proto === 'tcp' ? '-tlnp' : '-ulnp';
        $process = new Process(['ss', $flag]);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    protected function runNetstat(string $proto): ?string
    {
        $flag = $proto === 'tcp' ? '-tlnp' : '-ulnp';
        $process = new Process(['netstat', $flag]);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * Both `ss` and `netstat` share enough column structure that we can
     * parse them with one simple heuristic: find the local address
     * column, split on ':' to get the port, look for a `users:` or
     * `pid=` segment for owner info.
     */
    protected function parse(string $output, string $proto): array
    {
        $rows = [];
        $lines = preg_split('/\R/', trim($output));
        // Skip header
        array_shift($lines);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Find "<addr>:<port>" — first such match in the line.
            // Skip 0.0.0.0:* and ipv6 wildcards by matching only the
            // local address (typically column 4 in ss, column 4 in netstat).
            if (! preg_match('/(\[[0-9a-fA-F:]+\]|\*|\d+\.\d+\.\d+\.\d+):(\d+)/', $line, $matches)) {
                continue;
            }

            // Both ss and netstat may emit the foreign address with the
            // same regex on the same line. We want the first match
            // (local), and we also need to skip lines whose port is `0`
            // (state column noise on netstat output).
            $address = $matches[1];
            $port = (int) $matches[2];
            if ($port === 0) {
                continue;
            }

            $owner = $this->parseOwner($line);

            $rows[] = [
                'port' => $port,
                'proto' => $proto,
                'address' => $address,
                'pid' => $owner['pid'],
                'process_name' => $owner['name'],
            ];
        }

        return $rows;
    }

    /**
     * Owner info is reported either as `users:(("nginx",pid=1234,fd=6))`
     * (ss) or `1234/nginx` (netstat). Returns null pid/name when
     * neither shape matches.
     */
    protected function parseOwner(string $line): array
    {
        if (preg_match('/users:\(\("([^"]+)",\s*pid=(\d+)/', $line, $matches)) {
            return ['pid' => (int) $matches[2], 'name' => $matches[1]];
        }

        if (preg_match('/(\d+)\/([\w.\-]+)\s*$/', $line, $matches)) {
            return ['pid' => (int) $matches[1], 'name' => $matches[2]];
        }

        return ['pid' => null, 'name' => null];
    }
}
