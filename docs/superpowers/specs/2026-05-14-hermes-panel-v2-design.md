# Hermes Panel v2 — Design Spec

**Date:** 2026-05-14  
**Status:** Approved  
**Scope:** Full rewrite of Hermes Panel as a cPanel-like server administration panel for managing Laravel projects on a VPS via Docker.

---

## 1. Overview

Hermes Panel is a web-based server administration panel for managing multiple Laravel projects hosted on a VPS. It provides a cPanel-like experience with database management, file management, Laravel tools, and a built-in terminal — all accessible through a modern dark/light theme UI.

### Goals

- Single panel to manage all Laravel projects on a VPS
- Auto-discover projects from a designated directory
- Full database management with multi-connection support
- Full-featured file manager with built-in web terminal
- Docker-based deployment for portability and isolation

### Non-Goals

- Multi-user management (single owner use)
- Domain/SSL management (handled externally)
- Server-level monitoring (CPU, RAM, network)

---

## 2. Architecture & Deployment

### VPS Directory Structure

```
/home/ZaganJade1/hermes-panel/          ← Docker container root
├── app/                                 ← Laravel application
├── bootstrap/
├── config/
├── database/
├── docker/
│   ├── Dockerfile
│   └── nginx.conf
├── docker-compose.yml
├── public/
├── resources/
├── routes/
├── storage/
├── vendor/
└── Project/                             ← Mounted volume (auto-detected projects)
    ├── desakta/                         ← Auto-detected Laravel project
    ├── project-2/                       ← Auto-detected Laravel project
    └── project-3/                       ← Auto-detected Laravel project
```

### Docker Setup

Two services in `docker-compose.yml`:

1. **hermes-panel** — PHP-FPM + Nginx in single container (or split into two containers)
   - Base image: PHP 8.3 FPM + Nginx Alpine
   - Port 8080 exposed
   - Volume mount: `./Project:/var/www/html/Project` (persistent project files)
   - Working directory: `/var/www/html`
   - Install extensions: pdo_mysql, pdo_pgsql, zip, pcntl, proc_open (for terminal)

2. **hermes-db** (optional) — MySQL 8 or PostgreSQL for the panel's own database (user accounts, session, audit logs). If not needed, use file-based session.

### Auto-Discovery

Panel scans the `Project/` directory on each page load (or cached with TTL). A folder is recognized as a Laravel project if it contains an `artisan` file. Non-Laravel folders are displayed as "Generic Project" — file manager works but Laravel Tools are unavailable.

Project metadata read from target project's `.env` and `composer.json`:
- App name (`APP_NAME` in `.env`)
- Laravel version (`require.laravel/framework` in `composer.json`)
- PHP version requirement (`require.php` in `composer.json`)
- Database configuration (`DB_*` variables in `.env`)

---

## 3. Authentication & Security

### Login Methods (3 methods, any one grants access)

1. **Password Login** (primary)
   - Login form at `/panel/login`
   - Credentials: `PANEL_USERNAME` and `PANEL_PASSWORD` from panel's `.env`
   - On success: store in session (`panel_auth`)
   - Configurable session timeout via `PANEL_SESSION_LIFETIME` (default: 120 minutes)

2. **WhatsApp Number** (alternative)
   - Header `X-WA-Sender` or query param `sender` from WhatsApp gateway
   - Numbers configured via `PANEL_OWNER_NUMBERS` in `.env` (comma-separated)
   - Numbers normalized to Indonesian format (+62 prefix)
   - Grants immediate access without session creation (stateless per-request)

3. **Local Bypass**
   - Automatic access when `APP_ENV=local`
   - For development only

### Auth Flow

```
User visits /panel/* → Check session
  ├─ Session valid → Allow access
  └─ No session → Check WA header → Check query param
       ├─ Match → Allow access
       └─ No match → Redirect to /panel/login
            ├─ Submit password → Match → Create session → Redirect to dashboard
            └─ Mismatch → Show error + rate limit
```

### Security Measures

- **CSRF protection** — Enabled for all POST routes (default Laravel)
- **Rate limiting** — 5 failed login attempts → lock for 60 seconds
- **Middleware** — `owner.access` applied to entire `Route::prefix('panel')` group
- **Path traversal protection** — `realpath()` checks on all file operations (existing, preserved)
- **Artisan command whitelist** — Removed (Docker container provides isolation)
- **SQL injection** — Prevented via Laravel's query builder / parameterized raw queries
- **Terminal isolation** — Runs inside Docker container, no host access

---

## 4. UI/UX Design

### Theme

- Dark theme (default) + Light theme
- Toggle switch in sidebar footer
- Preference stored in browser `localStorage`

### Layout

```
┌─────────────────────────────────────────────────────┐
│  [Sidebar]  │  [Header: Breadcrumb + Project Name]  │
│             │───────────────────────────────────────│
│  Logo       │                                       │
│  ───────    │  [Content Area]                       │
│  Dashboard  │                                       │
│  Database   │                                       │
│  Files      │                                       │
│  Tools      │                                       │
│  Projects   │                                       │
│  ───────    │                                       │
│  [Project   │                                       │
│   Switcher] │                                       │
│  ───────    │                                       │
│  [User]     │                                       │
│  [Logout]   │                                       │
│  [🌙/☀️]    │                                       │
└─────────────────────────────────────────────────────┘
```

### Navigation

- **Tab-based SPA-like** — Clicking sidebar items switches content without full page reload (Alpine.js)
- Routes remain server-side rendered (Laravel Blade)
- Active tab highlighted in sidebar
- Responsive: sidebar collapses to hamburger menu on mobile

### Tech

- **Frontend:** Blade Templates + Alpine.js 3 + Tailwind CSS 4
- **Build:** Vite 8 + laravel-vite-plugin
- **Icons:** Font Awesome 6
- **Font:** Instrument Sans (Bunny Fonts)

### Routes

```
GET  /panel/login            → Login page
POST /panel/login            → Process login
POST /panel/logout           → Logout (clear session)
GET  /panel/dashboard        → Dashboard
GET  /panel/database         → Database Manager
GET  /panel/files            → File Manager
GET  /panel/tools            → Laravel Tools
GET  /panel/projects         → Project Management
GET  /panel/api/*            → AJAX endpoints (existing pattern, extended)
POST /panel/api/*            → AJAX endpoints
```

---

## 5. Dashboard

Overview of the currently active project.

### Stat Cards (4 cards, top row)

| Card | Source |
|------|--------|
| **Tables** | Count from `SHOW TABLES` on active project's DB |
| **Total Files** | Recursive file count in active project folder |
| **Storage Used** | Recursive folder size, formatted (B → KB → MB → GB) |
| **Projects** | Count of detected projects in `Project/` directory |

### Project Cards Grid

Displays all detected projects as clickable cards:

- Project name (folder name)
- Laravel version (from `composer.json`)
- PHP version requirement (from `composer.json`)
- Status badges: `.env` ✓/✗, `vendor/` ✓/✗, `storage/` ✓/✗, `DB connected` ✓/✗
- Last modified timestamp (folder mtime)
- Click card → switch to that project

### Quick Actions

- Cache Clear (active project)
- View Recent Logs (last 5 lines)
- Open File Manager (active project)
- Open Database Manager (active project)

### Activity

- Current timestamp (last activity)
- Panel uptime (time since panel started)

---

## 6. Database Manager

Located in the "Database" tab. Supports MySQL and PostgreSQL.

### Sub-tabs (horizontal)

1. **Tables**
   - List all tables in active project's database
   - Each table shows: name, approximate row count, size
   - Click table → switch to "Browse Data" sub-tab

2. **Browse Data**
   - Paginated table view (default 25 rows per page, configurable)
   - Sortable columns (click header to sort asc/desc)
   - Per-row actions: Edit (inline), Delete (with confirmation)
   - Top actions: Export (JSON/CSV), Back to table list

3. **SQL Editor**
   - Textarea for writing raw SQL queries
   - "Run" button
   - Output:
     - `SELECT` → results in paginated table
     - `INSERT/UPDATE/DELETE` → affected rows count
     - `DDL` (CREATE, ALTER, DROP) → confirmation dialog before execute, success/error message
   - Query history: last 10 queries in current session (clickable to re-run)

4. **Export**
   - Select table → choose format (JSON or CSV)
   - Auto-generated filename: `{table}_{YYYYMMDD_HHmmss}.{format}`
   - Download as file

### Multiple DB Connection

- Reads all `DB_*` variables from active project's `.env`
- Detects additional connections via naming convention: `DB_CONNECTION_SECONDARY`, `DB_HOST_SECONDARY`, `DB_DATABASE_SECONDARY`, etc.
- Dropdown "Connection" selector above all sub-tabs
- Default: primary connection from `DB_DATABASE`
- Switching connection refreshes table list and resets state

### Error Handling

- Connection failure → error message + checklist (check .env, check service running, check credentials)
- Query error → DB engine error message + syntax error line highlighting

---

## 7. File Manager

Located in the "Files" tab.

### Root Behavior

- **No project selected:** Root = `Project/` directory (see all project folders)
- **Project selected:** Root = selected project's folder

### Layout

```
┌─────────────────────────────────────────┐
│ [Breadcrumb: root > folder > subfolder] │
├─────────────────────────────────────────┤
│ [Search] [Upload] [New File] [New Folder]│
│ [Download Zip] [Terminal]               │
├─────────────────────────────────────────┤
│ ☐ │ Icon │ Name     │ Size │ Modified │ ⋮│
│ ☐ │ 📁   │ desakta  │  -   │ 14 May   │ ⋮│
│ ☐ │ 📁   │ project2 │  -   │ 10 May   │ ⋮│
│ ☐ │ 📄   │ readme  │ 2KB  │ 09 May   │ ⋮│
└─────────────────────────────────────────┘
```

### Features

| Feature | Description |
|---------|-------------|
| **Browse** | Navigate folders, click to enter, breadcrumb to go back |
| **View/Edit** | Open file in code editor with syntax highlighting (PHP, JS, CSS, HTML, JSON, SQL, YAML, MD, env). Files not in `editable_extensions` are view-only. |
| **Create** | Create empty file or folder in current directory |
| **Rename** | Inline rename, Enter to confirm, Escape to cancel |
| **Move/Copy** | Select item → click Move/Copy → browse target → paste |
| **Delete** | Confirmation dialog. Folder delete = recursive. |
| **Upload** | Drag & drop or file picker. Progress bar. Multi-file support. Max 10MB per file (configurable). |
| **Download** | Single file download, or folder download as zip archive |
| **Search** | Search files by name in current directory (and subdirectories toggle) |
| **Permissions** | Display chmod in column. Edit via numeric input (755, 644, etc.) |
| **Sort** | Sort by name, size, or date. Folders always on top. |

### Built-in Terminal

- "Terminal" button in toolbar → opens web-based terminal panel below file manager
- Full SSH-like terminal emulator (xterm.js or similar)
- Auto `cd` into active project folder
- Full command access: `ls`, `cat`, `grep`, `composer`, `npm`, `php artisan`, etc.
- No command restrictions (runs inside Docker container)
- Session per project, isolated
- Close button to collapse terminal panel

---

## 8. Laravel Tools

Located in the "Tools" tab. All commands execute in the context of the active project.

### Sub-tabs (horizontal)

1. **Artisan Commands**
   - Dropdown to select from common commands (cache:clear, migrate, etc.)
   - Or type any command manually (no whitelist — Docker provides isolation)
   - Input fields for arguments/options (`--seed`, `--force`, etc.)
   - "Run" button → real-time output streaming
   - History: last 10 commands executed

2. **Log Viewer**
   - Reads `storage/logs/laravel.log` from active project
   - Default: last 100 lines, "Load More" button
   - Auto-refresh toggle (5-second interval)
   - Filter by level: All, Error, Warning, Info, Debug
   - Search text within logs
   - Clear log button (with confirmation)
   - Color-coded output: Error = red, Warning = yellow, Info = blue, Debug = gray

3. **Queue Management**
   - Status: queue worker running or not
   - Pending/failed jobs list (from `failed_jobs` table)
   - Actions: Queue Restart, Queue Flush, Retry Failed Job (per-item)
   - Table columns: job name, queue name, failed_at, exception message

4. **Composer & NPM**
   - Quick action buttons:
     - Composer: `install`, `update`, `dump-autoload`
     - NPM: `install`, `run build`, `run dev`
   - Real-time output streaming
   - Project selector dropdown (run in different project without switching active project)

---

## 9. Projects Management

Located in the "Projects" tab.

### Project Cards Grid

Each card displays:
- Project name (folder name)
- Status badges: `.env` ✓/✗, `vendor/` ✓/✗, `storage/` ✓/✗, `DB connected` ✓/✗
- Laravel version (from `composer.json`)
- PHP version requirement (from `composer.json`)
- Total file count
- Storage used
- Last modified timestamp
- Actions: "Open" (switch to project), "Hide", "Delete Permanently"

### Auto-Discovery

- Panel scans `Project/` directory
- Detection rule: folder contains `artisan` file → Laravel project
- Folders without `artisan` → "Generic Project" (file manager works, Laravel Tools unavailable)
- Scan happens on page load (cached with configurable TTL)

### Manual Project Addition

- Input: project name + path (absolute path on disk)
- For projects located outside the `Project/` directory
- Stored in `projects.json`
- Manual projects appear alongside auto-discovered ones (with "Manual" badge)

### Project Switching

- Click "Open" on project card → or select from sidebar dropdown
- Session stores `active_project`
- All tabs (Dashboard, Database, Files, Tools) switch context to selected project
- File Manager root changes to selected project's folder
- Database Manager connects to selected project's DB

### Remove Options

1. **Hide** — Add project to blacklist (stored in `projects.json`). Project remains on disk but hidden from panel view. Can be un-hidden from a "Hidden Projects" section.
2. **Delete Permanently** — Destructive. Requires: double confirmation dialog + type project name to confirm. Executes `rm -rf` on the project folder.

---

## 10. Configuration

All panel settings via `.env`:

| Variable | Default | Description |
|----------|---------|-------------|
| `PANEL_NAME` | `Hermes Panel` | Panel display name |
| `PANEL_USERNAME` | — | Login username |
| `PANEL_PASSWORD` | — | Login password |
| `PANEL_OWNER_NUMBERS` | — | Comma-separated WhatsApp numbers |
| `PANEL_SESSION_LIFETIME` | `120` | Session timeout in minutes |
| `PANEL_PROJECTS_DIR` | `Project` | Project directory name (relative to panel root) |
| `PANEL_EDITABLE_EXTENSIONS` | (see below) | File extensions allowed for editing |
| `PANEL_MAX_UPLOAD_SIZE` | `10485760` | Max upload size in bytes (10MB) |
| `PANEL_BYPASS_PASSWORD` | — | Bypass password (deprecated in favor of PANEL_PASSWORD) |

**Default editable extensions:** `php`, `js`, `css`, `blade.php`, `html`, `txt`, `json`, `env`, `md`, `yaml`, `yml`, `xml`, `sql`, `gitignore`, `htaccess`

---

## 11. Technical Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Frontend framework | Alpine.js + Blade | Lightweight, no build complexity, fits server-rendered Laravel app |
| CSS framework | Tailwind CSS 4 | Utility-first, consistent with existing codebase |
| Build tool | Vite 8 | Fast HMR, Laravel ecosystem standard |
| Terminal emulator | xterm.js | Industry standard web terminal, used by VS Code |
| Code editor | CodeMirror 6 or Monaco | Syntax highlighting, lightweight compared to full Monaco |
| File archive | ZipArchive (PHP) | Native PHP extension, no external dependency |
| Project metadata cache | File-based JSON (`projects.json`) | Simple, no extra DB dependency for discovery data |
| Session driver | File (default Laravel) | Sufficient for single-user panel |

---

## 12. Out of Scope (Future Considerations)

- Multi-user/role management
- Server resource monitoring (CPU, RAM, disk)
- SSL certificate management
- Domain/subdomain management
- Scheduled task (cron) management
- Email/notification system
- Backup/restore project snapshots
- Git integration (commit, push, pull from UI)
