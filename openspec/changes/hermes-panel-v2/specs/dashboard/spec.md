## ADDED Requirements

### Requirement: Dashboard stat cards
The dashboard SHALL display 4 stat cards for the active project: Tables (count from database), Total Files (recursive count), Storage Used (formatted size), Projects (total detected project count).

#### Scenario: Stat cards display
- **WHEN** user views the dashboard with an active project
- **THEN** 4 stat cards display: table count from project's database, recursive file count, storage formatted (B/KB/MB/GB), and total number of detected projects

#### Scenario: No active project
- **WHEN** user views dashboard without selecting a project
- **THEN** Tables, Total Files, and Storage Used show "—" or "N/A", Projects shows total detected count

### Requirement: Project cards grid
The dashboard SHALL display a grid of cards for all detected projects. Each card SHALL show: project name (folder name), Laravel version, PHP version requirement, status badges (`.env`, `vendor/`, `storage/`, `DB connected`), and last modified timestamp. Clicking a card SHALL switch to that project.

#### Scenario: Project card display
- **WHEN** user views dashboard with 3 detected projects
- **THEN** 3 project cards are displayed, each showing name, Laravel version, PHP version, status badges, and last modified date

#### Scenario: Switch project from card
- **WHEN** user clicks on a project card
- **THEN** active project switches to the clicked project and all stat cards update accordingly

### Requirement: Quick actions
The dashboard SHALL provide quick action buttons: Cache Clear (active project), View Recent Logs (last 5 lines), Open File Manager (active project), Open Database Manager (active project).

#### Scenario: Cache clear quick action
- **WHEN** user clicks "Cache Clear" quick action
- **THEN** system runs `php artisan optimize:clear` in the active project's directory and displays success/error message

#### Scenario: View recent logs
- **WHEN** user clicks "View Recent Logs" quick action
- **THEN** system displays the last 5 lines of the active project's `storage/logs/laravel.log`

### Requirement: Activity indicator
The dashboard SHALL display current timestamp (last activity) and panel uptime (time since panel started).

#### Scenario: Activity display
- **WHEN** user views dashboard
- **THEN** current date/time is displayed along with panel uptime in human-readable format (e.g., "Up for 3 days, 2 hours")
