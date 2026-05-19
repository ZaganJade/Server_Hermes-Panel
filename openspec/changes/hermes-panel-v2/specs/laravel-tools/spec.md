## ADDED Requirements

### Requirement: Artisan command runner
The system SHALL provide an interface to run any Artisan command in the active project's directory. A dropdown of common commands SHALL be provided alongside a free-text input for manual command entry. Arguments and options SHALL be configurable. Output SHALL stream in real-time.

#### Scenario: Run command from dropdown
- **WHEN** user selects "cache:clear" from dropdown and clicks "Run"
- **THEN** system executes `php artisan cache:clear` in active project directory and displays output in real-time

#### Scenario: Run manual command with options
- **WHEN** user types "migrate --seed --force" and clicks "Run"
- **THEN** system executes the full command and streams output

#### Scenario: Command history
- **WHEN** user has executed 3 commands and clicks "History"
- **THEN** system displays the last 10 commands executed with output, each clickable to re-run

### Requirement: Log viewer
The system SHALL read and display `storage/logs/laravel.log` from the active project. Default display: last 100 lines with "Load More" button.

#### Scenario: View recent logs
- **WHEN** user navigates to Log Viewer sub-tab
- **THEN** system displays last 100 lines of the active project's Laravel log

#### Scenario: Load more logs
- **WHEN** user clicks "Load More" button
- **THEN** system displays the next 100 older lines

### Requirement: Log auto-refresh
The system SHALL provide an auto-refresh toggle that polls for new log entries every 5 seconds.

#### Scenario: Enable auto-refresh
- **WHEN** user toggles auto-refresh on
- **THEN** log viewer automatically fetches new log entries every 5 seconds and appends them to the display

#### Scenario: Disable auto-refresh
- **WHEN** user toggles auto-refresh off
- **THEN** log viewer stops polling and displays static content

### Requirement: Log level filtering
The system SHALL allow filtering log entries by level: All, Error, Warning, Info, Debug. Log lines SHALL be color-coded: Error = red, Warning = yellow, Info = blue, Debug = gray.

#### Scenario: Filter by Error level
- **WHEN** user selects "Error" filter
- **THEN** only log entries with level "error" are displayed, highlighted in red

### Requirement: Log text search
The system SHALL provide a search input to filter log entries by text content.

#### Scenario: Search within logs
- **WHEN** user types "SQLSTATE" in the search box
- **THEN** only log lines containing "SQLSTATE" are displayed

### Requirement: Clear log file
The system SHALL provide a "Clear Log" button that empties the log file after confirmation.

#### Scenario: Clear log
- **WHEN** user clicks "Clear Log" and confirms the dialog
- **THEN** system empties `storage/logs/laravel.log` and displays empty log view

### Requirement: Queue management
The system SHALL display queue worker status (running/stopped) and a list of failed jobs from the `failed_jobs` table. Actions: Queue Restart, Queue Flush, Retry Failed Job (per-item).

#### Scenario: View failed jobs
- **WHEN** user navigates to Queue Management sub-tab
- **THEN** system displays queue worker status and a table of failed jobs with columns: job name, queue name, failed_at, exception message

#### Scenario: Retry failed job
- **WHEN** user clicks "Retry" on a failed job
- **THEN** system deletes the job from `failed_jobs` table and re-dispatches it to the queue

#### Scenario: Queue restart
- **WHEN** user clicks "Queue Restart"
- **THEN** system runs `php artisan queue:restart` in the active project

### Requirement: Composer and NPM runner
The system SHALL provide quick action buttons for Composer (`install`, `update`, `dump-autoload`) and NPM (`install`, `run build`, `run dev`). A project selector dropdown SHALL allow running commands in a different project without switching the active project. Output SHALL stream in real-time.

#### Scenario: Run composer install
- **WHEN** user clicks "Composer Install" button
- **THEN** system executes `composer install` in the active project and streams output in real-time

#### Scenario: Run npm build in different project
- **WHEN** user selects "project-2" from project dropdown and clicks "NPM Build"
- **THEN** system executes `npm run build` in the "project-2" directory, not the active project

#### Scenario: Command output streaming
- **WHEN** any Composer or NPM command is running
- **THEN** output is displayed line-by-line in real-time as the command executes
