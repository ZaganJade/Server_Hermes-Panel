## 1. Docker & Deployment Setup

- [x] 1.1 Create `docker/Dockerfile` based on PHP 8.3 FPM Alpine with Nginx, managed by supervisord
- [x] 1.2 Install required PHP extensions in Dockerfile: pdo_mysql, pdo_pgsql, zip, pcntl, proc_open
- [x] 1.3 Create `docker/nginx.conf` for Nginx configuration (port 8080, PHP-FPM upstream)
- [x] 1.4 Create `docker-compose.yml` with hermes-panel service (volume mount Project/) and optional hermes-db service (commented out)
- [x] 1.5 Configure non-root user in Dockerfile with read/write access to Project/ volume
- [x] 1.6 Update `.env.example` with all new PANEL_* configuration variables
- [x] 1.7 Update `config/panel.php` with new settings (PANEL_USERNAME, PANEL_PASSWORD, PANEL_SESSION_LIFETIME, PANEL_PROJECTS_DIR)

## 2. Authentication System

- [x] 2.1 Create `AuthController` with `login()` (GET) and `authenticate()` (POST) methods
- [x] 2.2 Create login Blade view (`resources/views/panel/login.blade.php`) with username/password form and CSRF token
- [x] 2.3 Update `OwnerAccess` middleware: check session first, then WhatsApp header, then local bypass, then redirect to login
- [x] 2.4 Apply `owner.access` middleware to entire `Route::prefix('panel')` group in `routes/web.php`
- [x] 2.5 Add login routes: `GET /panel/login`, `POST /panel/login`, `POST /panel/logout`
- [x] 2.6 Implement rate limiting: 5 failed attempts → 60 second lockout using Laravel's built-in throttle
- [x] 2.7 Create session management: store `panel_auth` on success, `PANEL_SESSION_LIFETIME` timeout, clear on logout

## 3. UI Layout & Theme

- [x] 3.1 Create panel layout Blade template (`resources/views/panel/layout.blade.php`) with sidebar, header, and content area using Tailwind CSS classes
- [x] 3.2 Implement sidebar: logo, navigation links (Dashboard, Database, Files, Tools, Projects), project switcher dropdown, user info, logout button, theme toggle
- [x] 3.3 Implement header: breadcrumb component, active project name display
- [x] 3.4 Implement dark/light theme toggle using Alpine.js and Tailwind dark mode with `localStorage` persistence
- [x] 3.5 Implement SPA-like tab navigation: Alpine.js handles content switching without full page reload, update `window.location.hash` for back/forward support
- [x] 3.6 Implement responsive mobile layout: sidebar collapses to hamburger menu below 768px
- [x] 3.7 Extract all inline CSS from v1 layout into Tailwind utility classes and `resources/css/app.css`

## 4. Dashboard

- [x] 4.1 Create `DashboardController` with `index()` method
- [x] 4.2 Implement stat cards: table count (from DB), file count (recursive), storage used (recursive), project count
- [x] 4.3 Implement project cards grid with metadata: name, Laravel version, PHP version, status badges, last modified
- [x] 4.4 Implement quick actions: Cache Clear, View Recent Logs (5 lines), Open File Manager, Open Database Manager
- [x] 4.5 Implement activity indicator: current timestamp and panel uptime
- [x] 4.6 Create dashboard Blade view (`resources/views/panel/dashboard.blade.php`)

## 5. Project Management

- [x] 5.1 Create `ProjectService` class: auto-discovery scan of Project/ directory, Laravel detection (artisan file check), metadata extraction (.env, composer.json), caching with TTL
- [x] 5.2 Create `ProjectController` with `index()`, `switch()`, `add()`, `hide()`, `unhide()`, `delete()` methods
- [x] 5.3 Implement project cards view with status badges (.env, vendor, storage, DB connected), file count, storage used
- [x] 5.4 Implement project switching: store `active_project` in session, update all tab contexts
- [x] 5.5 Implement manual project addition: form (name + path), store in `projects.json`, display with "Manual" badge
- [x] 5.6 Implement hide project: blacklist in `projects.json`, "Hidden Projects" section for un-hiding
- [x] 5.7 Implement delete permanently: double confirmation dialog + type project name to confirm + `rm -rf`
- [x] 5.8 Implement project switcher dropdown in sidebar (Alpine.js component)
- [x] 5.9 Create project management Blade views (`resources/views/panel/projects.blade.php`)

## 6. Database Manager

- [x] 6.1 Create `DatabaseController` with `index()`, `tableData()`, `runQuery()`, `exportTable()`, `editRow()`, `deleteRow()` methods
- [x] 6.2 Create `DatabaseService` class: multi-DB connection management, read DB_* from .env, detect additional connections, dynamic connection configuration
- [x] 6.3 Implement table listing sub-tab: list tables with name, row count, size
- [x] 6.4 Implement browse data sub-tab: paginated table view (25 rows/page), sortable columns, inline edit, delete with confirmation
- [x] 6.5 Implement SQL editor sub-tab: textarea input, run button, SELECT → paginated results, INSERT/UPDATE/DELETE → affected count, DDL → confirmation dialog, error display with line highlight
- [x] 6.6 Implement query history: store last 10 queries in session, clickable to re-run
- [x] 6.7 Implement export: JSON and CSV format, auto-generated filename
- [x] 6.8 Implement connection dropdown selector for multi-DB switching
- [x] 6.9 Implement connection error handling with troubleshooting checklist
- [x] 6.10 Create database manager Blade views (`resources/views/panel/database.blade.php`)

## 7. File Manager

- [x] 7.1 Create `FileController` with `index()`, `content()`, `save()`, `create()`, `rename()`, `move()`, `copy()`, `delete()`, `upload()`, `download()`, `search()`, `permissions()` methods
- [x] 7.2 Create `FileService` class: directory browsing, file CRUD, path traversal protection, file size formatting, breadcrumbs
- [x] 7.3 Implement directory browsing: file listing with icon, name, size, modified, permissions, actions columns; folders first, sort by name/size/date
- [x] 7.4 Implement breadcrumb navigation with clickable segments
- [x] 7.5 Implement root directory context switching based on active project
- [x] 7.6 Install and integrate CodeMirror 6 for file viewing/editing with syntax highlighting (PHP, JS, CSS, HTML, JSON, SQL, YAML, MD)
- [x] 7.7 Implement create file/folder, rename (inline), move/copy (browse target), delete (with confirmation)
- [x] 7.8 Implement file upload: drag-and-drop zone, progress bar, multi-file, max size validation
- [x] 7.9 Implement download: single file direct download, folder as zip archive (PHP ZipArchive)
- [x] 7.10 Implement file search by name with subdirectory toggle
- [x] 7.11 Implement permission viewer (chmod column) and editor (numeric input)
- [x] 7.12 Create file manager Blade views (`resources/views/panel/files.blade.php`)

## 8. Built-in Web Terminal

- [x] 8.1 Install xterm.js npm dependency
- [x] 8.2 Install Laravel Reverb (WebSocket server) via Composer
- [x] 8.3 Create `TerminalController` with WebSocket connection handling
- [x] 8.4 Create `TerminalService` class: PTY process spawning via proc_open, I/O streaming through WebSocket, project directory auto-cd
- [x] 8.5 Implement terminal UI component in File Manager: toggle button, xterm.js terminal panel below file listing, close button
- [x] 8.6 Wire terminal I/O through WebSocket (Laravel Reverb): keyboard input → PTY stdin, PTY stdout → terminal display

## 9. Laravel Tools

- [x] 9.1 Create `ToolController` with `artisan()`, `logs()`, `queueStatus()`, `queueRetry()`, `composer()`, `npm()` methods
- [x] 9.2 Implement Artisan runner sub-tab: dropdown of common commands, free-text input, arguments/options fields, real-time output streaming, command history (last 10)
- [x] 9.3 Implement Log Viewer sub-tab: display last 100 lines, load more, auto-refresh toggle (5s interval), level filter (All/Error/Warning/Info/Debug), color-coded output, text search, clear log button
- [x] 9.4 Implement Queue Management sub-tab: worker status, failed jobs table (from failed_jobs), actions (restart, flush, retry per-job)
- [x] 9.5 Implement Composer & NPM sub-tab: action buttons (install, update, build, dev), project selector dropdown, real-time output streaming
- [x] 9.6 Create Laravel tools Blade views (`resources/views/panel/tools.blade.php`)

## 10. Refactoring & Cleanup

- [x] 10.1 Remove monolithic `PanelController` — replace with new modular controllers
- [x] 10.2 Extract reusable logic into service classes: `ProjectService`, `DatabaseService`, `FileService`, `TerminalService`
- [x] 10.3 Update `routes/web.php` with new route structure (login, logout, dashboard, database, files, tools, projects + API endpoints)
- [x] 10.4 Update `bootstrap/app.php` middleware registration if needed
- [x] 10.5 Remove v1 inline CSS, replace with Tailwind utility classes throughout all Blade views
- [x] 10.6 Update `vite.config.js` to include new frontend assets (xterm.js, CodeMirror CSS)
- [x] 10.7 Update `README.md` with project description, Docker setup instructions, and configuration reference
- [x] 10.8 Write basic PHPUnit tests for: auth flow, project discovery, file path traversal protection, database connection management
