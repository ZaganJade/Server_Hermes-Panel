# Hermes Panel — Agent Context Documentation

**Version:** 2.0  
**Last Updated:** {{ date('Y-m-d') }}  
**Project Type:** VPS Server Administration Panel (cPanel alternative for Laravel projects)

---

## 1. Project Overview

**Hermes Panel** adalah panel administrasi server mirip cPanel yang dirancang untuk mengelola multiple proyek Laravel di VPS. Built dengan Laravel 13, Alpine.js, dan Tailwind CSS v4.

### Core Features
- **Authentication:** Password login + WhatsApp number header bypass
- **Dashboard:** System stats, quick actions, project overview cards
- **Project Management:** Auto-discovery Laravel projects, manual add, hide/delete
- **Database Manager:** Multi-DB connection support, SQL editor, browse data, export (JSON/CSV)
- **File Manager:** Browse, edit, upload, download (zip), search, chmod, built-in terminal
- **Laravel Tools:** Artisan runner, log viewer, queue monitor, Composer & NPM commands
- **Terminal:** Full SSH-like web terminal via xterm.js + WebSocket (Reverb)
- **Dark/Light Theme:** Toggle between dark (default) and light modes, persisted in localStorage

---

## 2. Architecture

### 2.1 Tech Stack

| Layer | Technology |
|---|---|
| Backend Framework | Laravel 13.8 |
| Frontend | Alpine.js 3.x + Tailwind CSS 4.0 |
| Build Tool | Vite 8.0 |
| Container | Docker + Docker Compose |
| Process Manager | PM2 (ecosystem.config.js) |
| Fonts | Fraunces (serif) + JetBrains Mono |

### 2.2 Directory Structure

```
hermes-panel/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php          # Base controller
│   │   │   ├── LandingController.php    # Landing page
│   │   │   └── Panel/
│   │   │       ├── AuthController.php   # Login/logout
│   │   │       ├── DashboardController.php
│   │   │       ├── DatabaseController.php
│   │   │       ├── FileController.php
│   │   │       ├── ProjectController.php
│   │   │       ├── TerminalController.php
│   │   │       └── ToolController.php
│   │   └── Middleware/
│   │       └── OwnerAccess.php          # Auth + WA bypass middleware
│   ├── Models/
│   ├── Providers/
│   └── Services/
│       ├── DatabaseService.php          # Multi-DB operations
│       ├── FileService.php              # File CRUD operations
│       ├── ProjectService.php           # Project discovery & management
│       └── TerminalService.php          # Command execution
├── bootstrap/
├── config/
│   └── panel.php                        # Panel-specific configuration
├── database/
├── docker/
│   ├── Dockerfile
│   ├── nginx.conf
│   ├── php-fpm.conf
│   └── supervisord.conf
├── public/
├── resources/
│   ├── css/
│   │   └── app.css                      # Tailwind + custom design system
│   ├── js/
│   │   └── app.js
│   └── views/panel/
│       ├── layout.blade.php             # Main layout (sidebar + header)
│       ├── login.blade.php
│       ├── dashboard.blade.php
│       ├── database.blade.php
│       ├── files.blade.php
│       ├── projects.blade.php
│       └── tools.blade.php
├── routes/
│   └── web.php                          # All routes
├── storage/
├── tests/
├── composer.json
├── package.json
├── vite.config.js
├── docker-compose.yml
└── ecosystem.config.js
```

### 2.3 Service Architecture

#### ProjectService
- **Responsibility:** Project discovery, management, metadata
- **Key Methods:**
  - `getAllProjects(bool $includeHidden)` — Get all discovered + manual projects
  - `discoverProjects()` — Scan `Project/` directory, detect Laravel via `artisan`
  - `switchProject(?string $name)` — Switch active project in session
  - `readEnv(string $path)` — Parse `.env` file
  - `testDbConnection(array $env)` — Test DB connectivity
- **Caching:** 5-minute TTL via `Cache::remember('panel.discovered_projects')`

#### DatabaseService
- **Responsibility:** Multi-database operations
- **Key Methods:**
  - `getConnections(array $env)` — Detect primary + additional connections
  - `getTables(string $connectionName)` — List tables (MySQL + PostgreSQL)
  - `getTableData(...)` — Paginated table data with sorting
  - `runQuery(string $connectionName, string $sql)` — Execute raw SQL
  - `exportTable(...)` — Export to JSON/CSV
- **Connection Pattern:** Dynamic connection config `panel_project_{$name}`

#### FileService
- **Responsibility:** File system operations with path traversal protection
- **Key Methods:**
  - `listDirectory(string $relativePath)` — List with metadata
  - `getFileContent(string $relativePath)` — Read file
  - `saveFile(string $relativePath, string $content)` — Write file
  - `search(string $path, string $query, bool $recursive)` — File search
  - `resolvePath(string $relativePath)` — **Security:** Prevents path traversal
- **Editable Extensions:** `['php', 'js', 'css', 'blade.php', 'html', 'txt', 'json', 'env', 'md', 'yaml', 'yml', 'xml', 'sql', 'gitignore', 'htaccess']`

#### TerminalService
- **Responsibility:** Execute shell commands in project context
- **Implementation:** Uses `Illuminate\Support\Facades\Process`
- **Security:** Commands run in project directory only

---

## 3. Design System

### 3.1 Color Palette

```
┌─────────────────────────────────────────────────────────────────┐
│  INK (Background)                                               │
├─────────────────────────────────────────────────────────────────┤
│  --ink:       #0e0d0a   │ Primary dark background                │
│  --ink-soft:  #15130f   │ Card/surface backgrounds               │
│  --ink-card:  #1a1812   │ Elevated surfaces                      │
├─────────────────────────────────────────────────────────────────┤
│  PAPER (Text)                                                    │
├─────────────────────────────────────────────────────────────────┤
│  --paper:     #f4ede1   │ Primary text                           │
│  --paper-soft: #ddd2bd   │ Secondary text                        │
│  --paper-dim:  #8a8275   │ Muted/tertiary text                    │
├─────────────────────────────────────────────────────────────────┤
│  ACCENT                                                        │
├─────────────────────────────────────────────────────────────────┤
│  --copper:       #d4a45c  │ Primary accent (buttons, active)      │
│  --copper-deep:  #a87a3c  │ Darker copper (hover states)          │
│  --copper-glow:  rgba(212, 164, 92, 0.15) │ Glow effect          │
│  --verdigris:    #5a7a5a  │ Success/positive states               │
│  --rust:         #b85c44  │ Danger/error states                   │
├─────────────────────────────────────────────────────────────────┤
│  RULE (Borders)                                                  │
├─────────────────────────────────────────────────────────────────┤
│  --rule:          rgba(244, 237, 225, 0.10)   │ Subtle borders    │
│  --rule-strong:   rgba(244, 237, 225, 0.24)  │ Strong borders    │
│  --rule-copper:   rgba(212, 164, 92, 0.35)    │ Copper highlight  │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 Typography

| Role | Font | Config | Usage |
|---|---|---|---|
| **Serif** | Fraunces | `'opsz' 144, 'wght' 400, 'WONK' 1` | Headlines, brand, Greek glyphs |
| **Serif Italic** | Fraunces | `'opsz' 144, 'wght' 300, 'SOFT' 60, 'WONK' 1` | Accent text, copper-colored |
| **Mono** | JetBrains Mono | Various weights | UI labels, code, navigation |

**Scale:**
- Title Editorial: `clamp(36px, 5vw, 64px)` — Hero headlines
- Navigation: 11px mono uppercase, letter-spacing 0.18em
- Labels: 10px mono uppercase, letter-spacing 0.22em
- Body/Mono content: 12-13px mono

### 3.3 Greek Glyph Navigation

The sidebar uses Greek letters as visual anchors:
```
α → Dashboard
β → Database
γ → Files
δ → Tools
ε → Projects
```

### 3.4 Button Components

```css
/* Primary CTA */
.btn-copper {
    font-family: mono; font-size: 11px; letter-spacing: 0.22em; uppercase;
    color: var(--ink); background: var(--copper); border: 1px solid var(--copper);
    padding: 12px 22px;
    transition: all 0.3s cubic-bezier(0.65, 0, 0.35, 1);
}
.btn-copper:hover:not(:disabled) {
    background: var(--paper); border-color: var(--paper);
    transform: translate(-2px, -2px);
    box-shadow: 4px 4px 0 var(--copper-deep);
}

/* Ghost/Secondary */
.btn-ghost {
    color: var(--paper); background: transparent;
    border: 1px solid var(--rule-strong);
}
.btn-ghost:hover { border-color: var(--copper); color: var(--copper); }

/* Danger */
.btn-danger {
    color: var(--rust); background: transparent;
    border: 1px solid var(--rust);
}
.btn-danger:hover { background: var(--rust); color: var(--paper); }

/* Mini */
.btn-mini {
    font-size: 9px; padding: 6px 12px; /* Compact actions */
}
```

### 3.5 Input Components

```css
.input-editorial, .select-editorial, .textarea-editorial {
    font-family: var(--mono); font-size: 13px;
    color: var(--paper); background: var(--ink-soft);
    border: 1px solid var(--rule-strong);
    padding: 12px 16px;
}
.input-editorial:focus,
.select-editorial:focus {
    border-color: var(--copper);
}
```

### 3.6 Card Component

```css
.card-editorial {
    background: var(--ink-soft);
    border: 1px solid var(--rule);
    padding: 28px;
    transition: border-color 0.3s ease;
}
.card-editorial:hover { border-color: var(--rule-strong); }

/* Optional: Number badge */
.card-num {
    position: absolute; top: 16px; right: 20px;
    font-family: var(--mono); font-size: 9px; letter-spacing: 0.2em;
    color: var(--paper-dim); text-transform: uppercase;
}
```

### 3.7 Toast Notifications

```css
.toast {
    font-family: var(--mono); font-size: 11px;
    letter-spacing: 0.18em; text-transform: uppercase;
    padding: 14px 22px; border: 1px solid;
    box-shadow: 4px 4px 0 rgba(0,0,0,0.4);
}
.toast-success { border-color: var(--copper); color: var(--copper); }
.toast-error   { border-color: var(--rust); color: var(--rust); }
.toast-warning { border-color: #c8a04a; color: #c8a04a; }
.toast-info    { border-color: var(--paper-soft); color: var(--paper-soft); }
```

### 3.8 Modal Component

```css
.modal-overlay {
    position: fixed; inset: 0; z-index: 50;
    background: rgba(8, 7, 5, 0.85);
    backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
}

.modal-card {
    background: var(--ink-soft); border: 1px solid var(--rule-strong);
    box-shadow: 8px 8px 0 var(--copper-deep);
    max-width: 600px; max-height: 90vh; overflow: auto;
}
```

### 3.9 Table Component

```css
.table-editorial thead th {
    font-family: var(--mono); font-size: 10px;
    letter-spacing: 0.2em; text-transform: uppercase;
    color: var(--paper-dim); padding: 14px 18px;
    border-bottom: 1px solid var(--rule-strong); background: var(--ink);
}
.table-editorial tbody td {
    padding: 14px 18px; border-bottom: 1px solid var(--rule);
    color: var(--paper); font-family: var(--mono); font-size: 12px;
}
.table-editorial tbody tr:hover { background: var(--ink-soft); }
```

### 3.10 Animation System

```css
/* Page entrance */
@keyframes fade-up-page {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}
.animate-fade-up { animation: fade-up-page 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
.animate-fade-up-1 { animation-delay: 0.1s; }
.animate-fade-up-2 { animation-delay: 0.2s; }
.animate-fade-up-3 { animation-delay: 0.3s; }

/* Pulse dot (status indicator) */
@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(0.7); }
}
```

---

## 4. Authentication Flow

### OwnerAccess Middleware

```
Request → Check Session → Check Header Password → Check WA Number → Local Bypass → Redirect/403
```

**Priority Order:**
1. **Session Auth** — `session('panel_auth')` exists
2. **Header Password** — `X-Panel-Password` header or `?password=` query
3. **WhatsApp Bypass** — `X-WA-Sender` header with registered number
4. **Local Environment** — `App::environment('local')`
5. **Fallback** — Redirect to login (web) or 401 JSON (API)

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `PANEL_NAME` | `Hermes Panel` | Panel display name |
| `PANEL_USERNAME` | `admin` | Login username |
| `PANEL_PASSWORD` | *(required)* | Login password |
| `PANEL_SESSION_LIFETIME` | `120` | Session lifetime (minutes) |
| `PANEL_OWNER_NUMBERS` | `""` | WhatsApp numbers (comma-separated, with country code) |
| `PANEL_PROJECTS_DIR` | `Project` | Directory containing managed projects |
| `PANEL_MAX_UPLOAD_SIZE` | `10485760` | Max file upload size (bytes) |

---

## 5. Route Structure

### Public Routes (no middleware)
```
GET  /panel/login         → Login page
POST /panel/login         → Authenticate
POST /panel/logout        → Logout
```

### Protected Routes (OwnerAccess middleware)
```
GET  /panel/dashboard     → Dashboard page
GET  /panel/database      → Database manager
GET  /panel/files         → File manager
GET  /panel/tools         → Laravel tools
GET  /panel/projects      → Project management
```

### API Routes (AJAX)
```
Dashboard:
  POST /api/quick/cache-clear
  GET  /api/quick/recent-logs

Database:
  GET  /api/tables
  GET  /api/tables/{table}/data
  POST /api/tables/{table}/rows (update)
  DELETE /api/tables/{table}/rows/{id}
  POST /api/query
  GET  /api/tables/{table}/export/{format}
  GET  /api/connections

Files:
  GET  /api/files
  POST /api/files/content
  POST /api/files/save
  POST /api/files/create
  POST /api/files/rename
  POST /api/files/move
  POST /api/files/copy
  POST /api/files/delete
  POST /api/files/upload
  GET  /api/files/download
  POST /api/files/permissions
  GET  /api/files/search

Tools:
  POST /api/artisan
  GET  /api/logs
  POST /api/logs/clear
  GET  /api/queue/status
  POST /api/queue/retry/{id}
  POST /api/queue/restart
  POST /api/queue/flush
  POST /api/composer
  POST /api/npm

Terminal:
  GET  /api/terminal/state
  POST /api/terminal/execute
  POST /api/terminal/reset

Projects:
  POST /api/projects/switch
  POST /api/projects/add
  POST /api/projects/hide
  POST /api/projects/unhide
  POST /api/projects/delete
  GET  /api/projects/list
```

---

## 6. State Management

### Session Variables
- `panel_auth` — Authentication flag
- `panel_auth_time` — Last auth timestamp
- `active_project` — Currently selected project name

### Project Switching
- When user switches project via sidebar dropdown → `ProjectService::switchProject()`
- Session updated → Page reload with new project context
- All services use `active_project` to scope their operations

---

## 7. Security Considerations

### Path Traversal Protection
`FileService::resolvePath()` ensures all file operations stay within project directory:
```php
$realPath = realpath($fullPath);
$realBase = realpath($basePath);
if (!$realPath || !$realBase || !str_starts_with($realPath, $realBase)) {
    return null; // Access denied
}
```

### Rate Limiting
- OwnerAccess middleware integrates with Laravel's `RateLimiter`
- Session timeout refreshes on each request

### File Upload
- Max size enforced via `PANEL_MAX_UPLOAD_SIZE` env
- Extension restrictions for editing (not uploading)

---

## 8. Implementation Patterns

### Alpine.js Component Pattern
```html
<!-- layout.blade.php -->
<body x-data="panelApp()">
    <!-- Alpine methods defined in script block -->
    <script>
        function panelApp() {
            return {
                mobileMenu: false,
            };
        }
    </script>
</body>
```

### Toast System
```html
<!-- Defined in layout, called globally -->
<script>
    window.showToast = (msg, type) => add(msg, type);
</script>

<!-- Usage in blade -->
<button @click="window.showToast('Saved!', 'success')">Save</button>
```

### API Fetch Pattern
```javascript
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
fetch('/api/action', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrf
    },
    body: JSON.stringify({ /* data */ })
})
.then(r => r.json())
.then(data => {
    if (data.success) location.reload();
    else window.showToast(data.error, 'error');
});
```

---

## 9. Docker Configuration

### Services
1. **hermes-panel** — Main PHP/Nginx container
   - Ports: 8080:8080
   - Volumes: `./Project:/var/www/html/Project`, `./storage:/var/www/html/storage`
   - Environment: `APP_ENV=production`, `APP_DEBUG=false`

2. **Optional Database Containers** (commented)
   - MySQL 8.0
   - PostgreSQL 16 Alpine

---

## 10. Key Configuration Files

### config/panel.php
```php
return [
    'name' => env('PANEL_NAME', 'Hermes Panel'),
    'username' => env('PANEL_USERNAME', 'admin'),
    'password' => env('PANEL_PASSWORD', ''),
    'session_lifetime' => (int) env('PANEL_SESSION_LIFETIME', 120),
    'owner_numbers' => array_filter(explode(',', env('PANEL_OWNER_NUMBERS', '')), ...),
    'projects_dir' => env('PANEL_PROJECTS_DIR', 'Project'),
    'editable_extensions' => ['php', 'js', 'css', 'blade.php', ...],
    'max_upload_size' => (int) env('PANEL_MAX_UPLOAD_SIZE', 10485760),
    'suggested_artisan_commands' => [...],
    'discovery_cache_ttl' => 300,
];
```

---

## 11. Development Guidelines

### Adding New Features
1. Create Controller in `app/Http/Controllers/Panel/`
2. Add route to `routes/web.php`
3. Create Blade view in `resources/views/panel/`
4. Register service if needed in `app/Services/`

### Styling Guidelines
1. Use CSS custom properties (`--ink`, `--copper`, etc.)
2. Use Tailwind utility classes where possible
3. Keep design system classes consistent
4. Follow `btn-*`, `input-*`, `card-*` patterns

### JavaScript Guidelines
1. Use Alpine.js for reactivity
2. Keep logic in blade templates where simple
3. Extract complex logic to `resources/js/`
4. Use global `showToast()` for user feedback

---

*Document generated for Claude Code agent context. All architectural decisions and design tokens are defined here.*