## Why

Hermes Panel v1 is an initial commit with a monolithic controller, no proper authentication, no Docker support, and limited file/DB management. A full rewrite is needed to transform it into a production-ready cPanel-like administration panel for managing multiple Laravel projects on a VPS.

## What Changes

- **BREAKING**: Complete rewrite of the panel from monolithic single-controller architecture to modular, service-layer-based architecture
- **BREAKING**: New authentication system with login form (password + WhatsApp number + local bypass)
- **BREAKING**: New deployment via Docker (docker-compose) instead of PM2 + `php artisan serve`
- New dark/light theme toggle (default dark)
- New auto-discovery of Laravel projects from a designated `Project/` directory
- New full-featured file manager with browse, edit, rename, move/copy, upload, download (zip), search, chmod, and built-in web terminal (xterm.js)
- New database manager with table browsing, data editing, full SQL editor with history, multi-DB connection support, and export (JSON/CSV)
- New Laravel tools with artisan command runner, log viewer (filtered + auto-refresh), queue management, and Composer/NPM runner
- New project management with auto-discovery, manual addition, hide/delete, and project switching
- New responsive SPA-like tab navigation (Alpine.js)
- New dashboard with stat cards, project overview grid, and quick actions

## Capabilities

### New Capabilities
- `auth-system`: Password login form, WhatsApp number verification, local bypass, session management, rate limiting
- `docker-deployment`: Dockerfile, docker-compose.yml, volume mounts for Project/ directory, PHP extensions for terminal/DB
- `ui-layout`: Dark/light theme toggle, sidebar navigation, tab-based SPA-like routing, responsive mobile layout, header with breadcrumb
- `dashboard`: Stat cards (tables, files, storage, projects), project cards grid, quick actions, activity indicator
- `database-manager`: Table listing, data browsing with pagination/sort, inline editing, full SQL editor with history, multi-DB connection, export JSON/CSV
- `file-manager`: Browse, view/edit with syntax highlighting, create/rename/move/copy/delete, upload with drag-drop, download as zip, search, chmod viewer, built-in web terminal (xterm.js)
- `laravel-tools`: Artisan command runner, log viewer with filters and auto-refresh, queue management (status, failed jobs, retry), Composer/NPM runner
- `project-management`: Auto-discovery from Project/ directory, manual project addition, project switching, hide/delete options, status badges

### Modified Capabilities
<!-- No existing specs to modify -->

## Impact

- **Code**: Full rewrite — new controllers, services, middleware, Blade templates, JavaScript modules
- **Routes**: New route structure with login/logout, dashboard, database, files, tools, projects
- **Dependencies (PHP)**: No new Laravel packages required. Docker adds PHP extensions: pdo_mysql, pdo_pgsql, zip, pcntl, proc_open
- **Dependencies (JS)**: New frontend dependencies: xterm.js (terminal), CodeMirror 6 or Monaco (code editor)
- **Configuration**: New `.env` variables: PANEL_USERNAME, PANEL_PASSWORD, PANEL_SESSION_LIFETIME, PANEL_PROJECTS_DIR
- **Deployment**: Migration from PM2 to Docker (docker-compose)
- **Storage**: `projects.json` for project metadata cache and blacklists
- **Security**: OwnerAccess middleware applied to all panel routes, CSRF protection, rate limiting on login
