## ADDED Requirements

### Requirement: VPS metric collection lifecycle
The system SHALL sample host metrics every 5 seconds via a long-running supervisord-managed artisan command, persist samples to a dedicated SQLite database, and broadcast a snapshot per tick to a private Reverb channel.

#### Scenario: Tick produces a snapshot
- **WHEN** the `hermes:monitoring-tick` artisan command runs
- **THEN** each iteration invokes every registered Reader (cpu, memory, uptime, disk usage, disk i/o, network, connections, process, services, ports), records numeric metrics to `samples_raw`, updates `latest_snapshot`, and dispatches `App\Events\MonitoringSnapshot` on `private-monitoring.host`

#### Scenario: Reader failure isolated
- **WHEN** a single reader throws during sampling
- **THEN** the snapshot's `errors[<reader_key>]` carries the exception message; other readers' output is preserved; broadcast fires normally

#### Scenario: Maintenance every minute
- **WHEN** the tick-loop has run 12 iterations (≈ 1 minute at default cadence)
- **THEN** the previous-minute aggregate is computed into `samples_1m` and rows past retention are pruned from both `samples_raw` (1 h) and `samples_1m` (24 h)

### Requirement: Threshold evaluation with hysteresis
The system SHALL evaluate configured thresholds against each snapshot, emit `App\Events\MonitoringAlert` only on level transitions, and return active non-ok rule states for HTTP `/alerts`.

#### Scenario: First cross of a sustained rule defers emission
- **WHEN** a rule with `sustained_seconds = 60` first crosses into warning/critical
- **THEN** no alert is emitted on that tick; a first-cross timestamp is cached

#### Scenario: Sustained breach emits when window elapses
- **WHEN** the same rule remains in breach for ≥ `sustained_seconds`
- **THEN** a `MonitoringAlert` event fires and the rule's cached state is updated

#### Scenario: Recovery emits clear
- **WHEN** a rule transitions from non-ok back to `ok`
- **THEN** a clear alert (level = `ok`) is emitted and the first-cross marker is cleared

#### Scenario: Disk glob picks worst mount
- **WHEN** the `disk_used` rule (metric `disk.*.used_pct`) sees multiple mounts
- **THEN** the rule level is the highest classification across all mounts (critical wins over warning wins over ok)

#### Scenario: Service down requires prior active observation
- **WHEN** the `service_down` rule sees a unit with `status != 'active'`
- **THEN** the rule fires only if that unit was observed `active` in the last 5 minutes (key `hermes:monitoring:expected_services`)

### Requirement: HTTP API surface
The system SHALL expose monitoring data via JSON endpoints behind the `OwnerAccess` middleware. Endpoints are documented below.

#### Scenario: Snapshot endpoint requires auth
- **WHEN** an unauthenticated client requests `GET /panel/api/monitoring/snapshot`
- **THEN** the system responds with HTTP 401 and a JSON `{error}` body

#### Scenario: Snapshot returns empty + error before first sample
- **WHEN** the tick-loop has not yet recorded a sample
- **THEN** the snapshot endpoint returns `{ts: null, entries: [], alerts: [], errors: {storage: '…'}}`

#### Scenario: Snapshot returns latest payload
- **WHEN** the tick-loop has recorded at least one sample
- **THEN** the endpoint returns the most recent snapshot's `{ts, entries, alerts, errors}` shape

#### Scenario: Series enforces validation
- **WHEN** the client posts `metrics` array with > 20 entries or omits the field entirely
- **THEN** the system returns HTTP 422

#### Scenario: Series resolution selection
- **WHEN** the `window` parameter is `5m`, `15m`, or `1h`
- **THEN** the response uses raw 5-second resolution
- **WHEN** the `window` is `6h` or `24h`
- **THEN** the response uses 1-minute aggregate resolution

#### Scenario: Discrete state endpoints surface latest snapshot
- **WHEN** the client requests `GET /panel/api/monitoring/services` (or `/processes`, `/ports`)
- **THEN** the system returns `latest_snapshot.entries.<key>` directly

#### Scenario: Active alerts endpoint
- **WHEN** the client requests `GET /panel/api/monitoring/alerts`
- **THEN** the system returns `{active: [{rule_id, level}]}` from the cached threshold state

#### Scenario: Service refresh clears discovery cache
- **WHEN** the client posts `POST /panel/api/monitoring/services/refresh`
- **THEN** the cache key `hermes:monitoring:services:units` is forgotten and the next tick re-discovers via systemctl/pgrep

### Requirement: Private channel auth
The system SHALL gate `private-monitoring.host` subscriptions through the same dual-flag auth model as the v3.1 terminal channel.

#### Scenario: Reject in trusted-network bypass mode
- **WHEN** `PANEL_AUTH_ENABLED=false` and `PANEL_DEV_BYPASS=true`
- **THEN** the channel callback returns `false`

#### Scenario: Reject without active session
- **WHEN** auth is enforced and the request lacks `session('panel_auth') === true`
- **THEN** the channel callback returns `false`

#### Scenario: Accept with active session
- **WHEN** auth is enforced and the session is authenticated
- **THEN** the callback returns presence-style payload `{user: 'panel'}`

### Requirement: Storage layout & retention
The system SHALL persist monitoring data to `storage/monitoring.sqlite` (WAL mode) with the following schema and retention rules.

#### Scenario: Schema bootstrapped on tick-loop boot
- **WHEN** the tick-loop starts
- **THEN** tables `samples_raw`, `samples_1m`, and `latest_snapshot` are created if missing (idempotent)

#### Scenario: Numeric metrics flatten into samples_raw
- **WHEN** `MetricStorage::recordSample()` runs for a snapshot
- **THEN** numeric leaves of `entries.{cpu, memory, disk_usage, disk_io, network, connections}` are inserted as `(ts, metric, value)` rows; discrete state (process/services/ports) lives only in `latest_snapshot.payload`

#### Scenario: Retention boundaries enforced
- **WHEN** `MetricStorage::prune(now)` runs
- **THEN** rows in `samples_raw` older than 1 h and rows in `samples_1m` older than 24 h are deleted; `PRAGMA wal_checkpoint(TRUNCATE)` runs to reclaim WAL disk
