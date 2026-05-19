# Hermes Panel v3.1 — Real-time Terminal Design

**Date:** 2026-05-19
**Sub-project:** v3.1 (first of v3 umbrella: terminal → monitoring → multi-language → server admin)
**Status:** Design approved, awaiting spec review before writing implementation plan
**Author:** Brainstormed collaboratively with the panel owner

---

## 1. Architecture Overview

```
┌─────────────────────────── Browser ───────────────────────────┐
│                                                                │
│  Floating panel (Alpine + xterm.js)                            │
│   ↓ click "Run"                                                │
│   POST /panel/api/terminal/execute  ────────┐                  │
│                                              │                 │
│   ↑ subscribe                                ↓                 │
│   wss://domain/app   private channel:                          │
│   private-terminal.{project_slug}                              │
│                                                                │
└────────────┬───────────────────────────────────┬───────────────┘
             │                                   │
             │ WS (Reverb)                       │ HTTP
             ↓                                   ↓
┌─────────────────────── Container ──────────────────────────────┐
│                                                                │
│  Reverb 8081  ←──── broadcast ──── TerminalSessionService      │
│                                       │                        │
│                                       ↓                        │
│                                Symfony Process                  │
│                                ['bash','-c',$cmd]              │
│                                       │                        │
│                                       ↓                        │
│                                Tick-loop reader                 │
│                                (artisan command)                │
│                                       │                        │
│                                ┌──────┴──────┐                  │
│                                ↓             ↓                  │
│                          Buffer (cache)  Watchdog                │
│                          5min replay     10min idle / 60min cap  │
│                                                                │
└────────────────────────────────────────────────────────────────┘
```

**New / changed components**

- `TerminalSessionService` — wrapper above existing `TerminalService`. Spawns non-blocking processes, manages cache state, owns lifecycle (start/stop/cleanup).
- `TerminalCommandPolicy` — extracted security layer. Decides whether a command is allowed.
- `TerminalOutput` event — broadcast event with payload `{session_id, ts, type, data, exit_code?}`.
- `hermes:terminal-tick` artisan command — long-running process under supervisord. Polls active sessions every 100ms, reads incremental stdout/stderr, broadcasts chunks, enforces idle/cap watchdogs.
- Channel auth in `routes/channels.php` — gated on `panel.auth_enabled` flag and active session. When the panel is in trusted-network bypass mode (`auth_enabled=false` AND `dev_bypass=true`), the channel callback returns `false` by design — real-time terminal needs an authenticated session.
- Frontend bundle — xterm.js + addons + Laravel Echo + pusher-js, wired through Vite.

**Data flow**

1. User clicks Run → `POST /execute` → server spawns `bash -c <cmd>`, writes session metadata to cache, returns `{session_id}` immediately.
2. Tick-loop reads pipe stdout/stderr per 200 ms or 8 KB → broadcasts `TerminalOutput` chunk + appends to cache buffer.
3. Browser xterm receives event → `term.write(chunk.data)` (raw, ANSI-passthrough).
4. Process exits / timeout / stop → broadcast `exit` chunk → frontend disables input, status = done.
5. Page reload → API returns active session id + buffer → replay last 5 min → resume subscription.

---

## 2. Backend Internals

### Service split

- **`TerminalService`** (existing) — keeps `cwd` management, `cd` handling, `getDisplayPath`. Security helpers extracted out.
- **`TerminalSessionService`** (new) — `spawn`, `stop`, `replay`, `destroy`, `history`, `tick(sessionId)`.
- **`TerminalCommandPolicy`** (new) — `allow($command)`, blocked-pattern definitions. Chaining is now allowed; substitution and untrusted redirects are not.

### Process spawn

```php
$process = new Process(['bash', '-c', $command], $cwd, $env, null, /*timeout*/ 3600);
$process->setIdleTimeout(null);   // we manage idle ourselves
$process->start();
$sessionId = $process->getPid() . '-' . Str::random(6);
```

Symfony `Process::start()` + `getIncrementalOutput()` covers the pipe-streaming need without manual `proc_open`.

### Reader loop — dedicated supervisord process

A long-running artisan command `hermes:terminal-tick` registered as `[program:terminal-tick]` in `docker/supervisord.conf`. Loop:

```
while not shutting_down:
    sleep 100ms
    for session_id in cache scan "hermes:term:active:*":
        chunk = read_incremental(session_id)
        if chunk: broadcast + append_buffer + update last_chunk_at
        if process.exited: broadcast exit + finalize
        if now - last_chunk_at > 600: SIGTERM
        if now - started_at > 3600: SIGTERM
```

Idle ~10 MB RAM. Independent from the queue worker so user `queue:work` is never blocked by terminal output.

### Cache schema

> **Project slug convention.** "Project slug" throughout this doc means the
> folder name returned by `ProjectService::getActiveProject()['name']` (which
> normalizes to `folder`). Example: project at `Project/desakta/` has slug
> `desakta`. Channel and cache keys are bound to this slug.

```
hermes:term:active:{session_id}        TTL 5min (extended on each chunk)
  pid, project, user="panel", command, cwd,
  started_at, last_chunk_at, status, exit_code?

hermes:term:buffer:{session_id}        TTL 5min (refreshed alongside active)
  list of {ts, type, data, exit_code?}
  cap 512 KB total (FIFO)

hermes:term:project:{project}          TTL 5min
  string: current session_id for that project

hermes:term:history:{project}          TTL 30 days
  list of {ts, command, exit_code?}, cap 50 items (FIFO)
```

No Eloquent migrations. Single-user panel, ephemeral state, history is small. YAGNI on a `terminal_history` table until v3.2 needs richer audit.

### Stop & lifecycle

- **Stop button** → `POST /{session}/stop` → `SIGTERM`, wait 5 s, `SIGKILL`.
- **Idle watchdog (10 min)** → tick-loop `last_chunk_at` check.
- **Hard cap (60 min)** → tick-loop `started_at` check.
- **Process exit** → broadcast final `exit`, persist `exit_code`, drop active key, leave buffer for TTL.
- **User disconnect (close tab)** → process keeps running; output keeps buffering; state visible on reconnect.

### Channel auth (`routes/channels.php`)

```php
Broadcast::channel('terminal.{project}', function ($user) use (...) {
    // Real-time terminal needs an authenticated session. In trusted-network
    // bypass mode (auth_enabled=false AND dev_bypass=true) there is no
    // session, so the channel is intentionally unavailable. Sync fallback
    // /execute-sync covers that mode.
    if (! config('panel.auth_enabled', true) && config('panel.dev_bypass', false)) {
        return false;
    }
    if (! session('panel_auth')) {
        return false;
    }
    return ['user' => 'panel', 'project' => $project];
});
```

When the panel is in trusted-network bypass mode, terminal real-time is unavailable. Frontend falls back to `POST /execute-sync` (legacy synchronous path).

### `TerminalCommandPolicy` rules

**Allowed**: `;`, `&&`, `||`, `|`, quoted strings with normal escapes, redirects to `/tmp` and to relative paths.

**Blocked**:
- `$(...)`, backtick command substitution
- Trailing `&` (background fork)
- Newline / CR injection
- `vim, vi, nano, emacs, less, more, man, top, htop, ssh, scp, sudo, su, mysql, psql`
- `rm -rf /` (literal root path)
- `dd`, `mkfs`
- `nc`, `ncat`
- Output redirect `>/...` to FS root except `/tmp`, `/var/tmp`
- Input redirect `</...` from FS sensitive except `/dev/null`, `/dev/zero`

---

## 3. Broadcasting & Frontend Wiring

### Server config

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=hermes-panel
REVERB_APP_KEY=<random-32>
REVERB_APP_SECRET=<random-32>
REVERB_HOST=0.0.0.0
REVERB_PORT=8081
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8081
```

`composer require laravel/reverb` is required (currently spawned by supervisord but not in composer.json — this is a real gap to close in story 1).

### Client config

```env
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${APP_HOST}"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

Browser connects to `wss://panel.example.com/app` → reverse proxy → container nginx `/app` → Reverb 8081.

### Frontend dependencies

```json
"dependencies": {
  "alpinejs": "^3.15.12",
  "@xterm/xterm": "^5.5.0",
  "@xterm/addon-fit": "^0.10.0",
  "@xterm/addon-search": "^0.15.0",
  "@xterm/addon-web-links": "^0.11.0",
  "laravel-echo": "^1.16.0",
  "pusher-js": "^8.4.0"
}
```

Alpine moves from CDN to npm bundle so it can import shared modules. xterm + addons total ~95 KB; echo + pusher ~30 KB. Vite tree-shakes.

### File layout

```
resources/js/
├── app.js                          (entry — imports Alpine + terminal)
├── bootstrap.js                    (existing)
├── echo.js                         (init Laravel Echo + Pusher with Reverb)
└── terminal/
    ├── index.js                    (Alpine.data('hermesTerminal', …))
    ├── session.js                  (WS subscribe, replay, fetch state)
    ├── xterm-instance.js           (xterm bootstrap + addons)
    └── history.js                  (up/down arrow, filter)
```

### Floating panel UX

- Sidebar bottom: toggle button with status indicator (`◌` idle / `⚡` running / `✓` done).
- Click → panel slides up from bottom edge, default height 45 vh, drag-resize handle.
- Header: `project · cwd · ↑↓ history shortcut` + `× _ ⤢` controls.
- Body: xterm canvas.
- Footer: `$ ` prompt input, `[Run]` `[⏹]` buttons.
- Persistent across page navigation. Project switch in sidebar emits event → panel swaps subscription, clears xterm, replays new project's buffer.

### Event flow on Run

```
User Enter
  ↓
session.send(command)
  ├─ append "$ <command>\n" to xterm
  ├─ POST /panel/api/terminal/execute {project, command} → {session_id}
  └─ ensure subscribed to private-terminal.{project}

server: spawn → cache → broadcast 'meta' init
  ↓
tick-loop: read pipe → broadcast 'stdout' / 'stderr'
  ↓
Echo handler:
  if type stdout|stderr → term.write(data)
  if type exit → term.write([exit code]), status=done
```

### Reconnect / replay flow

```
Page load → session.init()
  ↓
GET /panel/api/terminal/state?project=<slug>
  ↓
if session active:
  GET /{session_id}/replay → chunks
  → instant write to xterm
  → subscribe channel for new chunks
else:
  → just subscribe (idle state, ready to run)
```

---

## 4. Security, Idle Timeout & Hardening

### Idle timeout 15 min

- Default `PANEL_SESSION_LIFETIME` lowered from 120 → **15** minutes.
- Refresh on every authenticated HTTP request (including `/broadcasting/auth` initial handshake).
- WebSocket message reception does **not** refresh — answer to clarification c-no.
- One mechanism, no stacking.

### WS auth (B2)

Channel auth requires the panel to be in auth-enforced mode (default `auth_enabled=true`) AND an active `panel_auth` session. Intentional consequence: trusted-network bypass mode (`auth_enabled=false` + `dev_bypass=true`) loses terminal real-time. Documented as trade-off, not a bug. Sync fallback `/execute-sync` keeps behavior parity with the existing terminal in that mode.

### Command execution hardening

- `bash -c <command>` shell-wrapped (per chosen option B).
- `TerminalCommandPolicy` enforced before spawn.
- Container runs as non-root user `hermes`. Worst case after auth breach: same access as `hermes` inside container.

### Rate limit

- `POST /execute` → `throttle:30,1` (30 commands per minute per IP).
- WS rate limit deliberately deferred; revisit when v3.2 monitoring increases broadcast volume.

### Process orphan prevention

- Symfony Process spawned in own group; if php-fpm dies, process inherits.
- Tick-loop on boot sweeps `hermes:term:active:*`, validates each PID with `posix_kill($pid, 0)`. Dead PIDs → broadcast synthetic exit chunk, drop key.
- On supervisord SIGTERM, tick-loop traps signal, SIGTERMs every active session, broadcasts exit, exits gracefully.

### Audit log

`storage/logs/terminal-audit.log` writes one structured line per spawn:

```
[2026-05-19 18:18:54] panel.INFO: terminal_command_run
  project: desakta
  command: "npm install"
  cwd: /var/www/desakta
  session_id: 12345-abc123
  ip: 1.2.3.4
```

Viewer surface deferred — grep is enough for v3.1.

---

## 5. Data & API Contract

### API endpoints

All under `panel/api/terminal`.

| Method | Path                           | Body / Query                 | Returns                                                                              |
| ------ | ------------------------------ | ---------------------------- | ------------------------------------------------------------------------------------ |
| GET    | `/state`                       | `?project=<slug>`            | `{cwd, display, project, session?, history[]}`                                       |
| POST   | `/execute`                     | `{project, command}`         | 202 `{session_id, started_at}`; 409 if session running; 422 if blocked; 401 if unauth |
| POST   | `/execute-sync`                | `{project, command}`         | Legacy sync, returns full output. Used in trusted-network mode.                      |
| POST   | `/{session}/stop`              | —                            | `{success, status: "exiting"}`                                                       |
| GET    | `/{session}/replay`            | —                            | `{session, chunks[], status}`                                                        |
| POST   | `/reset`                       | `{project}`                  | `{cwd, display}` — clears terminal_cwd; kills active session if any                  |
| DELETE | `/history`                     | `{project}`                  | `{success}`                                                                          |

### Session payload

```json
{
  "session_id": "12345-abc123",
  "pid": 12345,
  "project": "desakta",
  "command": "npm install",
  "cwd": "/var/www/desakta",
  "started_at": 1763577330,
  "last_chunk_at": 1763577340,
  "status": "running"
}
```

### Chunk payload

```json
{ "ts": 1763577331, "type": "stdout", "data": "added 1 package in 2s\n" }
```

Special `type` values: `meta` (session init message), `exit` (final, includes `exit_code`).

### Broadcast event

```php
class TerminalOutput implements ShouldBroadcast {
    public function broadcastOn(): array {
        return [new PrivateChannel("terminal.{$this->project}")];
    }
    public function broadcastWith(): array {
        return [
            'session_id' => $this->sessionId,
            'ts'         => $this->ts,
            'type'       => $this->type,
            'data'       => $this->data,
            'exit_code'  => $this->exitCode,
        ];
    }
    public function broadcastAs(): string {
        return 'terminal.output';
    }
}
```

Frontend: `Echo.private("terminal.${project}").listen(".terminal.output", handler)`.

### State machine

```
[idle] ──execute──→ [running] ──exit──→ [done]
                       │
                       ├─ stop ──→ [exiting] ──exit──→ [done]
                       ├─ idle 10min ──→ SIGTERM ──→ [exiting]
                       └─ runtime 60min ──→ SIGTERM ──→ [exiting]
```

`done` lives 5 minutes in buffer, then GC.

### Edge cases

- Run while session running → 409 with current `session_id`. Frontend chooses wait, force-stop, or abort.
- Run in project A then project B → two parallel sessions, tick-loop handles both. Sidebar indicator scoped per-project.
- Session orphan after PHP crash → tick-loop sweep on boot resolves.
- Cache evict mid-session → process keeps running but panel loses control. Mitigated by 200 ms TTL refresh on every chunk; file cache (default) does not evict mid-key.
- Reverb crash mid-session → broadcast silently fails; buffer continues. Reconnect replay covers gap.

### Frontend Alpine state

```js
hermesTerminal() {
  return {
    open: false, expanded: false, minimized: false,
    project: null, cwd: '/', display: '',
    status: 'idle',          // idle | running | exiting | done
    sessionId: null,
    history: [], historyIndex: -1, inputBuffer: '',
    term: null, fitAddon: null, searchAddon: null,
    echoChannel: null,
    init() { … }, open() { … }, close() { … },
    run() { … }, stop() { … }, reset() { … },
    onProjectChange() { … },
    onChunk(payload) { … }, onExit(payload) { … },
  };
}
```

---

## 6. Test Plan

### Test pyramid

```
       ╱╲    Browser/E2E (Playwright) — 2 tests
      ╱  ╲
     ╱    ╲  Feature (Laravel) — ~12 tests
    ╱      ╲
   ╱        ╲ Unit (PHPUnit) — ~8 test classes
   ──────────
```

### Unit tests

**`TerminalCommandPolicyTest`** — allows simple commands, allows `&&` `||` `;` `|`, blocks `$()`, blocks backtick, blocks trailing `&`, blocks newline injection, blocks interactive bins, blocks `rm -rf /`, blocks redirect to `/etc` `/root`, allows redirect to `/tmp` and relative paths.

**`TerminalSessionServiceTest`** — `spawn()` creates active cache entry, returns session_id; `stop()` SIGTERMs and updates status; `replay()` returns buffer chunks; `destroy()` removes keys; `history()` prepends and trims to 50.

**`OwnerAccessTest`** (gap fix) — enforces auth by default; trusted-network bypass requires both `auth_enabled=false` AND `dev_bypass=true` (single flag is rejected); valid session allowed; valid header password via `X-Panel-Password` (constant-time) allowed; invalid header rejected; query-string `?password=` rejected; valid WhatsApp sender after normalization allowed; unknown WhatsApp rejected; web denied = redirect to login; API denied = 401 JSON; refreshes `panel_auth_time`. Plus `AppServiceProviderGuardTest` — production with auth disabled and no dev-bypass throws `RuntimeException`.

**`FileServiceTest`** (gap fix) — `resolvePath` rejects `../../../etc/passwd`; rejects absolute paths outside base; accepts subdirectories; `listDirectory` empty for invalid path.

**`DatabaseServiceTest`** (gap fix) — `isValidSqlIdentifier` accepts table names, rejects injection attempts, rejects names with quotes/spaces.

### Feature tests

**`TerminalApiTest`** — `/state` idle/active responses; `/execute` spawns + returns session_id (auth enforced); `/execute` 401 unauth; `/execute` falls to `/execute-sync` when in trusted-network bypass; 422 if project missing; 409 if session running; 422 if blocked by policy; throttle 30/min; `/stop` SIGTERMs; `/replay` returns buffered chunks; `DELETE /history` clears.

**`TerminalChannelAuthTest`** — `/broadcasting/auth` rejects in trusted-network bypass mode; rejects without `panel_auth` session; accepts valid session; returns presence data on success.

### E2E tests (Playwright)

**`terminal-stream.spec.ts`** — open floating terminal, run `echo hello && sleep 1 && echo world`, assert progressive output, assert exit chunk, assert command in history.

**`terminal-reconnect.spec.ts`** — run 10-second loop, observe initial output, reload mid-execution, assert replay + continued streaming, assert final exit.

### Infrastructure

- `phpunit.xml` already uses sqlite in-memory + `CACHE_STORE=array`.
- `Broadcast::fake()` for feature tests; no Reverb required.
- Process tests use real `sleep 0.1` commands.
- E2E requires running container; `composer test:e2e` script spins up + tears down.

### Coverage targets

- `TerminalCommandPolicy` 100% (security)
- `OwnerAccess` 100% (auth gate)
- `FileService::resolvePath` 100% (security)
- `TerminalSessionService` happy path + lifecycle (>80%)
- `TerminalController` covered by feature tests

---

## 7. Rollout Plan

### Story decomposition (BMAD)

| #  | Story                                            | Deps   | Effort |
| -- | ------------------------------------------------ | ------ | ------ |
| 1  | Install Reverb + broadcasting config             | —      | S      |
| 2  | Refactor TerminalService → split `Policy`        | —      | S      |
| 3  | `TerminalSessionService` + cache schema          | 2      | M      |
| 4  | Tick-loop artisan command + supervisord entry    | 3      | M      |
| 5  | API contract + controller update                 | 3, 4   | M      |
| 6  | Frontend bundling — Alpine npm + Echo + xterm    | 1      | M      |
| 7  | Floating panel UI + Alpine component             | 5, 6   | M      |
| 8  | Idle timeout default 15min + polish              | 7      | S      |

Each story is mergeable independently; until story 7, current File-Manager terminal continues via `/execute-sync`.

### Migration & breaking changes

- **`POST /panel/api/terminal/execute`** response shape changes (sync → async). Mitigated by introducing `/execute-sync` for legacy use and trusted-network mode.
- **`PANEL_SESSION_LIFETIME`** default lowered 120 → 15 in story 8. Existing `.env` files keep their value (gitignored). README upgrade notes call this out.

### Rollback

Stories 1–5 are backend-only; the existing terminal keeps working. Story 6 → 7 are sequential frontend changes. Reverting story 7 reverts to File-Manager terminal panel.

### Verification gates

- `./vendor/bin/pint` clean
- `php artisan test --testsuite=Unit` green
- `php artisan test --testsuite=Feature` green
- Manual smoke per story
- `code-review` skill pass before merge
- OpenSpec `realtime-terminal-v31` capability spec written and referenced

### Documentation deliverables

- This design doc: `docs/superpowers/specs/2026-05-19-realtime-terminal-design.md`
- BMAD stories: `docs/bmad/stories/v3.1-{01..08}-*.md`
- OpenSpec capability: `openspec/changes/realtime-terminal-v31/specs/realtime-terminal/spec.md`
- README update: new "Real-time terminal" section, roadmap update
- AGENTS.md update: section 14 status

### Effort estimate

~8–12 days of focused dev for sequential 1–2 h/day work; ~5–7 days with 4-hour focus blocks.

---

## Decisions Log

Recorded for future reference. Each pinned by user response in brainstorming.

| #   | Decision                                          | Rationale                                                                                |
| --- | ------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| 1   | Streaming output (option A), not full PTY         | Safer for public-domain panel; covers 80% of dev VPS commands                            |
| 2   | Multi-session per project                         | Matches active-project workflow; project switch = context switch                          |
| 3   | Buffered chunk 200 ms / 8 KB                      | Smooth UX, avoids broadcast flood, friendly to Cloudflare WS pacing                       |
| 4   | Buffer + replay (5 min)                           | Survives reload/disconnect; commands not killed                                           |
| 5   | WS auth wajib session, universal (B2)             | Trusted-network mode loses real-time terminal — explicit trade-off                        |
| 6   | `bash -c` shell wrap, allow chaining              | Real-world workflow needs `&&` `||` `;` ; substitution still blocked                      |
| 7   | Idle watchdog 10 min + hard cap 60 min + Stop     | No fixed timeout; `npm ci && npm run build` gets time it needs                            |
| 8   | Floating global panel (option B)                  | Persistent across pages; status indicator visible in sidebar                              |
| 9   | History 50 commands per project, file cache       | Project-scoped feels right; up/down arrow + client-side filter                            |
| 10  | Reverb behind nginx, single domain                | Cloudflare-friendly, single cert                                                          |
| 11  | xterm + fit + search + web-links                  | ANSI passthrough, search, URL clickable                                                   |
| 12  | Welcome banner only (no quick-actions)            | Quick-actions deferred to v3.3 multi-language for stack-aware suggestions                 |
| 13  | Idle timeout 15 min replaces SESSION_LIFETIME     | Single mechanism; HTTP refreshes; WS does not                                             |

---

## Out of Scope (Confirmed)

- Multi-tab / multi-session paralel per project
- Save command output to server file / download `.txt`
- Schedule command (cron-like)
- Cross-project run from current project context
- sudo / privilege escalation
- Custom keybindings, custom themes
- Real PTY (vim, htop, top remain blocked)
- WhatsApp / email notifications on command completion (deferred to v3.2)
