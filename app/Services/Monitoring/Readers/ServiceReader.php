<?php

namespace App\Services\Monitoring\Readers;

use App\Services\Monitoring\ProcResolver;
use App\Services\Monitoring\Reader;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Symfony\Component\Process\Process;

/**
 * Service health from systemctl, with pgrep fallback for environments
 * without systemd access (e.g. containers without /run/systemd mount).
 *
 * Output shape:
 *   [
 *     {
 *       unit,                                      // 'nginx.service' or pattern name
 *       status: 'active'|'inactive'|'failed'|'unknown',
 *       detection: 'systemd'|'pgrep',
 *     },
 *     ...
 *   ]
 *
 * Discovery:
 *   1. Try systemctl list-units, filter against
 *      config('panel.monitoring.service_patterns').
 *   2. If systemctl unavailable / errors, fall back to pgrep -x
 *      against the same pattern bases (strip glob suffix).
 *
 * Discovery is cached for 60 s under hermes:monitoring:services:units.
 * Each tick reads the cache then probes per-unit status (cheap).
 */
class ServiceReader implements Reader
{
    public const CACHE_KEY = 'hermes:monitoring:services:units';

    public const CACHE_TTL = 60;

    public function __construct(protected CacheRepository $cache) {}

    public function key(): string
    {
        return 'services';
    }

    public function read(ProcResolver $proc): array
    {
        $units = $this->cache->remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => $this->discoverUnits(),
        );

        return array_map(fn (array $unit) => $this->probeUnit($unit), $units);
    }

    /**
     * Whitelist patterns from config.
     *
     * @return array<int, string>
     */
    protected function patterns(): array
    {
        return (array) config('panel.monitoring.service_patterns', [
            'nginx*', 'apache2*', 'caddy*', 'traefik*',
            'mysql*', 'mariadb*', 'postgres*', 'postgresql*',
            'redis*', 'memcached*',
            'php-fpm*', 'php*-fpm*',
            'docker', 'containerd', 'podman',
        ]);
    }

    protected function discoverUnits(): array
    {
        $systemd = $this->discoverViaSystemd();

        if ($systemd !== null) {
            return $systemd;
        }

        return $this->discoverViaPgrep();
    }

    /**
     * Attempt systemctl list-units. Returns null when systemctl is
     * absent or returns non-zero (lets caller fall through to pgrep).
     *
     * @return array<int, array{name:string, detection:string}>|null
     */
    protected function discoverViaSystemd(): ?array
    {
        $process = new Process([
            'systemctl', 'list-units',
            '--type=service', '--state=running,loaded',
            '--no-legend', '--no-pager', '--plain',
        ]);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $patterns = $this->patterns();
        $units = [];

        foreach (preg_split('/\R/', trim($process->getOutput())) as $line) {
            $unit = explode(' ', trim($line))[0] ?? '';
            if ($unit === '') {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (fnmatch($pattern.'.service', $unit) || fnmatch($pattern, $unit)) {
                    $units[$unit] = ['name' => $unit, 'detection' => 'systemd'];
                    break;
                }
            }
        }

        return array_values($units);
    }

    /**
     * Pgrep fallback: for each pattern, look for a matching process
     * name and emit a synthetic unit entry.
     *
     * @return array<int, array{name:string, detection:string}>
     */
    protected function discoverViaPgrep(): array
    {
        $patterns = $this->patterns();
        $units = [];

        foreach ($patterns as $pattern) {
            $bare = rtrim($pattern, '*');
            if ($bare === '') {
                continue;
            }
            // pgrep -c just counts matches; presence > 0 means we should
            // surface this pattern as a "synthetic" unit.
            $process = new Process(['pgrep', '-c', '-f', $bare]);
            $process->setTimeout(3);

            try {
                $process->run();
            } catch (\Throwable) {
                continue;
            }

            $count = (int) trim($process->getOutput());
            if ($count > 0) {
                $units[] = ['name' => $bare, 'detection' => 'pgrep'];
            }
        }

        return $units;
    }

    /**
     * Probe a single unit's current status. systemd-discovered units
     * use systemctl is-active; pgrep-discovered units re-run pgrep -c.
     */
    protected function probeUnit(array $unit): array
    {
        if (($unit['detection'] ?? null) === 'pgrep') {
            return $this->probeViaPgrep($unit);
        }

        return $this->probeViaSystemctl($unit);
    }

    protected function probeViaSystemctl(array $unit): array
    {
        $process = new Process(['systemctl', 'is-active', $unit['name']]);
        $process->setTimeout(3);

        try {
            $process->run();
        } catch (\Throwable) {
            return [
                'unit' => $unit['name'],
                'status' => 'unknown',
                'detection' => 'systemd',
            ];
        }

        $output = trim($process->getOutput());
        $status = match ($output) {
            'active' => 'active',
            'inactive' => 'inactive',
            'failed' => 'failed',
            default => 'unknown',
        };

        return [
            'unit' => $unit['name'],
            'status' => $status,
            'detection' => 'systemd',
        ];
    }

    protected function probeViaPgrep(array $unit): array
    {
        $process = new Process(['pgrep', '-c', '-f', $unit['name']]);
        $process->setTimeout(3);

        try {
            $process->run();
        } catch (\Throwable) {
            return [
                'unit' => $unit['name'],
                'status' => 'unknown',
                'detection' => 'pgrep',
            ];
        }

        $count = (int) trim($process->getOutput());

        return [
            'unit' => $unit['name'],
            'status' => $count > 0 ? 'active' : 'inactive',
            'detection' => 'pgrep',
        ];
    }
}
