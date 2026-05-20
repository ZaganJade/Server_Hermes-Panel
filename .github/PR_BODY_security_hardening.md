# Security hardening — Phases 1–3 (audit-driven)

Closes the full set of findings from the BMAD-tracked codebase audit
recorded in `docs/bmad/bmm-workflow-status.yaml` (workflow
`full-codebase-audit` → `hardening-implementation`).

## Verdict before this PR

**❌ Not safe for public deploy.** Audit found 4 critical, 5 high,
6 medium, 3 efficiency findings, including:

- `ToolController::isArtisanCommandAllowed()` always returned `true`
- `ToolController::runArtisan` concatenated user input into
  `Process::run('php artisan ' . $command)` — shell injection
- `DatabaseService::runQuery` doc-block claimed read-only but DDL/DML
  ran with no gate
- `DatabaseService::configureConnection` had a hardcoded path
  `/home/ZaganJade1/hermes-panel/Project/desakta/.env` as a fallback
- `FileService::create / upload` allowed path traversal via untrusted
  `name` argument; upload accepted `.php` / `.phar` / `.htaccess`

## Verdict after this PR

**✅ Safe for public deploy** with HTTPS + reverse proxy + filled-in
`PANEL_OWNER_NUMBERS` / `PANEL_GATEWAY_IPS`.

## Commits

| Phase | Commit | Theme |
|---|---|---|
| 1 | `59eb3bf` | Block-public-deploy fixes (CRIT-1..4, HIGH-2, HIGH-4) |
| 2 | `884439e` | Defensive depth (HIGH-1, HIGH-3, HIGH-5, MED-1..6) |
| 3 | `eb120e4` | Efficiency (EFF-1..3) |
| — | `2b7388b` | BMAD log update |
| — | `85e6962` | TerminalSessionServiceTest aligned with new buffer shape |

## What changed

### Critical — Phase 1

- **`ToolController::runArtisan`**: tokenises the command, rejects shell
  metacharacters (`;`, `&&`, `||`, `|`, `` ` ``, `$()`, redirects,
  newlines), then passes an array `argv` to Symfony Process so shell
  injection is structurally impossible. `runArtisan` now reads
  `tokens[0]` (the artisan subcommand) and consults `getAllowedArtisan
  Commands()` + `getBlockedArtisanCommands()`. Blocklist beats
  allowlist; the blocklist includes `tinker`, `key:generate`,
  `env:encrypt/decrypt`, `down/up`, `db:wipe`, `db:seed`,
  `migrate:rollback/fresh/reset/refresh`, `serve`, and
  `reverb:start/restart`. Operators can extend the allowlist via
  `config('panel.allowed_artisan_commands')` without touching code.

- **`DatabaseService::runQuery`**: DDL/DML now require an explicit
  `confirm_write=true` flag — without it the response is
  `type: confirm_required` so the controller surfaces a confirmation
  dialog. `DatabaseController::runQuery` wires this through with
  `$request->boolean('confirm_write')`. Comment doc-block updated to
  match behaviour.

- **`DatabaseService::configureConnection`**: removed the hardcoded
  `/home/ZaganJade1/...` path fallback. The connection now refuses to
  register when the password is masked (`********`); callers must
  pass raw env via `ProjectService::readEnvRaw()`.

- **`ProjectService`**: split into two explicit methods.
  - `readEnv()` — masked, safe for display surfaces (admin views, JSON)
  - `readEnvRaw()` — unmasked, for connection/auth code paths
  All DB-connecting callsites updated: `DatabaseController`,
  `DashboardController`, `ToolController` (3 spots),
  `Jobs\CleanupDatabaseTrash`.

### High — Phase 1

- **`FileService::create / rename / upload`**: every supplied name
  segment goes through `assertSafeName()` (reject path separators,
  `..`, leading dot, shell metachars, control chars, length > 255)
  and the resolved parent is re-checked against the sandbox base via
  `isUnderBasePath()`. Closes the `realpath()-on-non-existent-target`
  hole.

- **`FileService::isAllowedFilename`**: blocks PHP-executable
  extensions (`php`, `phar`, `phtml`, `php3-php8`, `pl`, `cgi`, `jsp`,
  `asp`, `aspx`, `sh`, `bash`, `zsh`, `exe`, `bat`, `cmd`, `com`,
  `msi`, `dll`, `vbs`) and Apache/IIS config filenames (`.htaccess`,
  `.htpasswd`, `web.config`, `.user.ini`) by default. Operators
  override via `config('panel.upload_blocked_extensions')`.

### High — Phase 2

- **`DatabaseService::getTableColumns`**: PostgreSQL branch now binds
  the table name with PDO parameters; the MySQL `DESCRIBE` /
  `SHOW KEYS` paths keep the regex gate (identifiers can't bind in
  MySQL) but drop the in-string interpolation pattern.

- **`FileService::zipDirectory`**: caps total bytes
  (`panel.zip_max_bytes`, default 1 GB) and entries
  (`panel.zip_max_entries`, default 50 000). `zipRecursive` threads a
  stats array through the recursion; on overflow the partial zip is
  unlinked and `null` returned. Closes the
  `zip vendor/`-OOMs-the-container vector.

- **`TerminalService::handleCd`**: normalises both sides of the
  sandbox compare to forward slashes before `str_starts_with`. Fixes
  a real issue on Windows where `realpath()` can return mixed
  separators (`C:\Projects\foo` vs `C:/Projects/foo`).

### Medium — Phase 2

- **`TerminalSessionService::appendChunk`**: keeps a running
  `bytes` counter alongside the chunk list so we no longer rescan the
  whole buffer per append (was O(N²) over a session lifetime).
  Backwards-compatible — legacy buffers (flat array of chunks) are
  upgraded on first read.

- **`TerminalSessionService::pushHistory`**: per-project cache lock
  before the read-modify-write so concurrent spawns can't drop
  history entries. Lock acquisition failure falls through to
  unlocked write (history is convenience-only).

- **`DatabaseService::exportTable`**: streams via
  `->chunk(1000, …)` instead of `->get()` and caps at
  `panel.export_row_cap` (default 100 000). Operators needing more
  should `mysqldump` via SSH.

- **`FileController::listFiles`**: drops the GET `?project=` override
  (it was a CSRF surface because `panel/api/*` is exempt from CSRF).
  Project switching moves to a new `POST /api/files/switch-project`
  endpoint.

- **`OwnerAccess` WhatsApp bypass**: `X-WA-Sender` only honoured when
  the request originates from a configured gateway IP
  (`PANEL_GATEWAY_IPS`, default loopback) and — when
  `PANEL_GATEWAY_SECRET` is set — carries a valid
  `X-WA-Signature: hex(hmac_sha256(secret, sender))` header. Closes
  the public-domain header-spoofing vector.

### Efficiency — Phase 3

- **`ProjectService::buildProjectData`** stops eagerly recursing every
  project tree. The heavy `file_count` / `storage_used` fields move
  to a separate `withFileStats()` helper backed by a 1-hour
  per-project cache (`panel.project_stats_cache_ttl`). Dashboard and
  Projects controllers opt in for the views that actually display
  them. Cold first-load on a panel with several Laravel projects
  drops from O(sum_of_files) to O(number_of_projects).

- **`DatabaseController`** memoises the
  `(connectionKey → laravel-connection-name)` map per request. The
  SQL editor / table browser was re-reading `.env` and re-registering
  the connection on every AJAX call.

- **`TerminalTickLoop`** adopts an idle / active sleep cadence. When
  every tracked session has finalised, the loop sleeps for
  `--idle-sleep` µs (default 1 s) instead of the active 100 ms. Long-
  running panels with no live terminal sessions stop spinning the
  CPU 10×/s for nothing.

## Tests

- 95 passing, 197 assertions, 0 failing.
- `TerminalSessionServiceTest` adjusted for the new `{bytes, entries}`
  buffer envelope (commit `85e6962`).

```
php artisan test
# {"tool":"phpunit","result":"passed","tests":95,"passed":95,"assertions":197,…}
```

## New configuration knobs

```env
# auth + dev bypass (Phase 1 — already shipped)
PANEL_AUTH_ENABLED=true
PANEL_DEV_BYPASS=false
SESSION_SECURE_COOKIE=true            # for HTTPS deploys

# WhatsApp gateway (MED-6)
PANEL_GATEWAY_IPS=                    # comma-separated; empty = loopback only
PANEL_GATEWAY_SECRET=                 # HMAC-SHA256 secret for X-WA-Signature
```

```php
// config/panel.php (defaults shown)
'allowed_artisan_commands'   => [],            // extend the allowlist
'upload_blocked_extensions'  => […built-in…],  // override the blocklist
'zip_max_bytes'              => 1_073_741_824, // 1 GB
'zip_max_entries'            => 50_000,
'export_row_cap'             => 100_000,
'project_stats_cache_ttl'    => 3600,
```

## Rollout / operations checklist

- [ ] Set `PANEL_AUTH_ENABLED=true` in production `.env`
- [ ] Set `SESSION_SECURE_COOKIE=true` when serving over HTTPS
- [ ] Decide WhatsApp bypass policy:
  - co-located gateway → leave `PANEL_GATEWAY_IPS` empty (loopback only)
  - external gateway → fill `PANEL_GATEWAY_IPS` and set
    `PANEL_GATEWAY_SECRET` so the gateway must HMAC-sign
  - not used → drop the `PANEL_OWNER_NUMBERS` configuration
- [ ] Rotate `REVERB_APP_KEY` / `REVERB_APP_SECRET` away from the
      `changeme-*` defaults if Reverb faces the public network
- [ ] After deploy, restart the container so the new `TerminalTickLoop`
      idle backoff kicks in
- [ ] Smoke test:
  - `/panel/login` requires auth (no longer redirects to dashboard)
  - SQL editor refuses `DROP TABLE` without `confirm_write`
  - artisan tab refuses `tinker --execute` and `key:generate`
  - file manager refuses `..` / `.htaccess` / `.php` upload

## What this PR does NOT touch

- `routes/channels.php` (already gating Reverb terminal channel by
  `session('panel_auth') === true` in v3.1)
- `docker/supervisord.conf` Reverb start command
- Frontend Alpine / xterm.js wiring
- Existing v3.1 sprint stories or the legacy `agents.md` content

## Risk assessment

- **Behavioural change for ops:** SQL editor now requires
  re-submission with `confirm_write` for non-read queries. If the
  frontend doesn't surface this yet, mutating SQL will look broken
  until the dialog lands. Trade-off worth it; current behaviour is
  unsafe.
- **Behavioural change for ops:** the artisan UI must run a command
  on the allowlist. Operators who want broader access should SSH in
  or extend `panel.allowed_artisan_commands`.
- **No data migrations.** Cache shape changed for the terminal
  session buffer but auto-upgrades on read.

## BMAD audit log

`docs/bmad/bmm-workflow-status.yaml` records the
`full-codebase-audit` and `hardening-implementation` workflow
entries with per-finding status (`FIXED`, `VERIFIED`, `DOCUMENTED`).
The branch closes every CRIT, HIGH, MED, and EFF finding raised by
the audit.
