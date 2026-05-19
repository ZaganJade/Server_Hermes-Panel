# Hermes Panel

A cPanel-like server administration panel for managing multiple Laravel projects on a VPS. Built with Laravel 13, Alpine.js, and Tailwind CSS.

## Features

- **Authentication**: Password login + WhatsApp number header bypass + CSRF bypass for API routes
- **Dashboard**: System stats, quick actions, project overview cards
- **Project Management**: Auto-discovery of Laravel projects, manual add, hide/delete, default project selection
- **Database Manager**: Multi-DB connection support, SQL editor, browse data, export (JSON/CSV), **inline cell edit**, **soft-delete trash tab** with restore/force-delete/empty-trash
- **File Manager**: Browse, edit, upload, download (zip), search, chmod, built-in terminal
- **Laravel Tools**: Artisan runner, log viewer, queue monitor, Composer & NPM commands, **seeder runner**
- **Terminal**: Full SSH-like web terminal via xterm.js + WebSocket (Reverb)
- **Dark/Light Theme**: Toggle between dark (default) and light modes, persisted in localStorage

## Requirements

- PHP 8.3+
- Composer (for Laravel dependencies)
- Docker & Docker Compose
- Node.js 20+ (for asset building)

## Docker Setup

1. Clone the repository and configure environment:

```bash
cp .env.example .env
# Edit .env with your settings (PANEL_USERNAME, PANEL_PASSWORD, etc.)
```

2. Build and start:

```bash
docker compose up -d --build
```

3. Access the panel at `http://your-vps-ip:8000`

## Configuration

| Variable | Default | Description |
|---|---|---|
| `PANEL_NAME` | `Hermes Panel` | Panel display name |
| `PANEL_USERNAME` | `admin` | Login username |
| `PANEL_PASSWORD` | *(required)* | Login password |
| `PANEL_SESSION_LIFETIME` | `120` | Session lifetime (minutes) |
| `PANEL_OWNER_NUMBERS` | `""` | WhatsApp numbers (comma-separated, with country code) |
| `PANEL_PROJECTS_DIR` | `Project` | Directory containing managed projects |
| `PANEL_DEFAULT_PROJECT` | — | Auto-select project on login (e.g. `desakta`) |
| `PANEL_MAX_UPLOAD_SIZE` | `10485760` | Max file upload size (bytes, default 10MB) |

## Architecture

```
app/
├── Http/
│   ├── Controllers/Panel/
│   │   ├── AuthController.php
│   │   ├── DashboardController.php
│   │   ├── DatabaseController.php   (inline edit, trash support)
│   │   ├── FileController.php
│   │   ├── ProjectController.php
│   │   ├── TerminalController.php
│   │   └── ToolController.php       (seeder support)
│   └── Middleware/
│       ├── OwnerAccess.php
│       └── PanelApiCsrf.php         (NEW — CSRF bypass for panel/api)
├── Jobs/
│   └── CleanupDatabaseTrash.php     (NEW — scheduled monthly cleanup)
├── Services/
│   ├── DatabaseService.php          (inline edit, soft-delete detection)
│   ├── FileService.php
│   └── ProjectService.php
├── config/
│   └── panel.php
├── resources/
│   ├── views/panel/
│   │   ├── layout.blade.php
│   │   ├── login.blade.php
│   │   ├── dashboard.blade.php
│   │   ├── projects.blade.php
│   │   ├── database.blade.php
│   │   ├── files.blade.php
│   │   └── tools.blade.php
│   ├── css/app.css
│   └── js/app.js
└── docker/
    ├── Dockerfile
    ├── nginx.conf
    ├── php-fpm.conf
    └── supervisord.conf
```

## Scheduled Jobs

| Job | Schedule | Description |
|---|---|---|
| `CleanupDatabaseTrash` | Monthly | Automatically cleans soft-deleted database rows older than 30 days across all managed projects |

## License

Proprietary. All rights reserved.
