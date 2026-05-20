# Hermes Panel v3.2 — VPS Monitoring Design

**Date:** 2026-05-20
**Sub-project:** v3.2 (second of v3 umbrella: terminal → **monitoring** → multi-language → server admin)
**Status:** Design approved, awaiting spec review before writing implementation plan
**Author:** Brainstormed collaboratively with the panel owner (sequential decisions, see Decisions Log)

---

## 1. Architecture Overview

```
┌─ Container ──────────────────────────────────────────────────┐
│                                                              │
│  ┌─ supervisord ──┐                                          │
│  │ php-fpm        │                                          │
│  │ nginx          │                                          │
│  │ reverb (8081)  │                                          │
│  │ terminal-tick  │   ← v3.1                                 │
│  │ monitoring-tick│   ← v3.2 (new, 5s sleep loop)            │
│  └────────────────┘                                          │
│         │                                                    │
│         │ MetricCollector                                    │
│         ↓                                                    │
│   ┌───────────────────────────────────────────────────────┐  │
│   │ readers (auto-detect /host/proc fallback /proc)       │  │
│   │  - CpuReader        /proc/stat, /proc/loadavg         │  │
│   │  - MemoryReader     /proc/meminfo                     │  │
│   │  - DiskUsageReader  df -P                              │  │
│   │  - DiskIoReader     /proc/diskstats (delta)            │  │
│   │  - NetworkReader    /proc/net/dev (delta)              │  │
│   │  - UptimeReader     /proc/uptime, /proc/stat btime     │  │
│   │  - ProcessReader    /proc/[pid]/{stat,status}          │  │
│   │  - ServiceReader    systemctl + pgrep fallback         │  │
│   │  - PortReader       ss -tlnp                           │  │
│   │  - ConnectionReader /proc/net/tcp{,6} count            │  │
│   └───────────────────────────────────────────────────────┘  │
│         │                                                    │
│         ↓                                                    │
│   MetricStorage (SQLite at storage/monitoring.sqlite)        │
│         │                                                    │
│         ├─ samples_raw    (5s, retain 1h)                    │
│         ├─ samples_1m     (1m aggregate, retain 24h)          │
│         └─ latest_snapshot (full JSON blob, single row)       │
│                                                              │
│         ↑                                                    │
│         │                                                    │
│   ThresholdEvaluator → MonitoringAlert (in-process event)    │
│         │                                                    │
│         ↓                                                    │
│   broadcast(MonitoringSnapshot) on private channel           │
│                                                              │
└──────────────────────────────────────────────────────────────┘
                  │
                  │ wss + HTTP
                  ↓
┌─ Browser ────────────────────────────────────────────────────┐
│                                                              │
│  Dashboard strip          Tab ζ Monitoring                   │
│  ─ 4 sparkline (CPU/MEM/  ─ uPlot full charts                │
│    DISK/NET) updates       ─ services table                  │
│    via Echo private        ─ process top + ports             │
│    channel                 ─ alert log (in-session)          │
│                                                              │
│  Auth-bypass mode →       (same data via HTTP poll every     │
│  fallback poll             5s instead of WS)                  │
│  /api/monitoring/snapshot                                    │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

### New components

| Layer        | Class / file                                                                |
| ------------ | --------------------------------------------------------------------------- |
| Service      | `App\Services\Monitoring\MetricCollector`                                   |
| Service      | `App\Services\Monitoring\ProcResolver`                                      |
| Service      | `App\Services\Monitoring\MetricStorage`                                     |
| Service      | `App\Services\Monitoring\ThresholdEvaluator`                                |
| Readers      | `App\Services\Monitoring\Readers\{Cpu,Memory,DiskUsage,DiskIo,Network,Uptime,Process,Service,Port,Connection}Reader` |
| Command      | `App\Console\Commands\MonitoringTickLoop` (`hermes:monitoring-tick`)        |
| Event        | `App\Events\MonitoringSnapshot` (broadcast)                                 |
| Event        | `App\Events\MonitoringAlert` (in-process; v3.2.1 swaps notifier)            |
| Controller   | `App\Http\Controllers\Panel\MonitoringController`                           |
| Channel      | `monitoring.host` (private, gated like terminal channel)                    |
| Storage      | `storage/monitoring.sqlite` (WAL, dedicated PDO connection)                 |
| Frontend     | `resources/js/monitoring/{index,snapshot-store,charts,series}.js`           |
| Frontend     | `resources/views/panel/monitoring.blade.php` (full tab)                     |
| Frontend     | `resources/views/panel/_dashboard_health_strip.blade.php` (partial)         |

### Data flow

1. `monitoring-tick` artisan loop: every 5 s call `MetricCollector::sample()` → invoke each Reader → returns `Snapshot` value object
2. `MetricStorage::recordSample($snapshot)` writes to `samples_raw` and updates `latest_snapshot`
3. `ThresholdEvaluator::evaluate($snapshot)` checks against config thresholds with hysteresis, emits `MonitoringAlert` events on transitions
4. `broadcast(new MonitoringSnapshot(...))` pushes to `monitoring.host` private channel
5. Every 12th tick (~ 1 minute): tick-loop computes 1m aggregate from last 60 s of raw samples → write `samples_1m`, prune `samples_raw` past 1 h, prune `samples_1m` past 24 h
6. Browser subscribed via Echo: receives snapshot → updates Alpine store → uPlot redraws + sparklines + alert visuals
7. Trusted-network bypass mode: browser polls `GET /panel/api/monitoring/snapshot` every 5 s instead

### Integration with v3.1 patterns

- Same supervisord-managed long-running command pattern (signal trap, idle-safe loop, dedicated log channel)
- Same channel-auth pattern (gate on `auth_enabled` + session, return false in bypass mode)
- Same fallback strategy (sync HTTP endpoint when bypass mode disables WS)
- Reuse Reverb infrastructure — no new broadcaster

---

## 2. Collector & Readers

### `MetricCollector` orchestrator

Iterates all registered readers, captures per-reader failures into `errors[]` instead of failing the whole snapshot. Returns a `Snapshot` value object.

### `ProcResolver` path abstraction

Centralizes `/proc` and `/sys` access with autodetect: `/host/proc` if mounted (container), else `/proc` (host or local dev). Bound as singleton.

### `Reader` interface

```php
interface Reader
{
    public function key(): string;
    public function read(ProcResolver $proc): array;
}
```

### Reader inventory

| Reader              | Output shape                                                                                  | Source                                                |
| ------------------- | --------------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| `CpuReader`         | `{loadavg:{1m,5m,15m}, cores, usage_pct_total, per_core[]}`                                   | `/proc/loadavg`, `/proc/stat` (delta from last call)  |
| `MemoryReader`      | `{total_kb, used_kb, free_kb, available_kb, buffers_kb, cached_kb, swap_total_kb, swap_used_kb}` | `/proc/meminfo`                                       |
| `DiskUsageReader`   | `[{mount, fs, total_bytes, used_bytes, free_bytes, used_pct}]`                                | `df -P --output=source,target,fstype,size,used,avail` |
| `DiskIoReader`      | `{read_bytes_per_sec, write_bytes_per_sec, per_device:[...]}`                                 | `/proc/diskstats` (delta)                             |
| `NetworkReader`     | `[{iface, rx_bytes_per_sec, tx_bytes_per_sec, rx_errs, tx_errs}]`                             | `/proc/net/dev` (delta)                               |
| `UptimeReader`      | `{boot_time_unix, uptime_seconds}`                                                            | `/proc/uptime`, `/proc/stat` btime                    |
| `ProcessReader`     | `[{pid, name, cmd, cpu_pct, rss_kb}]` top 5 by CPU + top 5 by RSS, deduped (≤ 10 rows)        | `/proc/[pid]/stat` & `/proc/[pid]/status`             |
| `ServiceReader`     | `[{unit, status:'active'|'inactive'|'failed'|'unknown', detection:'systemd'|'pgrep'}]`        | `systemctl list-units` then `pgrep` fallback          |
| `PortReader`        | `[{port, proto, address, pid, process_name}]` listening only                                  | `ss -tlnp` (or `netstat -tlnp` fallback)              |
| `ConnectionReader`  | `{tcp_established:int}`                                                                       | `/proc/net/tcp` & `tcp6` (state == 01 count)          |

### Delta readers — first-sample handling

CPU, DiskIo, Network are rate-based. First call after panel boot has no prior reading, so delta is unknown. Each delta reader caches its prior reading and returns `'first_sample' => true` with raw counters when no prior exists. `MetricStorage` skips rate-based aggregation for first-sample rows.

### Service detection

`ServiceReader` discovers units via `systemctl list-units --type=service --state=running,loaded` matched against a whitelist of patterns (`nginx*`, `mysql*`, `postgres*`, `redis*`, `php-fpm*`, `docker`, `caddy`, `traefik`, etc.) configured in `config/panel.php` `monitoring.service_patterns`. Cache the discovered list for 60 s. When `systemctl` is unavailable (container without systemd socket mount), falls back to `pgrep -x` against the same patterns; status flips to `active`/`inactive` based on whether any matching process is running. Manual refresh button in UI clears the cache.

### Failure isolation

Individual reader exceptions never propagate. `MetricCollector` wraps each `read()` call in try/catch, records the error message under `errors[reader.key()]`, and continues. The UI shows `?` placeholders for missing readers without faking data.

---

## 3. Storage & Threshold

### SQLite at `storage/monitoring.sqlite`

Dedicated database — panel-DB outages don't kill monitoring, and monitoring DB issues don't kill the panel. Configured as a Laravel connection so we use the query builder, but not affected by panel default DB migrations.

```php
'monitoring' => [
    'driver' => 'sqlite',
    'database' => storage_path('monitoring.sqlite'),
    'foreign_key_constraints' => false,
    'busy_timeout' => 5000,
    'journal_mode' => 'WAL',
    'synchronous' => 'NORMAL',
],
```

### Schema

```sql
CREATE TABLE samples_raw (
    ts INTEGER NOT NULL,
    metric TEXT NOT NULL,
    value REAL NOT NULL,
    PRIMARY KEY (ts, metric)
) WITHOUT ROWID;
CREATE INDEX idx_samples_raw_ts ON samples_raw(ts);

CREATE TABLE samples_1m (
    ts INTEGER NOT NULL,
    metric TEXT NOT NULL,
    avg REAL NOT NULL,
    min REAL NOT NULL,
    max REAL NOT NULL,
    PRIMARY KEY (ts, metric)
) WITHOUT ROWID;
CREATE INDEX idx_samples_1m_ts ON samples_1m(ts);

CREATE TABLE latest_snapshot (
    ts INTEGER NOT NULL,
    payload TEXT NOT NULL,
    PRIMARY KEY (ts)
) WITHOUT ROWID;
```

### `MetricStorage` gateway

- `recordSample(Snapshot)` — flatten numeric metrics into `samples_raw`, write JSON blob to `latest_snapshot`, drop older latest rows opportunistically, all in one transaction
- `aggregateMinute(int $minuteBoundaryTs)` — compute avg/min/max from preceding 60 s of raw samples
- `prune(int $now)` — delete `samples_raw` past 1 h and `samples_1m` past 24 h
- `latestSnapshot(): ?array` — single-row read for HTTP `/snapshot` and Series-tab initial load
- `rangeRaw(metric, fromTs, toTs)` — values keyed by ts for chart backfill (≤ 1 h windows)
- `rangeMinute(metric, fromTs, toTs)` — avg/min/max rows keyed by ts (> 1 h windows)

### Discrete state

Service list, port list, and process list are stored only in `latest_snapshot` (JSON blob). They're discrete state, not numeric time-series; no point in flattening into `samples_raw`. Charts consume only numeric metrics; tables in the UI consume `latest_snapshot.entries.{services,process,ports}` directly.

### `ThresholdEvaluator`

Default rules in `config/panel.php`:

```php
'monitoring' => [
    'thresholds' => [
        ['id' => 'cpu_load',     'metric' => 'cpu.loadavg.1m',  'warning_factor_per_core' => 1.5, 'critical_factor_per_core' => 2.0, 'sustained_seconds' => 60],
        ['id' => 'mem_used',     'metric' => 'mem.used_kb',     'warning_pct' => 90, 'critical_pct' => 95],
        ['id' => 'disk_used',    'metric' => 'disk.*.used_pct', 'warning' => 90,     'critical' => 95],
        ['id' => 'service_down', 'metric' => 'services',        'critical_when' => 'expected_active_but_not'],
    ],
],
```

> **Note on `service_down`**: "expected" means the unit appeared in the
> whitelist-discovered list at any point in the last 5 minutes (cached
> as `hermes:monitoring:expected_services`). A unit that has never been
> seen active won't trigger the alert — prevents false positives for
> services that exist but are deliberately stopped.

Hysteresis: state cached at `hermes:monitoring:threshold:{rule_id}` with 10-min TTL. `MonitoringAlert` event fires only on level transitions (ok → warning, warning → critical, critical → ok). Sustained-time rules track first-cross timestamp and only fire when current ts − first-cross ≥ `sustained_seconds`.

### Alert delivery

`MonitoringAlert` is in-process for v3.2 (decision #7-A: visual only). Alerts also embed in the snapshot broadcast payload as `alerts: [...]` — one event type, single WS contract. v3.2.1 can add a listener that pushes WhatsApp/email without touching `ThresholdEvaluator`.

### SQLite size budget

- Raw: 720 rows/metric × ~12 metrics ≈ 8,640 rows × ~30 B = ~260 KB
- 1m aggregate: 1,440 rows/metric × 12 metrics ≈ 17,280 rows × ~50 B = ~850 KB
- WAL file ≤ ~1 MB during checkpoints
- Total at saturation ≈ 2 MB, well under the 10 MB budget from decision #3-B

`PRAGMA wal_checkpoint(TRUNCATE)` runs after `prune()` once per minute.

---

## 4. Tick-Loop & Broadcasting

### `MonitoringTickLoop` artisan command

Same shape as `TerminalTickLoop` (v3.1-04): supervisord-managed, signal-trapped, dedicated log channel. Different cadence (5 s vs 100 ms) and different responsibilities.

```php
class MonitoringTickLoop extends Command
{
    protected $signature = 'hermes:monitoring-tick
        {--max-iterations=0 : Stop after N iterations (0 = run forever)}
        {--sleep=5 : Seconds between samples}';

    public const PRUNE_EVERY_N_ITERATIONS = 12;     // every minute at 5s cadence
    public const AGGREGATE_EVERY_N_ITERATIONS = 12; // 1-minute aggregate boundary
}
```

Loop responsibilities per iteration:

1. Sample via `MetricCollector::sample()`
2. Evaluate thresholds → emit `MonitoringAlert` on transitions
3. `MetricStorage::recordSample()`
4. Every 12th iteration: aggregate previous minute, prune retention windows
5. Broadcast `MonitoringSnapshot` event with `entries`, `alerts`, `errors`

### Drift correction

After each `tick()`, sleep for `max(0, sleep_seconds - elapsed)` so cadence stays stable even when collector or storage take measurable time.

### Supervisord entry

```ini
[program:monitoring-tick]
command=php artisan hermes:monitoring-tick
directory=/var/www/html
user=hermes
group=hermes
autostart=true
autorestart=true
startsecs=2
stopsignal=TERM
stopwaitsecs=10
stdout_logfile=/dev/stdout
stderr_logfile=/dev/stderr
```

### Log channel

```php
'monitoring-tick' => [
    'driver' => 'single',
    'path' => storage_path('logs/monitoring-tick.log'),
    'level' => env('LOG_LEVEL', 'info'),
    'replace_placeholders' => true,
],
```

### `MonitoringSnapshot` broadcast event

```php
class MonitoringSnapshot implements ShouldBroadcast
{
    public function __construct(public array $payload) {}

    public function broadcastOn(): array { return [new PrivateChannel('monitoring.host')]; }
    public function broadcastAs(): string { return 'monitoring.snapshot'; }
    public function broadcastWith(): array { return $this->payload; }
}
```

Single private channel `monitoring.host`. Single event type. Payload carries `{ts, entries, alerts, errors}`.

### Channel auth (`routes/channels.php`)

```php
Broadcast::channel('monitoring.host', function ($user) {
    if (! config('panel.auth_enabled', true) && config('panel.dev_bypass', false)) {
        return false;     // bypass mode → fall back to HTTP poll
    }
    if (! session('panel_auth')) {
        return false;
    }
    return ['user' => 'panel'];
});
```

### Failure handling

| Failure mode                          | Behavior                                                                |
| ------------------------------------- | ----------------------------------------------------------------------- |
| One reader throws                     | Logged in `errors[]`, snapshot continues, broadcast fires with that key absent |
| Storage write fails                   | Logged, broadcast still fires (UI gets live data, history misses one tick) |
| Reverb is down                        | `event()` fails silently; samples still write to SQLite; HTTP `/snapshot` keeps working |
| SQLite locked briefly                 | `busy_timeout=5000` waits up to 5 s; if still locked, log warning, skip storage but broadcast |
| All readers fail (broken `/proc`)     | Snapshot has empty `entries`, full `errors`; UI shows degraded banner   |
| Supervisord restart                   | Loop reboots, no orphan to clean (storage is durable; cache thresholds re-evaluate) |

---

## 5. HTTP API & Frontend

### HTTP endpoints

All under `panel/api/monitoring`, behind `OwnerAccess` middleware.

| Method | Path                                       | Purpose                                                               |
| ------ | ------------------------------------------ | --------------------------------------------------------------------- |
| GET    | `/panel/api/monitoring/snapshot`           | Latest sample (poll fallback for bypass mode + initial page load)    |
| GET    | `/panel/api/monitoring/series`             | Historical range query for charts (validates metrics array, window)  |
| GET    | `/panel/api/monitoring/services`           | Discrete state: service list with current status                     |
| GET    | `/panel/api/monitoring/processes`          | Discrete state: top processes                                         |
| GET    | `/panel/api/monitoring/ports`              | Discrete state: listening ports                                       |
| GET    | `/panel/api/monitoring/alerts`             | Active threshold violations + recent transitions                      |
| POST   | `/panel/api/monitoring/services/refresh`   | Force re-discover service whitelist (clears 60s cache)                |

### Series resolution rules

`window=5m,15m,1h` → return raw 5 s samples (≤ 720 rows/metric)
`window=6h,24h` → return 1m aggregates (≤ 1,440 rows/metric)

Validation: `metrics` is required array, max 20 entries, each ≤ 128 chars.

### Frontend file layout

```
resources/js/monitoring/
├── index.js            (Alpine factory: hermesMonitoring())
├── snapshot-store.js   (singleton — receives WS events, exposes reactive state)
├── charts.js           (uPlot factory + sparkline factory + theme tokens)
└── series.js           (HTTP series fetcher with debounce)

resources/views/panel/
├── monitoring.blade.php                (full tab)
└── _dashboard_health_strip.blade.php   (Dashboard partial)
```

### Snapshot store singleton

A single subscriber to the `monitoring.host` channel. Multiple Alpine components (Dashboard strip, full Monitoring tab) register callbacks; the store fans out the latest snapshot to all of them. Avoids each component opening its own WS subscription.

In bypass mode, the store falls back to polling `GET /panel/api/monitoring/snapshot` every 5 s. Detection via `<meta name="hermes-auth-bypass">` set by the layout.

### Dashboard strip

Compact 4-card row at top of Dashboard: CPU, RAM, disk, network. Each card shows a current value (font Fraunces italic) and a 60-point rolling sparkline (uPlot mini-chart). Card border turns copper on warning, rust on critical. Click any card → navigate to `/panel/monitoring#<metric>` for deep dive.

### Full Monitoring tab

```
┌─ Header strip (4 sparklines, same as Dashboard but bigger) ─────┐
│  CPU 23%   RAM 4.2/8 GB   Disk 67%   ↓5KB/s ↑12KB/s             │
└──────────────────────────────────────────────────────────────────┘

┌─ Window selector ─────────────────────────────────────────────┐
│  [5m] [15m] [1h] [6h] [24h]                                     │
└──────────────────────────────────────────────────────────────────┘

┌─ Charts grid ────────────────────────────────────────────────┐
│ CPU load (line)              │ Memory used (area + swap)         │
│ Disk I/O (read/write split)  │ Network I/O (rx/tx split)         │
│ Disk usage per mount (bars)  │ TCP connections (line)            │
└──────────────────────────────────────────────────────────────────┘

┌─ Services table ──────────────────────────────────────────────┐
│  unit            status     detection     [refresh]            │
└──────────────────────────────────────────────────────────────────┘

┌─ Process top + Listening ports (collapsed by default) ────────┐
└──────────────────────────────────────────────────────────────────┘

┌─ Alerts log (sticky bottom) ──────────────────────────────────┐
└──────────────────────────────────────────────────────────────────┘
```

### uPlot theme

Editorial dark palette mapped from CSS tokens:

```js
export const monitoringChartTheme = {
    height: 200,
    cursor: { drag: { x: true, y: false } },
    scales: { x: { time: true } },
    axes: [
        { stroke: '#8a8275', grid: { stroke: 'rgba(244, 237, 225, 0.10)' } },
        { stroke: '#8a8275', grid: { stroke: 'rgba(244, 237, 225, 0.10)' } },
    ],
    series: [
        {},
        { stroke: '#d4a45c', width: 1.5, fill: 'rgba(212, 164, 92, 0.10)' },
    ],
};
```

---

## 6. Test Plan

### Pyramid

```
       ╱╲    Browser/E2E (Playwright) — 1 test
      ╱  ╲
     ╱    ╲  Feature (Laravel) — ~10 tests
    ╱      ╲
   ╱        ╲ Unit (PHPUnit) — ~6 test classes
   ──────────
```

### Unit tests

- `ProcResolverTest` — autodetect, path joining, missing file throws
- `MetricCollectorTest` — reader iteration, error isolation, ordering
- Per-reader tests for the four most logic-heavy: `CpuReader`, `MemoryReader`, `ServiceReader`, plus delta-aware `DiskIoReader` first-sample handling. Other readers covered indirectly via `MetricCollectorTest` fixtures.
- `MetricStorageTest` — schema bootstrap, write/read/aggregate/prune cycle on `:memory:`
- `ThresholdEvaluatorTest` — first cross emits, no spurious re-fire, transition warning↔critical, recovery emits clear, sustained-time rule waits N seconds, cache state survives between calls

### Feature tests

`MonitoringApiTest` — auth gate, snapshot endpoint, series resolution rules, validation, services/processes/ports endpoints, refresh-services endpoint
`MonitoringChannelAuthTest` — same shape as `TerminalChannelAuthTest`: rejects bypass, rejects no-session, accepts active session
`MonitoringTickLoopTest` — `--max-iterations=2` exits 0; Event::fake catches `MonitoringSnapshot` after 1 iteration; samples_1m row exists after 12 iterations; reader-failure isolated path

### E2E test

`tests/e2e/monitoring.spec.ts` — open `/panel/dashboard`, assert health strip; click CPU card → navigate to `/panel/monitoring#cpu`; assert chart grid + services table render; switch window selector → asserts `GET /series` fired

Skip-when-unreachable wrapper used (same pattern as v3.1-08).

### Coverage targets

- `MetricCollector`, `ProcResolver`, `ThresholdEvaluator` — 100% (security-adjacent)
- All readers — happy path + 1 failure path each
- `MetricStorage` — write/read/aggregate/prune cycle covered
- `MonitoringController` — feature tests cover all endpoints
- `MonitoringTickLoop` — single-iteration + signal-handling feature tests

Total expected: ~55 new tests / ~120 new assertions on top of v3.1's 95.

---

## 7. Rollout Plan

### Story decomposition

| #  | Story                                                    | Deps    | Effort |
| -- | -------------------------------------------------------- | ------- | ------ |
| 1  | Mount /proc, /sys + ProcResolver + path autodetect       | —       | S      |
| 2  | Reader interface + Cpu/Memory/Uptime readers             | 1       | M      |
| 3  | DiskUsage/DiskIo/Network/Connection readers              | 2       | M      |
| 4  | Process/Service/Port readers + whitelist patterns        | 2       | M      |
| 5  | MetricStorage SQLite schema + WAL + aggregate/prune      | 2       | M      |
| 6  | MonitoringTickLoop + supervisord + ThresholdEvaluator    | 3, 4, 5 | M      |
| 7  | HTTP API + channel auth + MonitoringController           | 5, 6    | M      |
| 8  | Frontend (uPlot, snapshot store, dashboard strip, full tab) + E2E | 7  | M      |

Stories 1-2 are independent of v3.1, can start anytime. Stories 3-4 are reader bundles. Story 7 depends on the full backend chain. Story 8 closes the sprint.

### Migration & breaking changes

- `docker-compose.yml` adds two read-only volume mounts (`/proc`, `/sys`). Existing deployments need `docker compose up -d --build` to apply.
- Sidebar gains a 6th tab (`ζ Monitoring`). New route `/panel/monitoring`.
- `storage/monitoring.sqlite` is created on first tick-loop boot. Already covered by `storage/*` gitignore pattern.
- One new optional env var: `PANEL_MONITORING_SAMPLE_INTERVAL` (defaults 5s).

### Verification gates per story

- `./vendor/bin/pint` clean
- biome check on JS/TS files
- Unit + Feature tests green
- Manual smoke (story-specific)
- code-review skill on diff
- OpenSpec spec updated where contract changes (story 7)

### Documentation deliverables

- This design doc (current file)
- BMAD stories: `docs/bmad/stories/v3.2-{01..08}-*.md` + INDEX
- OpenSpec capability spec at `openspec/changes/vps-monitoring-v32/specs/vps-monitoring/spec.md` (story 7)
- README "VPS monitoring" section (story 8 finalizes)
- agents.md section 15 update (story 8)

### Effort estimate

~10–14 days sequential at 1–2 h/day; ~6–8 days with 4-hour focus blocks. Slightly larger than v3.1 because of 9 readers + new chart UI.

---

## Decisions Log

| #   | Decision                                                          | Rationale                                                                           |
| --- | ----------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| 1   | Custom-built (option C), not Netdata/Glances integration           | Theme consistency, footprint, tight integration with active project context         |
| 2   | Tier 1 + Tier 2 metrics (option B)                                 | Covers ~95% of dev VPS daily checks; Tier 3 deferred until specific need            |
| 3   | 5 s sampling, raw 1 h + 1m-aggregate 24 h (option B)               | Sweet spot: snappy live feel without WS flood; 24 h is enough for "what happened pagi tadi" |
| 4   | Private channel `monitoring.host` (option B)                       | Consistent with v3.1 terminal channel; bypass mode falls back to HTTP poll          |
| 5   | Auto-discover services via systemd whitelist (option B + sub-i)    | Works out-of-box for common stacks; pgrep fallback when systemctl unavailable; cache 60s |
| 6   | View-only service health (option A)                                | Public domain → privilege escalation = blast radius; restart via SSH or v3.1 terminal |
| 7   | Visual-only alerts (option A) with extensible event structure       | Avoid WhatsApp gateway integration uncertainty; v3.2.1 swaps notifier when needed   |
| 8   | Strip in Dashboard + dedicated tab `ζ Monitoring` (option B)       | Glance value at landing + deep dive on demand                                        |
| 9   | Dedicated `hermes:monitoring-tick` process (option B)               | Single responsibility; doesn't overload terminal-tick                                |
| 10  | SQLite at `storage/monitoring.sqlite` (option A)                   | File-based, predictable, visible to operator; single-writer + WAL = no contention   |
| 11  | uPlot for all charts (option B)                                    | Time-series specialist, ~30 KB, matches editorial dark theme via tokens             |
| 12  | Mount `/proc`, `/sys` read-only into container (option A)          | Container isolation kept; consistent with node-exporter pattern; single change to compose |
| 13  | Scope cut accepted as listed                                       | Tier 3, service control, multi-host, exports all deferred                           |

---

## Out of Scope (Confirmed)

- Tier 3 metrics (GPU, SMART, sensor temp, per-process I/O detail)
- Service control (start/stop/restart) — view-only
- Notification escalation (WhatsApp, email, browser push) — visual-only with extensible event
- Multi-host monitoring (single host: the one panel runs on)
- Custom user-defined metrics (no plugin system)
- Long-term retention > 24 h
- Export/download metric dataset
- Compare across time windows
- Network packet capture / pcap
- Database-specific monitoring (lives in Database Manager, not VPS monitoring)
