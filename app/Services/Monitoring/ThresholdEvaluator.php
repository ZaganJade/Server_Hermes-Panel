<?php

namespace App\Services\Monitoring;

use App\Events\MonitoringAlert;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Evaluates thresholds against a snapshot, emits MonitoringAlert events
 * on level transitions, and surfaces the active alert set for the
 * snapshot broadcast payload.
 *
 * Rules live in `config('panel.monitoring.thresholds')`. Each rule has
 * an id, a metric path, and level definitions (warning / critical).
 * Hysteresis is implemented via a per-rule cache key that records the
 * last evaluated level — events fire only on transitions.
 *
 * Sustained-time rules (eg `cpu_load`) require the threshold to be
 * exceeded for at least `sustained_seconds` before the level flips.
 *
 * The `service_down` rule pulls expected-active service names from a
 * separate cache key populated by ServiceReader's first observation
 * of an active unit. A unit is considered "down" only after it has
 * been seen active at least once in the last 5 minutes.
 */
final class ThresholdEvaluator
{
    public const STATE_TTL = 600;                 // 10 minutes

    public const EXPECTED_SERVICES_TTL = 300;     // 5 minutes

    public const STATE_KEY_PREFIX = 'hermes:monitoring:threshold:';

    public const EXPECTED_SERVICES_KEY = 'hermes:monitoring:expected_services';

    public function __construct(
        protected CacheRepository $cache,
        protected Dispatcher $events,
    ) {}

    /**
     * Run every rule against the snapshot.
     *
     * @return array<int, array<string, mixed>> the alerts produced this tick (in toArray() shape)
     */
    public function evaluate(Snapshot $snapshot): array
    {
        $this->updateExpectedServices($snapshot);

        $rules = (array) config('panel.monitoring.thresholds', []);
        $alerts = [];

        foreach ($rules as $rule) {
            if (! is_array($rule) || empty($rule['id']) || empty($rule['metric'])) {
                continue;
            }
            $level = $this->levelFor($rule, $snapshot);
            $previous = (string) $this->cache->get($this->stateKey($rule['id']), 'ok');

            if ($level !== $previous) {
                if ($this->shouldEmit($rule, $level, $previous, $snapshot)) {
                    $alert = new MonitoringAlert(
                        ruleId: (string) $rule['id'],
                        level: $level,
                        message: $this->messageFor($rule, $level, $snapshot),
                        currentValue: $this->extractValue($rule, $snapshot),
                        ts: $snapshot->ts,
                    );

                    $this->events->dispatch($alert);
                    $alerts[] = $alert->toArray();

                    $this->cache->put($this->stateKey($rule['id']), $level, self::STATE_TTL);
                }
            }
        }

        return $alerts;
    }

    /**
     * Active alerts (last-known non-ok level per rule). Used by HTTP
     * /alerts endpoint and read on first page load.
     *
     * @return array<int, array{rule_id: string, level: string}>
     */
    public function activeAlerts(): array
    {
        $rules = (array) config('panel.monitoring.thresholds', []);
        $active = [];

        foreach ($rules as $rule) {
            if (! is_array($rule) || empty($rule['id'])) {
                continue;
            }
            $level = (string) $this->cache->get($this->stateKey($rule['id']), 'ok');
            if ($level !== 'ok') {
                $active[] = ['rule_id' => (string) $rule['id'], 'level' => $level];
            }
        }

        return $active;
    }

    /**
     * Decide the current level for a rule against the snapshot.
     */
    protected function levelFor(array $rule, Snapshot $snapshot): string
    {
        $id = (string) $rule['id'];

        // service_down: special-cased — looks at the discrete service list
        if ($id === 'service_down') {
            return $this->levelForServiceDown($snapshot);
        }

        $value = $this->extractValue($rule, $snapshot);
        if ($value === null || ! is_numeric($value)) {
            return 'ok';
        }
        $value = (float) $value;

        // CPU load uses per-core scaling factors.
        if (isset($rule['warning_factor_per_core'], $rule['critical_factor_per_core'])) {
            $cores = max(1, (int) ($snapshot->entries['cpu']['cores'] ?? 1));
            $warningAt = $cores * (float) $rule['warning_factor_per_core'];
            $criticalAt = $cores * (float) $rule['critical_factor_per_core'];

            return $this->classify($value, $warningAt, $criticalAt);
        }

        // Memory uses percent computed from used vs total.
        if (isset($rule['warning_pct'], $rule['critical_pct'])) {
            $total = (float) ($snapshot->entries['memory']['total_kb'] ?? 0);
            if ($total <= 0) {
                return 'ok';
            }
            $pct = ($value / $total) * 100.0;

            return $this->classify(
                $pct,
                (float) $rule['warning_pct'],
                (float) $rule['critical_pct'],
            );
        }

        // Disk usage rule: glob over disk.*.used_pct, alert at the
        // highest mount above threshold.
        if (str_contains((string) $rule['metric'], '.*.')) {
            return $this->levelForDiskGlob($rule, $snapshot);
        }

        // Plain numeric rule with warning/critical thresholds.
        if (isset($rule['warning'], $rule['critical'])) {
            return $this->classify(
                $value,
                (float) $rule['warning'],
                (float) $rule['critical'],
            );
        }

        return 'ok';
    }

    protected function classify(float $value, float $warningAt, float $criticalAt): string
    {
        if ($value >= $criticalAt) {
            return 'critical';
        }
        if ($value >= $warningAt) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Highest level across all mounts whose metric beats the threshold.
     */
    protected function levelForDiskGlob(array $rule, Snapshot $snapshot): string
    {
        $mounts = (array) ($snapshot->entries['disk_usage'] ?? []);
        if (empty($mounts)) {
            return 'ok';
        }

        $worst = 'ok';
        foreach ($mounts as $row) {
            if (! is_array($row) || ! isset($row['used_pct'])) {
                continue;
            }
            $level = $this->classify(
                (float) $row['used_pct'],
                (float) ($rule['warning'] ?? PHP_FLOAT_MAX),
                (float) ($rule['critical'] ?? PHP_FLOAT_MAX),
            );
            if ($level === 'critical') {
                return 'critical';
            }
            if ($level === 'warning') {
                $worst = 'warning';
            }
        }

        return $worst;
    }

    /**
     * Critical when any expected-active service appears with status
     * !== 'active'. Returns 'ok' when nothing is wrong.
     */
    protected function levelForServiceDown(Snapshot $snapshot): string
    {
        $expected = (array) $this->cache->get(self::EXPECTED_SERVICES_KEY, []);
        if (empty($expected)) {
            return 'ok';
        }

        $current = (array) ($snapshot->entries['services'] ?? []);
        foreach ($current as $row) {
            if (! is_array($row) || empty($row['unit'])) {
                continue;
            }
            $unit = (string) $row['unit'];
            if (! isset($expected[$unit])) {
                continue;
            }
            if (($row['status'] ?? '') !== 'active') {
                return 'critical';
            }
        }

        return 'ok';
    }

    /**
     * On every tick, register units we've seen active so that subsequent
     * "service_down" evaluations have an expected-set baseline.
     */
    protected function updateExpectedServices(Snapshot $snapshot): void
    {
        $current = (array) ($snapshot->entries['services'] ?? []);
        if (empty($current)) {
            return;
        }
        $expected = (array) $this->cache->get(self::EXPECTED_SERVICES_KEY, []);

        $changed = false;
        foreach ($current as $row) {
            if (! is_array($row) || ($row['status'] ?? '') !== 'active') {
                continue;
            }
            $unit = (string) ($row['unit'] ?? '');
            if ($unit === '') {
                continue;
            }
            if (! isset($expected[$unit])) {
                $expected[$unit] = $snapshot->ts;
                $changed = true;
            }
        }

        if ($changed) {
            $this->cache->put(self::EXPECTED_SERVICES_KEY, $expected, self::EXPECTED_SERVICES_TTL);
        }
    }

    /**
     * Extract the metric's value from the snapshot, supporting nested
     * paths via `.` separator and the special `disk.*.used_pct` form.
     */
    protected function extractValue(array $rule, Snapshot $snapshot): mixed
    {
        $metric = (string) $rule['metric'];

        // disk.*.used_pct → return the maximum across all mounts.
        if (str_contains($metric, '.*.')) {
            $mounts = (array) ($snapshot->entries['disk_usage'] ?? []);
            $field = $this->lastSegment($metric);
            $max = null;
            foreach ($mounts as $row) {
                if (! is_array($row) || ! isset($row[$field])) {
                    continue;
                }
                $val = (float) $row[$field];
                if ($max === null || $val > $max) {
                    $max = $val;
                }
            }

            return $max;
        }

        // services placeholder — see service_down handling above.
        if ($metric === 'services') {
            return null;
        }

        // Standard dot path against entries (e.g. cpu.loadavg.1m, mem.used_kb).
        $parts = explode('.', $metric);
        $top = array_shift($parts);
        $cursor = $snapshot->entries[$top] ?? null;

        foreach ($parts as $part) {
            if (! is_array($cursor)) {
                return null;
            }
            $cursor = $cursor[$part] ?? null;
        }

        return $cursor;
    }

    protected function shouldEmit(array $rule, string $newLevel, string $previousLevel, Snapshot $snapshot): bool
    {
        // Sustained-time rules wait until the breach has held for N seconds
        // before flipping. We track the first-cross timestamp under a
        // companion cache key.
        $sustained = (int) ($rule['sustained_seconds'] ?? 0);
        if ($sustained > 0 && $newLevel !== 'ok' && $previousLevel === 'ok') {
            $firstCrossKey = $this->stateKey($rule['id']).':first_cross';
            $firstCross = (int) $this->cache->get($firstCrossKey, 0);
            if ($firstCross === 0) {
                $this->cache->put($firstCrossKey, $snapshot->ts, self::STATE_TTL);

                return false;
            }
            if ($snapshot->ts - $firstCross < $sustained) {
                return false;
            }
            // Threshold has held — clear the marker for the next cycle.
            $this->cache->forget($firstCrossKey);
        }

        if ($newLevel === 'ok') {
            // Recovery: also clear the first-cross marker.
            $this->cache->forget($this->stateKey($rule['id']).':first_cross');
        }

        return true;
    }

    protected function messageFor(array $rule, string $level, Snapshot $snapshot): string
    {
        $value = $this->extractValue($rule, $snapshot);
        $id = (string) $rule['id'];
        $valStr = is_scalar($value) ? (string) $value : '—';

        if ($level === 'ok') {
            return "[ok] {$id} recovered";
        }

        return sprintf('[%s] %s → %s', $level, $id, $valStr);
    }

    protected function stateKey(string $ruleId): string
    {
        return self::STATE_KEY_PREFIX.$ruleId;
    }

    protected function lastSegment(string $metric): string
    {
        $parts = explode('.', $metric);

        return (string) end($parts);
    }
}
