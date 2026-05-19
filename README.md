# Hermes Panel

<div align="center">

**Your server, your rules.**

A cPanel alternative for Laravel projects on VPS. Built with Laravel 13, Alpine.js, and Tailwind CSS v4.

[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![Alpine.js 3.x](https://img.shields.io/badge/Alpine.js-3.x-8BC0D0?style=flat-square)](https://alpinejs.dev)

</div>

---

## ✨ Highlights

- 🔐 **Multi-layer Authentication** — Password login, WhatsApp number bypass, and CSRF-exempt API routes
- 📊 **Smart Dashboard** — Real-time system stats, project overview cards, and quick action buttons
- 🗄️ **Database Manager** — Multi-DB support (MySQL/PostgreSQL), SQL editor, inline editing, soft-delete trash with restore
- 📁 **File Manager** — Browse, edit, upload, download ZIP, search, chmod permissions, and built-in terminal
- ⚡ **Laravel Tools** — Artisan runner, log viewer, queue monitor, seeder runner, Composer & NPM
- 🖥️ **Web Terminal** — Full SSH-like experience via xterm.js + WebSocket (Laravel Reverb)
- 🌓 **Dark/Light Theme** — Toggle between modes, persisted in localStorage

---

## 📋 Table of Contents

1. [What is Hermes Panel?](#what-is-hermes-panel)
2. [Feature Showcase](#feature-showcase)
3. [Architecture Overview](#architecture-overview)
4. [Getting Started](#getting-started)
5. [Configuration Reference](#configuration-reference)
6. [Scheduled Jobs](#scheduled-jobs)
7. [Security Notes](#security-notes)
8. [Deployment Guide](#deployment-guide)
9. [License](#license)

---

## What is Hermes Panel?

### The Problem

Managing multiple Laravel projects on a VPS is painful. You end up juggling:
- **cPanel** or **Plesk** — overkill, ugly, expensive
- **phpMyAdmin** / **Adminer** — separate tools for database management
- **FTP/File manager** — clunky file editing
- **SSH terminals** — powerful but not visual

### The Solution

Hermes Panel is a unified, beautifully crafted control panel that replaces all of the above. It's designed specifically for developers who run their own VPS and manage Laravel projects.

> **Who it's for:** Developers managing their own VPS with multiple Laravel projects who want a fast, elegant alternative to cPanel.

### What It Replaces

| Instead of... | Use Hermes Panel... |
|---|---|
| cPanel / Plesk | ✅ Full server management UI |
| phpMyAdmin / Adminer | ✅ Built-in Database Manager |
| File manager / FTP | ✅ Visual File Manager |
| Separate SSH client | ✅ Web Terminal |
| Manual artisan commands | ✅ Laravel Tools dashboard |

---

## Feature Showcase

### 🔐 Authentication

Three ways to access your panel:

| Method | How it works |
|---|---|
| **Password** | Standard login form at `/panel/login` |
| **WhatsApp Bypass** | Send `X-WA-Sender` header with your registered number |
| **Header Password** | Pass `X-Panel-Password` header or `?password=` query param |

The `OwnerAccess` middleware checks authentication in this priority order:
1. Active session (`session('panel_auth')`)
2. Header password (`X-Panel-Password`)
3. WhatsApp number (`X-WA-Sender`)
4. Local environment bypass

> **Note:** API routes are CSRF-exempt via `PanelApiCsrf` middleware — safe for AJAX calls from your WhatsApp bot or external scripts.

---

### 📊 Dashboard

The landing page after login. Shows:

- **System Stats** — CPU, memory, disk usage at a glance
- **Project Cards** — Each active project displayed as a card with status indicator
- **Quick Actions** — One-click buttons for common tasks:
  - Clear cache
  - View recent logs
  - Restart queue workers
  - Open terminal

**Visual Design:**
- Greek letter navigation anchors (α β γ δ ε) in the sidebar
- Subtle pulse animation on the status dot
- Cards with hover lift effect (transform + shadow)
- Staggered fade-up animations on page load

---

### 🗄️ Database Manager

A full-featured database IDE within the panel.

#### Multi-Database Support
- Automatically detects primary database from `.env`
- Supports additional connections (`DB_CONNECTION_SECONDARY`, etc.)
- Works with **MySQL** and **PostgreSQL**

#### SQL Editor
- Syntax-highlighted query input
- Execute SELECT, INSERT, UPDATE, DELETE, ALTER, DROP, CREATE, TRUNCATE, RENAME
- Results displayed in paginated table with sorting
- Error messages are user-friendly (no raw SQL exposure)

#### Browse & Edit
- List all tables with row count and size
- Paginated data viewing (25 rows per page default)
- **Inline cell editing** — double-click any cell to edit directly
- Column metadata shown (type, nullable, key, default)
- Primary key auto-detected

#### Trash Tab (Soft Deletes)
- View soft-deleted rows (`deleted_at` not null)
- **Restore** — bring a row back to active state
- **Force Delete** — permanently remove
- **Empty Trash** — batch purge all soft-deleted rows

> **How it detects soft deletes:** If a table has a `deleted_at` column, Hermes automatically enables trash features.

#### Export Data
- Export any table to **JSON** or **CSV**
- Downloads with timestamped filename
- Includes metadata (table name, exported_at, row_count)

---

### 📁 File Manager

Full-featured file browser with visual editor.

#### Navigation
- Tree-style folder navigation
- Breadcrumb trail for current path
- Click to open files, double-click folders

#### File Operations
- **Browse** — List files with size, modified date, permissions
- **Edit** — Syntax-highlighted code editor for common file types
- **Upload** — Drag-and-drop or click to upload
- **Download** — Single file or entire folder as ZIP
- **Search** — Find files by name, optionally recursive
- **Chmod** — Change file permissions visually
- **Rename / Move / Copy / Delete** — Full CRUD operations

#### Editable File Types
```
php, js, css, blade.php, html, txt, json, env, md,
yaml, yml, xml, sql, gitignore, htaccess
```

#### Path Security
All file operations are sandboxed within the project directory. `FileService::resolvePath()` prevents path traversal attacks.

---

### ⚡ Laravel Tools

A dashboard for running Laravel commands without SSH.

#### Artisan Runner
- Pre-defined command suggestions (migrate, seed, cache:clear, etc.)
- Custom command input with autocomplete feel
- Real-time output display
- Error highlighting in red

#### Log Viewer
- Lists recent log files from `storage/logs`
- Displays contents with syntax coloring
- One-click clear logs

#### Queue Monitor
- Check queue status (working, pending, failed)
- Retry failed jobs by ID
- Restart queue workers
- Flush all failed jobs

#### Seeder Runner
- List available seeders from `database/seeders`
- Run specific seeder classes
- Fresh install with `--fresh` flag

#### Composer & NPM
- Run `composer` commands (install, update, require, etc.)
- Run `npm` commands (dev, prod, build)
- Output displayed in real-time

---

### 🖥️ Web Terminal

Full SSH-like terminal in your browser.

**Implementation:**
- **xterm.js** — Terminal emulator (rendered in a div, not an actual terminal)
- **Laravel Reverb** — WebSocket provider for real-time bidirectional communication
- Commands execute via `TerminalService` using `Illuminate\Support\Facades\Process`

**Features:**
- ANSI color support
- Scrollback history
- Full keyboard support (Ctrl+C, Ctrl+D, etc.)
- Reset/clear button
- Auto-scrolls to bottom on output

> **Security:** All commands run within the active project's directory — no escape to parent directories.

---

### 🌓 Dark / Light Theme

Toggle between dark mode (default) and light mode.

- **Dark:** Warm charcoal tones (#0e0d0a) with copper accents (#d4a45c)
- **Light:** Warm cream tones (#f4ede1) with deep brown accents
- Persisted in `localStorage` — remembers your preference
- Smooth transition animation (0.3s) when toggling

---

## Architecture Overview

### Tech Stack

| Layer | Technology |
|---|---|
| **Backend** | Laravel 13.8 |
| **Frontend** | Alpine.js 3.x + Tailwind CSS 4.0 |
| **Build Tool** | Vite 8.0 |
| **Container** | Docker + Docker Compose |
| **Process Manager** | PM2 (ecosystem.config.js) |
| **WebSocket** | Laravel Reverb |
| **Fonts** | Fraunces (serif) + JetBrains Mono |

### Directory Structure

```
hermes-panel/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php              # Base controller
│   │   │   ├── LandingController.php        # Landing page
│   │   │   └── Panel/
│   │   │       ├── AuthController.php       # Login/logout
│   │   │       ├── DashboardController.php  # Dashboard
│   │   │       ├── DatabaseController.php   # Inline edit, trash
│   │   │       ├── FileController.php       # File operations
│   │   │       ├── ProjectController.php    # Project management
│   │   │       ├── TerminalController.php   # Web terminal
│   │   │       └── ToolController.php       # Artisan, logs, queue
│   │   └── Middleware/
│   │       ├── OwnerAccess.php              # Auth + WA bypass
│   │       └── PanelApiCsrf.php             # CSRF exempt for API
│   ├── Jobs/
│   │   └── CleanupDatabaseTrash.php         # Monthly cleanup
│   └── Services/
│       ├── DatabaseService.php              # Multi-DB operations
│       ├── FileService.php                  # File CRUD + security
│       ├── ProjectService.php               # Discovery + management
│       └── TerminalService.php              # Command execution
├── config/
│   └── panel.php                            # Panel configuration
├── docker/
│   ├── Dockerfile                          # PHP + Nginx container
│   ├── nginx.conf                          # Web server config
│   ├── php-fpm.conf                        # PHP-FPM settings
│   └── supervisord.conf                     # Process supervisor
├── public/
├── resources/
│   ├── css/
│   │   └── app.css                         # Tailwind + design system
│   ├── js/
│   │   └── app.js                          # Alpine.js components
│   └── views/panel/
│       ├── layout.blade.php                 # Main layout (sidebar + header)
│       ├── login.blade.php                  # Login page
│       ├── dashboard.blade.php             # Dashboard page
│       ├── database.blade.php               # Database manager
│       ├── files.blade.php                  # File manager
│       ├── projects.blade.php               # Project management
│       ├── tools.blade.php                  # Laravel tools
│       └── terminal.blade.php               # Web terminal
├── routes/
│   ├── web.php                             # All HTTP routes
│   └── console.php                          # Scheduled commands
├── storage/
├── tests/
├── composer.json
├── package.json
├── vite.config.js
├── docker-compose.yml
├── ecosystem.config.js                      # PM2 configuration
└── .env.example                             # Environment template
```

### Service Architecture

#### ProjectService
Manages project discovery and metadata.

| Method | Description |
|---|---|
| `getAllProjects(bool $includeHidden)` | Get all discovered + manually added projects |
| `discoverProjects()` | Scan `Project/` directory, detect Laravel via `artisan` |
| `switchProject(?string $name)` | Switch active project in session |
| `readEnv(string $path)` | Parse `.env` file (masks passwords) |
| `testDbConnection(array $env)` | Test database connectivity |

**Caching:** Project discovery is cached for 5 minutes via `Cache::remember('panel.discovered_projects')`.

#### DatabaseService
Handles all database operations.

| Method | Description |
|---|---|
| `configureConnection(string $name, array $env)` | Set up dynamic connection config |
| `getConnections(array $env)` | Detect primary + additional connections |
| `getTables(string $connectionName)` | List tables (MySQL + PostgreSQL) |
| `getTableData(...)` | Paginated table data with sorting |
| `runQuery(string $connectionName, string $sql)` | Execute raw SQL safely |
| `exportTable(...)` | Export to JSON/CSV |
| `getTrashData(...)` | Get soft-deleted rows |
| `restoreRow(...)` | Restore a soft-deleted row |
| `forceDeleteRow(...)` | Permanently delete a row |

**Connection Pattern:** Dynamic connection config named `panel_project_{$name}`.

#### FileService
Provides secure file system operations.

| Method | Description |
|---|---|
| `listDirectory(string $relativePath)` | List directory with metadata |
| `getFileContent(string $relativePath)` | Read file contents |
| `saveFile(string $relativePath, string $content)` | Write/update file |
| `search(string $path, string $query, bool $recursive)` | Find files by name |
| `resolvePath(string $relativePath)` | **Security:** Prevents path traversal |
| `getPermissions(string $relativePath)` | Get file permissions |
| `setPermissions(string $relativePath, int $mode)` | Change file permissions |

**Editable Extensions:** `['php', 'js', 'css', 'blade.php', 'html', 'txt', 'json', 'env', 'md', 'yaml', 'yml', 'xml', 'sql', 'gitignore', 'htaccess']`

---

## Design System

### Color Palette

```
┌─────────────────────────────────────────────────────────────────┐
│  INK (Background)                                               │
├─────────────────────────────────────────────────────────────────┤
│  --ink:       #0e0d0a   │ Primary dark background              │
│  --ink-soft:  #15130f   │ Card/surface backgrounds             │
│  --ink-card:  #1a1812   │ Elevated surfaces                    │
├─────────────────────────────────────────────────────────────────┤
│  PAPER (Text)                                                   │
├─────────────────────────────────────────────────────────────────┤
│  --paper:     #f4ede1   │ Primary text                          │
│  --paper-soft: #ddd2bd  │ Secondary text                       │
│  --paper-dim:  #8a8275  │ Muted/tertiary text                  │
├─────────────────────────────────────────────────────────────────┤
│  ACCENT                                                        │
├─────────────────────────────────────────────────────────────────┤
│  --copper:       #d4a45c │ Primary accent (buttons, active)     │
│  --copper-deep:  #a87a3c │ Hover states                         │
│  --copper-glow:  rgba(212, 164, 92, 0.15) │ Glow effect         │
│  --verdigris:    #5a7a5a │ Success/positive states             │
│  --rust:         #b85c44 │ Danger/error states                  │
├─────────────────────────────────────────────────────────────────┤
│  RULE (Borders)                                                 │
├─────────────────────────────────────────────────────────────────┤
│  --rule:          rgba(244, 237, 225, 0.10)  │ Subtle borders   │
│  --rule-strong:   rgba(244, 237, 225, 0.24) │ Strong borders    │
│  --rule-copper:   rgba(212, 164, 92, 0.35)  │ Copper highlight │
└─────────────────────────────────────────────────────────────────┘
```

### Typography

| Role | Font | Config | Usage |
|---|---|---|---|
| **Serif** | Fraunces | `'opsz' 144, 'wght' 400, 'WONK' 1` | Headlines, brand |
| **Serif Italic** | Fraunces | `'opsz' 144, 'wght' 300, 'SOFT' 60, 'WONK' 1` | Accent text, copper-colored |
| **Mono** | JetBrains Mono | Various weights | UI labels, code, navigation |

**Scale:**
- Title Editorial: `clamp(36px, 5vw, 64px)` — Hero headlines
- Navigation: 11px mono uppercase, letter-spacing 0.18em
- Labels: 10px mono uppercase, letter-spacing 0.22em
- Body/Mono content: 12-13px mono

### Greek Glyph Navigation

The sidebar uses Greek letters as visual anchors:

```
α → Dashboard
β → Database
γ → Files
δ → Tools
ε → Projects
```

### Animations

```css
/* Page entrance — fade up with stagger */
@keyframes fade-up-page {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}

.animate-fade-up {
    animation: fade-up-page 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.animate-fade-up-1 { animation-delay: 0.1s; }
.animate-fade-up-2 { animation-delay: 0.2s; }
.animate-fade-up-3 { animation-delay: 0.3s; }

/* Pulse dot — status indicator */
@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(0.7); }
}

/* Button hover — lift with shadow */
.btn-copper:hover:not(:disabled) {
    transform: translate(-2px, -2px);
    box-shadow: 4px 4px 0 var(--copper-deep);
}

/* Card hover — subtle lift */
.card-editorial:hover {
    border-color: var(--rule-strong);
}
```

---

## Getting Started

### Requirements

| Requirement | Version | Notes |
|---|---|---|
| PHP | 8.3+ | Laravel 13 requires PHP 8.2+ |
| Composer | Latest | For Laravel dependencies |
| Docker | 24+ | Container runtime |
| Docker Compose | 2.20+ | Container orchestration |
| Node.js | 20+ | For asset building (npm) |
| MySQL | 8.0+ | Or PostgreSQL 16+ |

### Installation

#### 1. Clone the repository

```bash
git clone <your-repo-url>
cd hermes-panel
```

#### 2. Configure environment

```bash
cp .env.example .env
```

Edit `.env` with your settings:

```env
PANEL_NAME=Hermes Panel
PANEL_USERNAME=admin
PANEL_PASSWORD=your_secure_password
PANEL_SESSION_LIFETIME=120
PANEL_OWNER_NUMBERS=62895341414271,6281234567890
PANEL_PROJECTS_DIR=Project
PANEL_DEFAULT_PROJECT=desakta
```

#### 3. Build and start Docker

```bash
docker compose up -d --build
```

#### 4. Access the panel

```
http://your-vps-ip:8080
```

### First-Time Setup Checklist

- [ ] Set `PANEL_PASSWORD` to a strong password
- [ ] Add your WhatsApp number to `PANEL_OWNER_NUMBERS` (with country code, no +)
- [ ] Place your Laravel projects in the `Project/` directory
- [ ] Verify project auto-discovery works (check Dashboard)
- [ ] Test database connection (open Database Manager)
- [ ] Set `PANEL_DEFAULT_PROJECT` to auto-select a project on login

---

## Configuration Reference

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `PANEL_NAME` | `Hermes Panel` | Display name in header and title |
| `PANEL_USERNAME` | `admin` | Login username |
| `PANEL_PASSWORD` | *(required)* | Login password |
| `PANEL_SESSION_LIFETIME` | `120` | Session lifetime in minutes |
| `PANEL_OWNER_NUMBERS` | `""` | WhatsApp numbers (comma-separated, with country code) |
| `PANEL_PROJECTS_DIR` | `Project` | Directory containing managed projects |
| `PANEL_DEFAULT_PROJECT` | — | Auto-select project on login (e.g., `desakta`) |
| `PANEL_MAX_UPLOAD_SIZE` | `10485760` | Max file upload size in bytes (default: 10MB) |

### Config File (config/panel.php)

```php
return [
    // Display
    'name' => env('PANEL_NAME', 'Hermes Panel'),

    // Auth
    'username' => env('PANEL_USERNAME', 'admin'),
    'password' => env('PANEL_PASSWORD', ''),
    'session_lifetime' => (int) env('PANEL_SESSION_LIFETIME', 120),
    'owner_numbers' => array_filter(explode(',', env('PANEL_OWNER_NUMBERS', '')), ...),

    // Projects
    'projects_dir' => env('PANEL_PROJECTS_DIR', 'Project'),
    'default_project' => env('PANEL_DEFAULT_PROJECT'),
    'discovery_cache_ttl' => 300, // 5 minutes

    // Files
    'editable_extensions' => [
        'php', 'js', 'css', 'blade.php', 'html', 'txt',
        'json', 'env', 'md', 'yaml', 'yml', 'xml', 'sql',
        'gitignore', 'htaccess',
    ],
    'max_upload_size' => (int) env('PANEL_MAX_UPLOAD_SIZE', 10485760),

    // Artisan suggestions
    'suggested_artisan_commands' => [
        'cache:clear', 'config:clear', 'view:clear', 'route:clear',
        'migrate', 'migrate:fresh', 'migrate:rollback',
        'db:seed', 'queue:restart', 'key:generate',
        'make:seeder', 'make:migration', 'make:model',
    ],
];
```

---

## Scheduled Jobs

### CleanupDatabaseTrash

**Schedule:** Monthly (on the 1st at 00:00)  
**Purpose:** Automatically cleans soft-deleted database rows older than 30 days across all managed projects.

**How it works:**
1. Iterate through all discovered Laravel projects
2. For each project, read `.env` and configure database connection
3. List all tables
4. Detect tables with soft deletes (`deleted_at` column)
5. Delete rows where `deleted_at < 30 days ago`
6. Log results to `storage/logs/laravel.log`

**Configuration:**
```php
// In routes/console.php
$schedule->job(new \App\Jobs\CleanupDatabaseTrash())->monthlyOn(1, '00:00');
```

---

## Security Notes

### Owner-Only Access Model

Hermes Panel is designed for **single-user** or **trusted user** access. There is no multi-user support.

All routes are protected by the `OwnerAccess` middleware which checks:
1. Active session
2. Header password
3. WhatsApp number verification

### WhatsApp Number Verification

Numbers are stored with country code (no +). The `normalizeNumber()` function handles:
- Leading `0` → replaced with `62` (Indonesia)
- Missing `62` → prepend `62`

Example: `0895341414271` → `62895341414271`

### CSRF Protection

- Web routes use standard Laravel CSRF middleware
- API routes (`/api/*`) are CSRF-exempt via `PanelApiCsrf` middleware
- All API requests require `X-Requested-With: XMLHttpRequest` header

### Path Traversal Prevention

`FileService::resolvePath()` ensures all file operations stay within the project directory:

```php
$realPath = realpath($fullPath);
$realBase = realpath($basePath);
if (!$realPath || !$realBase || !str_starts_with($realPath, $realBase)) {
    return null; // Access denied
}
```

### Rate Limiting

The `OwnerAccess` middleware integrates with Laravel's `RateLimiter`. Session timeout refreshes on each authenticated request.

---

## Deployment Guide

### Option 1: Docker (Recommended)

```bash
# Build and run
docker compose up -d --build

# View logs
docker compose logs -f

# Restart
docker compose restart
```

### Option 2: PM2 (Manual)

```bash
# Install dependencies
composer install --optimize-autoloader
npm install
npm run build

# Start with PM2
pm2 start ecosystem.config.js

# Save PM2 process list
pm2 save

# Setup PM2 startup script
pm2 startup
```

### Option 3: GitHub Deployment

1. Push to GitHub repository
2. Set up a webhook to trigger deployment
3. On your VPS: `git pull && docker compose up -d --build`

### Nginx Reverse Proxy (Optional)

For running alongside other services:

```nginx
server {
    listen 80;
    server_name panel.yourdomain.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

### Cloudflare Tunnel (No Public IP)

If your VPS is behind NAT:

```bash
# Install cloudflared
curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -o /usr/local/bin/cloudflared
chmod +x /usr/local/bin/cloudflared

# Create tunnel
cloudflared tunnel --url http://localhost:8080
```

---

## License

Proprietary. All rights reserved.

---

<div align="center">

**Crafted with care for developers who self-host.**

*Hermes Panel v2.0*

</div>