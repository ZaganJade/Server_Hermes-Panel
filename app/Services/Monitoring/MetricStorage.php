<?php

namespace App\Services\Monitoring;

use Illuminate\Database\ConnectionInterface;

/**
 * Durable storage for v3.2 monitoring samples.
 *
 * Lives on a dedicated SQLite database at `storage/monitoring.sqlite`
 * (configured as the `monitoring` connection in config/database.php).
 * Panel-DB outages don't kill monitoring, and monitoring DB issues
 * don't kill the panel.
 *
 * Tables (created idempotently by `bootstrap()`):
 *   samples_raw        5s samples, retain 1h     (~720 rows/metric)
 *   samples_1m         1m aggregates, retain 24h (~1440 rows/metric)
 *   latest_snapshot    single-row JSON blob of the most recent tick
 *
 * Usage from MonitoringTickLoop:
 *   $storage->bootstrap();           // once on boot
 *   $storage->recordSample($snap);   // every tick
 *   $storage->aggregateMinute($ts);  // every 12th tick (1 min)
 *   $storage->prune($now);           // every 12th tick
 *
 * Usage from MonitoringController:
 *   $storage->latestSnapshot();      // GET /api/monitoring/snapshot
 *   $storage->rangeRaw($metric);     // ≤1h windows for charts
 *   $storage->rangeMinute($metric);  // >1h windows for charts
 */
final class MetricStorage
{
    public const RAW_RETENTION_SECONDS = 3600;        // 1 hour

    public const AGG_RETENTION_SECONDS = 86_400;      // 24 hours

    public const LATEST_SNAPSHOT_TS = 0;              // single fixed key

    public function __construct(protected ConnectionInterface $db) {}

    /**
     * Idempotent table + index creation. Safe to call on every boot.
     */
    public function bootstrap(): void
    {
        $statements = [
            'CREATE TABLE IF NOT EXISTS samples_raw (
                ts INTEGER NOT NULL,
                metric TEXT NOT NULL,
                value REAL NOT NULL,
                PRIMARY KEY (ts, metric)
            ) WITHOUT ROWID',

            'CREATE INDEX IF NOT EXISTS idx_samples_raw_ts ON samples_raw(ts)',

            'CREATE TABLE IF NOT EXISTS samples_1m (
                ts INTEGER NOT NULL,
                metric TEXT NOT NULL,
                avg REAL NOT NULL,
                min REAL NOT NULL,
                max REAL NOT NULL,
                PRIMARY KEY (ts, metric)
            ) WITHOUT ROWID',

            'CREATE INDEX IF NOT EXISTS idx_samples_1m_ts ON samples_1m(ts)',

            'CREATE TABLE IF NOT EXISTS latest_snapshot (
                ts INTEGER NOT NULL PRIMARY KEY,
                payload TEXT NOT NULL
            )',
        ];

        foreach ($statements as $sql) {
            $this->db->statement($sql);
        }
    }

    /**
     * Persist a fresh snapshot: numeric metrics flatten into samples_raw,
     * full payload (including discrete state — service/process/port lists)
     * lands in latest_snapshot. Atomic transaction.
     */
    public function recordSample(Snapshot $snapshot): void
    {
        $rows = $this->flattenForRaw($snapshot);

        $this->db->transaction(function () use ($snapshot, $rows) {
            foreach ($rows as $row) {
                $this->db->table('samples_raw')->insertOrIgnore($row);
            }

            $this->db->table('latest_snapshot')->updateOrInsert(
                ['ts' => self::LATEST_SNAPSHOT_TS],
                ['payload' => json_encode($snapshot->toArray(), JSON_UNESCAPED_SLASHES)],
            );
        });
    }

    /**
     * Compute avg/min/max over the 60-second window ending at the
     * supplied minute boundary (`ts == boundary - 60` through `ts == boundary - 1`).
     */
    public function aggregateMinute(int $minuteBoundaryTs): void
    {
        $start = $minuteBoundaryTs - 60;
        $end = $minuteBoundaryTs - 1;

        $rows = $this->db->table('samples_raw')
            ->whereBetween('ts', [$start, $end])
            ->selectRaw('metric, AVG(value) as avg, MIN(value) as min, MAX(value) as max')
            ->groupBy('metric')
            ->get();

        $this->db->transaction(function () use ($rows, $minuteBoundaryTs) {
            foreach ($rows as $row) {
                $this->db->table('samples_1m')->updateOrInsert(
                    ['ts' => $minuteBoundaryTs, 'metric' => $row->metric],
                    [
                        'avg' => (float) $row->avg,
                        'min' => (float) $row->min,
                        'max' => (float) $row->max,
                    ],
                );
            }
        });
    }

    /**
     * Drop samples past their retention windows. Run after each minute
     * tick. Cheap on WAL mode SQLite — single index seek + delete.
     */
    public function prune(int $now): void
    {
        $this->db->table('samples_raw')
            ->where('ts', '<', $now - self::RAW_RETENTION_SECONDS)
            ->delete();

        $this->db->table('samples_1m')
            ->where('ts', '<', $now - self::AGG_RETENTION_SECONDS)
            ->delete();

        // Reclaim WAL space periodically. SQLite's auto-checkpoint
        // handles the common case but TRUNCATE shrinks the WAL file
        // on disk, which matters on small VPS storage budgets.
        try {
            $this->db->statement('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable) {
            // Best effort — not fatal if the pragma errors.
        }
    }

    /**
     * Single-row read for the HTTP /snapshot endpoint and the initial
     * page-load state on the monitoring tab. Returns null when the
     * tick-loop hasn't recorded anything yet.
     */
    public function latestSnapshot(): ?array
    {
        $row = $this->db->table('latest_snapshot')
            ->where('ts', self::LATEST_SNAPSHOT_TS)
            ->first();

        if (! $row) {
            return null;
        }

        $decoded = json_decode((string) $row->payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Raw 5s values keyed by ts. Used for ≤1h chart windows where the
     * tick resolution gives the nicest chart.
     *
     * @return array<int, float> ts => value, ascending
     */
    public function rangeRaw(string $metric, int $from, int $to): array
    {
        return $this->db->table('samples_raw')
            ->where('metric', $metric)
            ->whereBetween('ts', [$from, $to])
            ->orderBy('ts')
            ->pluck('value', 'ts')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * 1-minute aggregate avg/min/max keyed by ts. Used for >1h windows.
     *
     * @return array<int, array{avg: float, min: float, max: float}>
     */
    public function rangeMinute(string $metric, int $from, int $to): array
    {
        $rows = $this->db->table('samples_1m')
            ->where('metric', $metric)
            ->whereBetween('ts', [$from, $to])
            ->orderBy('ts')
            ->get(['ts', 'avg', 'min', 'max']);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->ts] = [
                'avg' => (float) $row->avg,
                'min' => (float) $row->min,
                'max' => (float) $row->max,
            ];
        }

        return $result;
    }

    /**
     * Flatten a Snapshot's numeric leaves into (ts, metric, value) rows.
     *
     * Discrete state (process list, service list, port list) is NOT
     * flattened — it lives only in latest_snapshot JSON blob since
     * "name → value" doesn't cleanly map to a time-series.
     *
     * Skips rate-based fields when first_sample is true, since those
     * values are uninitialized cumulative counters not real rates.
     *
     * @return array<int, array{ts: int, metric: string, value: float}>
     */
    protected function flattenForRaw(Snapshot $snapshot): array
    {
        $flat = [];
        $emit = static function (string $metric, mixed $value) use (&$flat, $snapshot): void {
            if ($value === null || ! is_numeric($value)) {
                return;
            }
            $flat[] = [
                'ts' => $snapshot->ts,
                'metric' => $metric,
                'value' => (float) $value,
            ];
        };

        foreach ($snapshot->entries as $key => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            // CPU
            if ($key === 'cpu') {
                $emit('cpu.loadavg.1m', $payload['loadavg']['1m'] ?? null);
                $emit('cpu.loadavg.5m', $payload['loadavg']['5m'] ?? null);
                $emit('cpu.loadavg.15m', $payload['loadavg']['15m'] ?? null);
                if (empty($payload['first_sample'])) {
                    $emit('cpu.usage_pct', $payload['usage_pct_total'] ?? null);
                }
            }

            // Memory
            if ($key === 'memory') {
                $emit('mem.used_kb', $payload['used_kb'] ?? null);
                $emit('mem.swap_used_kb', $payload['swap_used_kb'] ?? null);
            }

            // Disk usage per mount
            if ($key === 'disk_usage') {
                foreach ($payload as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $mount = $this->slugify((string) ($row['mount'] ?? ''));
                    if ($mount === '') {
                        continue;
                    }
                    $emit("disk.{$mount}.used_pct", $row['used_pct'] ?? null);
                }
            }

            // Disk I/O totals (skip first sample — counters not rates)
            if ($key === 'disk_io' && empty($payload['first_sample'])) {
                $emit('disk.read_bytes_per_sec', $payload['read_bytes_per_sec'] ?? null);
                $emit('disk.write_bytes_per_sec', $payload['write_bytes_per_sec'] ?? null);
            }

            // Network per interface (skip first-sample interfaces)
            if ($key === 'network') {
                foreach ($payload as $row) {
                    if (! is_array($row) || ! empty($row['first_sample'])) {
                        continue;
                    }
                    $iface = $this->slugify((string) ($row['iface'] ?? ''));
                    if ($iface === '') {
                        continue;
                    }
                    $emit("net.{$iface}.rx_bytes_per_sec", $row['rx_bytes_per_sec'] ?? null);
                    $emit("net.{$iface}.tx_bytes_per_sec", $row['tx_bytes_per_sec'] ?? null);
                }
            }

            // TCP connections
            if ($key === 'connections') {
                $emit('net.tcp_established', $payload['tcp_established'] ?? null);
            }
        }

        return $flat;
    }

    /**
     * Lower-case + replace anything non-alphanumeric with `_`. Used to
     * make mount points (`/var/log` → `_var_log`) and interface names
     * safe as metric-key suffixes.
     */
    protected function slugify(string $value): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($value));

        return trim((string) $slug, '_');
    }
}
