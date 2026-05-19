## ADDED Requirements

### Requirement: Project auto-discovery
The system SHALL scan the `Project/` directory and detect Laravel projects. A folder SHALL be recognized as a Laravel project if it contains an `artisan` file. Folders without `artisan` SHALL be displayed as "Generic Project". Scan results SHALL be cached with configurable TTL (default 5 minutes).

#### Scenario: Detect Laravel projects
- **WHEN** `Project/` directory contains folders `desakta/` (with `artisan`), `api/` (with `artisan`), and `assets/` (no `artisan`)
- **THEN** system identifies `desakta` and `api` as Laravel projects, and `assets` as a Generic Project

#### Scenario: Cache expiry
- **WHEN** a new project folder is added to `Project/` and the cache TTL has expired
- **THEN** next page load detects and includes the new project

### Requirement: Project metadata
For each detected Laravel project, the system SHALL read and display: app name (from `.env` `APP_NAME`), Laravel version (from `composer.json` `require.laravel/framework`), PHP version requirement (from `composer.json` `require.php`), and database configuration (from `.env` `DB_*` vars).

#### Scenario: Read project metadata
- **WHEN** project `desakta/` has `APP_NAME=DESAKTA` in `.env` and `"laravel/framework": "^11.0"` in `composer.json`
- **THEN** project card shows name "DESAKTA" and Laravel version "^11.0"

### Requirement: Project cards display
The Projects tab SHALL display a grid of cards for all detected and manually added projects. Each card SHALL show: name, status badges (`.env`, `vendor/`, `storage/`, `DB connected`), Laravel version, PHP version, file count, storage used, last modified timestamp, and action buttons (Open, Hide, Delete Permanently).

#### Scenario: View project cards
- **WHEN** user navigates to Projects tab
- **THEN** all detected and manual projects are displayed as cards with full metadata and status badges

### Requirement: Manual project addition
The system SHALL allow adding projects outside the `Project/` directory via manual input (name + absolute path). Manual projects SHALL be stored in `projects.json` and appear alongside auto-discovered projects with a "Manual" badge.

#### Scenario: Add manual project
- **WHEN** user enters name "legacy-app" and path "/var/www/legacy-app" and clicks "Add"
- **THEN** project appears in the project list with "Manual" badge and is accessible via File Manager and tools

### Requirement: Project switching
The system SHALL allow switching the active project by clicking "Open" on a project card or selecting from the sidebar dropdown. The active project SHALL be stored in session (`active_project`). Switching SHALL update context for Dashboard, Database, Files, and Tools tabs.

#### Scenario: Switch active project
- **WHEN** user clicks "Open" on the "project-2" card
- **THEN** session stores `active_project` as "project-2", Dashboard stats update, Database Manager connects to project-2's DB, File Manager root changes to project-2's folder, Tools execute in project-2's context

### Requirement: Hide project
The system SHALL allow hiding a project from the panel view. Hidden projects SHALL be stored in `projects.json` blacklist. Hidden projects SHALL remain on disk. A "Hidden Projects" section SHALL allow un-hiding.

#### Scenario: Hide project
- **WHEN** user clicks "Hide" on project "old-project" card
- **THEN** project no longer appears in main project list but appears in "Hidden Projects" section

#### Scenario: Un-hide project
- **WHEN** user clicks "Un-hide" on a hidden project
- **THEN** project reappears in the main project list

### Requirement: Delete project permanently
The system SHALL allow permanently deleting a project (recursive folder deletion). This SHALL require: (1) double confirmation dialog, (2) typing the project name to confirm. The action SHALL execute `rm -rf` on the project folder.

#### Scenario: Delete with name confirmation
- **WHEN** user clicks "Delete Permanently", confirms first dialog, and types exact project name in second dialog
- **THEN** project folder and all contents are permanently deleted, project removed from all lists

#### Scenario: Delete with wrong name
- **WHEN** user clicks "Delete Permanently", confirms first dialog, but types incorrect project name
- **THEN** deletion is cancelled and error message "Project name does not match" is displayed

### Requirement: Project status badges
Each project card SHALL display status badges: `.env` exists ✓/✗, `vendor/` installed ✓/✗, `storage/` linked ✓/✗, `DB connected` ✓/✗. Badge check for DB SHALL attempt a connection test.

#### Scenario: Status badges
- **WHEN** project has `.env`, `vendor/`, `storage/` but database credentials are wrong
- **THEN** badges show ✓ for `.env`, `vendor/`, `storage/` and ✗ for `DB connected`
