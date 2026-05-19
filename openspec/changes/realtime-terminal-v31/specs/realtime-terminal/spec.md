## ADDED Requirements

### Requirement: Real-time terminal session lifecycle
The system SHALL spawn shell commands as long-running, non-blocking sessions whose output streams to the browser via WebSockets in near real time, while keeping a synchronous fallback path for trusted-network mode where broadcasting is unavailable.

#### Scenario: Spawn an asynchronous session
- **WHEN** an authenticated client posts `POST /panel/api/terminal/execute` with `{project, command}`
- **THEN** the system creates a `TerminalSession`, returns HTTP 202 with `{session_id, started_at, cwd, display}`, and the tick-loop begins streaming `terminal.output` events on the matching private channel

#### Scenario: Reject duplicate session for same project
- **WHEN** a session is already running for the requested project
- **THEN** the system returns HTTP 409 with `{error, session_id}` pointing to the existing session

#### Scenario: Reject command blocked by policy
- **WHEN** the command violates `TerminalCommandPolicy`
- **THEN** the system returns HTTP 422 with `{error}` containing the rejection reason

#### Scenario: Synchronous fallback for trusted-network mode
- **WHEN** auth is in trusted-network bypass mode (`PANEL_AUTH_ENABLED=false` AND `PANEL_DEV_BYPASS=true`) and the client cannot use WebSockets
- **THEN** the system accepts `POST /panel/api/terminal/execute-sync` with `{command}` and returns the full output synchronously in `{output, error, cwd, exit_code, display}`

### Requirement: Terminal state and history snapshot
The system SHALL expose a state endpoint that returns the current cwd, the active session if any, and the per-project command history.

#### Scenario: Idle state for a project
- **WHEN** the client sends `GET /panel/api/terminal/state?project={slug}` and no session is active
- **THEN** the response contains `{cwd, display, project, session: null, history: []}`

#### Scenario: Running state for a project
- **WHEN** the client sends `GET /panel/api/terminal/state?project={slug}` and a session is running
- **THEN** the response includes the session metadata under `session` (status, pid, command, cwd, started_at, last_chunk_at)

### Requirement: Session control endpoints
The system SHALL provide endpoints to stop, replay, and reset terminal sessions, plus a per-project history clear.

#### Scenario: Stop a running session
- **WHEN** the client sends `POST /panel/api/terminal/{session}/stop`
- **THEN** the service signals the underlying process with SIGTERM (then SIGKILL after 5 s grace) and the response is `{success: true, session_id, status: "exiting"}`

#### Scenario: Replay buffered chunks after reload
- **WHEN** the client sends `GET /panel/api/terminal/{session}/replay`
- **THEN** the response contains `{session, chunks[], status}` with chunks ordered by `ts` ascending, capped at 5 minutes / 512 KB FIFO

#### Scenario: Reset terminal cwd and stop active session
- **WHEN** the client sends `POST /panel/api/terminal/reset` with `{project}`
- **THEN** the cwd is reset to the project root and any active session for the project is stopped

#### Scenario: Clear command history
- **WHEN** the client sends `DELETE /panel/api/terminal/history` with `{project}`
- **THEN** the per-project history is dropped and the response is `{success: true, project}`

### Requirement: Async execute is rate-limited
The async execute endpoint SHALL be rate-limited to 30 requests per minute per IP. The synchronous fallback shares the standard panel auth chain only.

#### Scenario: 31st async execute attempt is throttled
- **WHEN** an authenticated client sends 31 `POST /panel/api/terminal/execute` requests within 1 minute
- **THEN** the 31st response is HTTP 429 (Too Many Requests)

### Requirement: Output broadcast event
The tick-loop SHALL dispatch `App\Events\TerminalOutput` for each stdout, stderr, meta, and exit chunk produced by an active session.

#### Scenario: Event payload shape
- **WHEN** the tick-loop produces a chunk on session `S` for project `P`
- **THEN** the event broadcasts on `PrivateChannel("terminal.{P}")` with `broadcastAs() = "terminal.output"` and a payload containing `session_id, ts, type ('stdout'|'stderr'|'meta'|'exit'), data`, plus `exit_code` when type is `exit`

#### Scenario: Synthetic exit codes
- **WHEN** the tick-loop force-exits a session
- **THEN** the synthetic exit chunk's `exit_code` is `-1` for idle/hard-cap timeouts, `-2` for orphan reap, `-3` for shutdown drain

### Requirement: Private terminal channel auth
The system SHALL authorize subscriptions to `private-terminal.{project}` only when the panel is in auth-enforced mode AND a `panel_auth` session is active.

#### Scenario: Reject in trusted-network bypass mode
- **WHEN** the channel authorize callback runs with `PANEL_AUTH_ENABLED=false` AND `PANEL_DEV_BYPASS=true`
- **THEN** it returns `false` (subscription denied; clients must use the sync execute path)

#### Scenario: Reject without panel session
- **WHEN** the channel callback runs without `session('panel_auth') === true`
- **THEN** it returns `false`

#### Scenario: Accept with active panel session
- **WHEN** the channel callback runs with `session('panel_auth') === true` and auth-enforced mode
- **THEN** it returns presence-style data `['user' => 'panel', 'project' => $project]`

### Requirement: Async execute requires authentication
The async execute endpoint SHALL require authentication via the `OwnerAccess` middleware. Synchronous fallback shares the same auth chain when in auth-enforced mode.

#### Scenario: Unauthenticated async execute
- **WHEN** a client without a `panel_auth` session, header password, or WhatsApp sender posts `POST /panel/api/terminal/execute`
- **THEN** the system returns HTTP 401 with a JSON `{error: "Unauthorized"}`
