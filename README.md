# Hermes Panel

<div align="center">

**Your VPS, your Laravel projects, one quiet panel.**

A self-hosted control panel for developers managing multiple Laravel projects on a headless VPS.

[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![Alpine.js 3.x](https://img.shields.io/badge/Alpine.js-3.x-8BC0D0?style=flat-square)](https://alpinejs.dev)
[![Tailwind 4](https://img.shields.io/badge/Tailwind-4.0-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)](https://tailwindcss.com)

</div>

---

## Who This Is For

You run a VPS without a GUI. You have two or three Laravel projects living
side by side. SSH gets the job done, but tailing a log, poking at the
database, or restarting a queue worker through a terminal grows tiring.

Hermes Panel is a lightweight web layer over the same server. It assumes
**you are the only operator**, that the panel is reachable only from a
trusted network (SSH tunnel, VPN, Cloudflare Tunnel, or `127.0.0.1`), and
that authentication is therefore optional and off by default.

It is not a hosting platform, not a multi-tenant control panel, and not a
replacement for `cpanel`. It is a personal cockpit.

---

## What It Replaces

| Instead of                      | Use Hermes Panel for                  |
| ------------------------------- | ------------------------------------- |
| `mysql` / `psql` CLI            | Browse, edit, and query your database |
| phpMyAdmin or Adminer           | Multi-connection SQL editor           |
| `nano` over SSH                 | File browser with inline editor       |
| `scp` / `rsync` for one file    | Drag-and-drop upload / zip download   |
| Long `php artisan` SSH sessions | Artisan, queue, log, seeder runner    |
| `composer install` over SSH     | Composer & NPM runner per project     |
| A second SSH tab                | Built-in web terminal (sandboxed)     |

---

## Feature Map

**Project workspace**
- Auto-discovery of Laravel projects under `Project/` (`artisan` file = Laravel)
- Manual project entries for paths outside the directory
- Hide / un-hide / delete (with name confirmation)
- Active project drives every other module

**Database manager**
- Multi-connection support (`DB_CONNECTION` plus suffixed variants like `DB_CONNECTION_SECONDARY`)
- Table list with row count + size
- Browse with pagination, sorting, inline cell edit, add row, delete
- Soft-delete aware: `deleted_at` columns get a *Trash* tab with restore / force-delete / empty
- SQL editor with per-session history (last 10 queries)
- Export tables to JSON or CSV

**File manager**
- Tree-style browsing with breadcrumbs scoped to the active project
- Inline editor for common text formats (`php`, `blade.php`, `js`, `css`, `json`, `env`, `md`, `yaml`, `xml`, `sql`, …)
- Create, rename, move, copy, delete
- Drag-and-drop upload with size cap, single-file or zip-folder download
- Recursive search by name, chmod editor
- Path-traversal protection: every path is resolved against the active project's base

**Built-in terminal**
- Request-response shell scoped to the active project (`cd`, `pwd`, `clear`, plus any non-interactive command)
- Blocklist for interactive tools (`vim`, `nano`, `top`, `ssh`, `mysql`, `sudo`, …) and chained commands (`;`, `&&`, `||`, `|`, command substitution)
- Argument parser is shlex-style — quoted arguments survive intact
- This is intentionally not a full PTY. For interactive work, use plain SSH

**Laravel tools**
- Artisan runner (suggested commands + free-form input + arguments)
- Log viewer (tail with offset, level filter, search, clear)
- Queue: failed-jobs table, retry, restart, flush, dispatch cleanup job
- Seeder runner (lists `database/seeders/*` and runs `db:seed --class=…`)
- Composer & NPM runner per project

**Authentication (on by default)**
- Auth chain is enforced by default — session login, header password, and WhatsApp sender header. The panel refuses to boot in production if auth is disabled without an explicit dev-bypass.
- Three credentials are accepted in order: active session (`panel_auth`), `X-Panel-Password` header, and `X-WA-Sender` matching `PANEL_OWNER_NUMBERS`.

**UI**
- Single Blade layout with Alpine.js for interactivity (no SPA build step)
- Editorial dark theme by default with copper accents and Greek-letter section anchors (α β γ δ ε)
- Responsive: mobile menu under 768 px, bottom nav for thumb reach

---

## Quick Start (Docker)

Requires Docker 24+ and Docker Compose 2.20+.

```bash
git clone <your-repo-url> hermes-panel
cd hermes-panel
cp .env.example .env

# generate APP_KEY and start the panel
docker compose run --rm hermes-panel php artisan key:generate
docker compose up -d --build
```

The panel binds to `127.0.0.1:8080` by default. Reach it locally at
<http://127.0.0.1:8080>, or put a reverse proxy in front (see
[Deployment Security](#deployment-security)).

Drop your Laravel projects into the `Project/` folder — they show up on
the dashboard within five minutes (discovery cache TTL).

## Quick Start (Local PHP)

Requires PHP 8.3, Composer, Node 20+.

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build
php artisan serve --host=127.0.0.1 --port=8080
```

---

## Configuration

All panel-specific knobs live under the `PANEL_*` namespace in `.env`.
Defaults are embedded in `config/panel.php`.

| Variable                  | Default        | Description                                                                |
| ------------------------- | -------------- | -------------------------------------------------------------------------- |
| `PANEL_NAME`              | `Hermes Panel` | Display name in header and `<title>`                                       |
| `PANEL_AUTH_ENABLED`      | `true`         | Toggle the entire authentication chain                                     |
| `PANEL_DEV_BYPASS`        | `false`        | Required to actually disable auth in production (second gate)              |
| `PANEL_USERNAME`          | `admin`        | Login username (when auth enabled)                                         |
| `PANEL_PASSWORD`          | —              | Login password and `X-Panel-Password` header value                         |
| `PANEL_OWNER_NUMBERS`     | `""`           | Comma-separated WhatsApp numbers (with country code, no `+`)               |
| `PANEL_SESSION_LIFETIME`  | `120`          | Session lifetime in minutes (sliding window)                               |
| `PANEL_PROJECTS_DIR`      | `Project`      | Folder (relative to panel root) holding managed projects                   |
| `PANEL_DEFAULT_PROJECT`   | —              | Folder name to auto-select on first request when no project is in session  |
| `PANEL_MAX_UPLOAD_SIZE`   | `10485760`     | File-upload cap in bytes (10 MB by default)                                |

The `Project/` folder is the convention. You can still register projects
manually from the *Projects* tab — those are persisted in `projects.json`
at the panel root, alongside the hidden-project blacklist.

---

## Authentication & Deployment Security

Hermes Panel exposes destructive operations: removing files, dropping
tables, executing arbitrary `php artisan` commands, and a sandboxed
shell. Treat the panel as a privileged terminal session.

### Choose your deployment shape

Pick one. Each row shows what to set, and what to add in front of the
container.

| Shape                                | Container bind     | Auth flag        | Front
| ------------------------------------ | ------------------ | ---------------- | -----
| **A. SSH tunnel only**               | `127.0.0.1:8080`   | `false` + dev-bypass `true` | `ssh -L 8080:127.0.0.1:8080 user@vps`
| **B. VPN / Tailscale / WireGuard**   | `127.0.0.1:8080`   | `true` recommended           | VPN endpoint reaches the loopback port
| **C. Cloudflare Tunnel (no DNS)**    | `127.0.0.1:8080`   | **`true` required**          | `cloudflared tunnel --url http://127.0.0.1:8080`
| **D. Public domain over HTTPS**      | `127.0.0.1:8080`   | **`true` required**          | Caddy / Nginx host / Cloudflare in front

Shapes B-D keep `PANEL_AUTH_ENABLED=true`. Only shape A justifies turning
it off, and only when paired with `PANEL_DEV_BYPASS=true` — the panel
refuses to boot in production with auth off otherwise.

### Trusted-network mode (opt-in)

Set **both** `PANEL_AUTH_ENABLED=false` AND `PANEL_DEV_BYPASS=true`. The
middleware becomes a pass-through and you accept full responsibility
for network isolation. Use this for shape A only.

If only one of the two flags is set in production, `AppServiceProvider`
throws a `RuntimeException` at boot and the panel refuses to serve.

### Auth-enabled mode (default)

Keep `PANEL_AUTH_ENABLED=true` (the default) and the middleware evaluates
three credentials, in order:

1. **Active session** — `panel_auth` set after a successful login at `/panel/login`
2. **Header password** — `X-Panel-Password: <value>` matching `PANEL_PASSWORD`, compared with `hash_equals`. The header is the only accepted form; `?password=` query is deliberately rejected to avoid leaks into nginx logs, browser history, and the Referer header.
3. **WhatsApp sender** — `X-WA-Sender: <number>` matching one of `PANEL_OWNER_NUMBERS` after normalization (leading `0` → `62`, prepend `62` if missing)

Web routes that fail every check are redirected to `/panel/login`. API
routes (`/panel/api/*`) return `401 Unauthorized` JSON.

The login endpoint is rate-limited at 5 failed attempts per IP per
minute; successful login clears the throttle. CSRF is enforced on
non-API routes via Laravel's built-in middleware (`bootstrap/app.php`
disables it for `panel/api/*` only).

### Hardening checklist for shape D (public domain)

When the panel sits behind a domain like `panel.example.com`, do all of
these:

- Keep `PANEL_AUTH_ENABLED=true` (default) and never set `PANEL_DEV_BYPASS=true`
- Set a long, unique `PANEL_PASSWORD`
- Set `APP_URL=https://panel.example.com` so generated links match
- Set `SESSION_SECURE_COOKIE=true` and `SESSION_SAME_SITE=lax` (already in `.env.example`)
- Keep the container bound to `127.0.0.1:8080` and let the host proxy reach it
- Terminate TLS at the front (Caddy / Nginx / Cloudflare) — the container's nginx serves plain HTTP only
- Forward `X-Forwarded-For` and `X-Forwarded-Proto` from the proxy; `bootstrap/app.php` already trusts them
- Restrict `/panel/login` further if possible — Cloudflare Access, IP allowlist on the host nginx, or basic-auth in front of the panel
- Watch `storage/logs/laravel.log` for failed login bursts

Minimal Caddyfile in front:

```caddy
panel.example.com {
    encode zstd gzip
    reverse_proxy 127.0.0.1:8080
}
```

Minimal Nginx host block in front:

```nginx
server {
    listen 443 ssl http2;
    server_name panel.example.com;
    ssl_certificate     /etc/letsencrypt/live/panel.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/panel.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Upgrade           $http_upgrade;
        proxy_set_header Connection        "upgrade";
    }
}
```

> **Heads-up:** `PANEL_AUTH_ENABLED` is safe to toggle at runtime — no
> migrations, no rebuilds. The login form, logout button, and session
> refresh all short-circuit when auth is off (and dev-bypass is on). In
> production, `AppServiceProvider` enforces the two-flag rule at boot:
> auth off without dev-bypass = `RuntimeException` and the panel will
> not serve traffic.

---

## Architecture Snapshot

```
hermes-panel/
├── app/
│   ├── Http/
│   │   ├── Controllers/Panel/
│   │   │   ├── AuthController.php
│   │   │   ├── DashboardController.php
│   │   │   ├── DatabaseController.php
│   │   │   ├── FileController.php
│   │   │   ├── ProjectController.php
│   │   │   ├── TerminalController.php
│   │   │   └── ToolController.php
│   │   └── Middleware/OwnerAccess.php   # auth gate (opt-in)
│   ├── Jobs/CleanupDatabaseTrash.php    # monthly trash purge
│   └── Services/
│       ├── DatabaseService.php          # multi-DB, schema, queries
│       ├── FileService.php              # CRUD + path sandbox
│       ├── ProjectService.php           # discovery + projects.json
│       └── TerminalService.php          # sandboxed exec
├── bootstrap/app.php                    # middleware aliases, CSRF skip
├── config/panel.php                     # PANEL_* defaults
├── docker/                              # Dockerfile + nginx + supervisord
├── resources/views/panel/               # Blade pages (one per module)
├── routes/web.php                       # all routes (pages + API)
└── projects.json                        # manual + hidden project metadata
```

### Stack

| Layer            | Choice                                            |
| ---------------- | ------------------------------------------------- |
| Backend          | Laravel 13 (PHP 8.3)                              |
| Frontend         | Blade + Alpine.js 3 (CDN, no SPA build)           |
| Styles           | Tailwind CSS v4 via the official Vite plugin      |
| Build            | Vite 8                                            |
| Container        | Single PHP-FPM + Nginx image, supervisord-managed |
| Process manager  | Docker is the supported target                    |
| Fonts            | Fraunces (serif), JetBrains Mono                  |

### Service responsibilities

- **`ProjectService`** — discover Laravel projects in `Project/`, merge in manual entries from `projects.json`, expose the active project. Reads each project's `composer.json` and `.env` (with sensitive keys masked). Caches discovery for 5 minutes.
- **`DatabaseService`** — configure dynamic connections named `panel_project_{key}`, list tables, paginate rows, run raw SQL safely, detect soft deletes per table, restore / force-delete trashed rows, export to JSON/CSV.
- **`FileService`** — list directories, CRUD files, build breadcrumbs, format sizes, zip folders, search recursively. Every path passes through `resolvePath()` which compares `realpath` results to keep operations inside the active project.
- **`TerminalService`** — execute one command per request via Symfony `Process`, persisting `cwd` in the session. Refuses chained commands, dangerous patterns, and a list of interactive tools that wouldn't work over HTTP anyway.

### Routes

All panel routes share the `panel` prefix and the `owner.access`
middleware alias.

```
GET    /panel/login                          (public)
POST   /panel/login                          (public)
POST   /panel/logout

GET    /panel/dashboard
GET    /panel/database
GET    /panel/files
GET    /panel/tools
GET    /panel/projects

POST   /panel/api/quick/cache-clear
GET    /panel/api/quick/recent-logs

GET    /panel/api/tables                     # + .../{table}/data, columns, trash
PATCH  /panel/api/tables/{table}/{id}/cell
POST   /panel/api/tables/{table}/rows
POST   /panel/api/tables/{table}/update
DELETE /panel/api/tables/{table}/rows/{id}
POST   /panel/api/tables/{table}/{id}/restore
DELETE /panel/api/tables/{table}/{id}/force
DELETE /panel/api/tables/{table}/trash       # empty trash
POST   /panel/api/query
GET    /panel/api/tables/{table}/export/{format}
GET    /panel/api/connections

GET    /panel/api/files                      # + content, save, create, rename,
POST   /panel/api/files/{action}             #   move, copy, delete, upload,
GET    /panel/api/files/download             #   permissions, search
GET    /panel/api/files/search

POST   /panel/api/artisan
GET    /panel/api/logs                       # + clear, queue/*, composer, npm
GET    /panel/api/seeders
POST   /panel/api/db-seed

GET    /panel/api/terminal/state
POST   /panel/api/terminal/execute
POST   /panel/api/terminal/reset

POST   /panel/api/projects/{action}          # switch, add, hide, unhide, delete
GET    /panel/api/projects/list
```

---

## Scheduled Jobs

**`CleanupDatabaseTrash`** runs on the 1st of every month at 00:00. For
each managed project, it iterates tables that have a `deleted_at`
column and permanently deletes rows older than 30 days. Failures and
counts are logged to the panel's own `storage/logs/laravel.log`.

The cleanup job can also be dispatched manually from *Tools → Antrian →
Dispatch Cleanup*.

---

## Design Tokens

The CSS lives in `resources/css/app.css`. Two custom properties drive
almost everything.

```css
--ink:        #0e0d0a   /* canvas */
--ink-soft:   #15130f   /* surface */
--paper:      #f4ede1   /* primary text */
--paper-soft: #ddd2bd   /* secondary text */
--paper-dim:  #8a8275   /* muted text */
--copper:     #d4a45c   /* accent */
--verdigris:  #5a7a5a   /* success */
--rust:       #b85c44   /* danger */
```

Type pairs Fraunces (display, with `'WONK' 1` opentype feature) against
JetBrains Mono (UI labels, code, navigation). Animations are deliberately
small: a fade-up entrance with three stagger steps and a single
`pulse-dot` for status indicators.

---

## Development

```bash
# install once
composer install
npm install

# the dev runner spawns serve + queue listener + log tailer + vite
composer run dev

# tests (sqlite in-memory)
composer test

# lint / format Laravel code
./vendor/bin/pint
```

The test suite currently contains the default Laravel scaffolding only.
Real coverage for path traversal, project discovery, the auth middleware,
and the SQL identifier validator is still on the to-do list.

---

## Roadmap Notes

These are real gaps, not marketing copy:

- The terminal is HTTP request-response, not WebSocket-backed. Reverb is wired into supervisord but the proxy / port pair is not finished. Long-running interactive output should keep going through SSH for now.
- The file editor is a styled `<textarea>`. CodeMirror was on the original wishlist; it isn't installed.
- `CleanupDatabaseTrash` calls `onlyTrashed()` on the query builder, which only exists on Eloquent models with `SoftDeletes`. The job currently handles this gracefully, but the query path needs rewriting to use `whereNotNull('deleted_at')` directly.
- Test coverage is thin. Auth middleware behaviour, the path-traversal guard, and the identifier validator are good first targets.

---

## License

Proprietary. All rights reserved.

<div align="center"><sub>Hermes Panel — for developers who run their own boxes.</sub></div>
