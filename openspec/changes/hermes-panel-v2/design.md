## Context

Hermes Panel is a web-based server administration panel for managing Laravel projects on a VPS. The current v1 is an initial commit with a single monolithic `PanelController` (~400 lines), no proper login form (auth via header/query params only), no Docker support, limited file manager (no rename/move/terminal), basic database browser (no SQL editor, no multi-DB), and inline CSS in Blade templates.

The panel runs on a Linux VPS at `/home/ZaganJade1/hermes-panel/`. All managed projects reside in `Project/` subdirectory. Target deployment is Docker via docker-compose.

Tech stack: Laravel 13 (PHP 8.3), Alpine.js 3, Tailwind CSS 4, Vite 8. Single owner usage — no multi-user requirements.

## Goals / Non-Goals

**Goals:**
- Transform into a cPanel-like administration panel with proper auth, modular architecture, and full-featured tools
- Docker-based deployment with volume-mounted project directory
- Auto-discover Laravel projects from a designated directory
- Full database management with multi-connection support and SQL editor
- Full-featured file manager with built-in web terminal
- Dark/light theme with responsive SPA-like navigation

**Non-Goals:**
- Multi-user/role management
- Server-level monitoring (CPU, RAM, network)
- Domain/SSL management
- Git integration from UI
- Backup/restore snapshots

## Decisions

### D1: Modular controller architecture (vs single PanelController)

**Choice:** Split into dedicated controllers per module: `AuthController`, `DashboardController`, `DatabaseController`, `FileController`, `ToolController`, `ProjectController`. Each uses service classes for business logic.

**Rationale:** The current monolithic PanelController mixes concerns from 5 different modules. Modular controllers are independently testable, readable, and follow Laravel conventions. Service classes extract reusable logic (file operations, project discovery, DB connection management).

**Alternatives considered:**
- Single controller with traits — still coupled, harder to test
- Invokable controllers per action — too granular, 20+ files

### D2: SPA-like navigation via Alpine.js (vs full page reload vs Livewire)

**Choice:** Server-side rendered Blade pages with Alpine.js for tab switching. Routes exist server-side but navigation feels like SPA (sidebar clicks update content area without full reload).

**Rationale:** Keeps Laravel's rendering simplicity while providing smooth UX. No need for Livewire's complexity (websockets, server-roundtrips) since most interactions are AJAX calls to existing API endpoints. Alpine.js is already a dependency and is lightweight.

**Alternatives considered:**
- Full page reload — jarring UX, flicker on every navigation
- Livewire — overkill for single-user panel, adds dependency and complexity
- Full SPA (React/Vue) — massive over-engineering for a Blade-based admin panel

### D3: xterm.js for web terminal (vs no terminal vs WebSocket shell)

**Choice:** xterm.js library for web-based terminal emulator. Backend spawns a PTY process via `proc_open`/`pcntl` in PHP, streams I/O through WebSocket (Laravel Reverb or native WebSocket server).

**Rationale:** xterm.js is the industry standard (used by VS Code, GitHub Codespaces). Provides full terminal experience with proper escape sequence handling, copy/paste, scrollback. Docker isolation means no host security risk from full shell access.

**Alternatives considered:**
- No terminal — user explicitly requested full SSH-like access
- Server-Sent Events for output only — no interactive input, can't use `vim`, `top`, etc.
- AJAX polling — latency, no real-time feel, breaks interactive commands

### D4: CodeMirror 6 for code editor (vs Monaco vs textarea)

**Choice:** CodeMirror 6 for the file editor component.

**Rationale:** Lightweight (~50KB gzipped vs Monaco's ~2MB), supports syntax highlighting for all needed languages (PHP, JS, CSS, JSON, SQL, YAML, MD), extensible, good mobile support. Monaco is overkill for simple file editing.

**Alternatives considered:**
- Monaco Editor — too heavy (~2MB), designed for full IDE not inline editing
- Plain textarea — no syntax highlighting, poor UX for code editing
- Ace Editor — legacy, larger than CodeMirror 6

### D5: Docker single container with PHP-FPM + Nginx (vs separate containers)

**Choice:** Single container running PHP-FPM with Nginx via supervisord, plus optional separate `hermes-db` container.

**Rationale:** Simpler docker-compose for single-user panel. Supervisord manages both PHP-FPM and Nginx processes. The `Project/` directory is volume-mounted. Optional `hermes-db` for panel's own session storage if needed, though file-based sessions are sufficient.

**Alternatives considered:**
- Separate PHP-FPM + Nginx containers — more complex networking, no real benefit for single app
- Apache + mod_php — heavier than Nginx + FPM

### D6: File-based project metadata (projects.json) (vs database table)

**Choice:** Store project metadata, blacklists, and manual project entries in `projects.json` at the panel root.

**Rationale:** Avoids needing a separate database for the panel itself. Auto-discovery data is ephemeral (regenerated on scan). Simple JSON read/write is sufficient. If the panel eventually needs a database (for audit logs, etc.), it can be added later.

**Alternatives considered:**
- SQLite database — adds migration management overhead for simple key-value data
- Config file (PHP) — harder to read/write dynamically at runtime

### D7: WebSocket via Laravel Reverb for terminal (vs raw WebSocket server)

**Choice:** Use Laravel Reverb (Laravel's official WebSocket server) for terminal I/O streaming.

**Rationale:** First-party Laravel package, integrates with Laravel's authentication and middleware, no external service dependency (Redis/Pusher). Single-user panel has minimal WebSocket load.

**Alternatives considered:**
- Custom WebSocket server (Ratchet/ReactPHP) — more maintenance burden
- Raw PHP WebSocket — reinventing the wheel
- No WebSocket (AJAX polling) — poor terminal experience

## Risks / Trade-offs

**[Risk] Terminal security** → Full shell access inside Docker container. Mitigation: Container runs as non-root user, no host volume mounts except `Project/`, no `--privileged` flag.

**[Risk] Large project directories slow to scan** → Recursive file count and storage calculation on every dashboard load. Mitigation: Cache project metadata in `projects.json` with configurable TTL (default 5 minutes), lazy-load detailed stats.

**[Risk] CodeMirror/xterm.js bundle size** → New JS dependencies increase page weight. Mitigation: Lazy-load editor and terminal only when tab is opened, use Vite code splitting.

**[Risk] SPA-like navigation breaks browser back/forward** → Alpine.js tab switching doesn't update URL. Mitigation: Update `window.location.hash` on tab switch, listen for `hashchange` events to restore state.

**[Trade-off] No multi-user support** → Limits panel to single owner. Acceptable for personal VPS admin tool. Can be added later without major refactoring (just add users table and auth middleware).

**[Trade-off] Docker adds deployment complexity** → User needs to know Docker basics. Mitigation: Provide clear `docker-compose.yml` and setup script in README.
