## ADDED Requirements

### Requirement: Table listing
The Database Manager SHALL list all tables in the active project's database. Each table SHALL display: name, approximate row count, and size.

#### Scenario: View table list
- **WHEN** user navigates to Database tab with an active project connected to MySQL
- **THEN** system displays a list of all tables with name, row count, and size for each table

#### Scenario: No database connection
- **WHEN** user navigates to Database tab with no DB configured for active project
- **THEN** system displays "No database configured" message with setup instructions

### Requirement: Browse data with pagination
The system SHALL allow browsing table data in a paginated view (default 25 rows per page). Columns SHALL be sortable by clicking headers (asc/desc toggle).

#### Scenario: Browse table data
- **WHEN** user clicks on a table in the table list
- **THEN** system displays first 25 rows of that table with all columns, sortable by clicking column headers

#### Scenario: Pagination
- **WHEN** table has more than 25 rows and user clicks "Next" page
- **THEN** system displays rows 26-50

### Requirement: Inline row editing
The system SHALL allow editing individual rows inline. User clicks "Edit" on a row, fields become editable, and "Save" commits changes.

#### Scenario: Edit a row
- **WHEN** user clicks "Edit" on a row in Browse Data view, modifies a field, and clicks "Save"
- **THEN** system updates the row in the database and refreshes the view with the updated data

### Requirement: Row deletion with confirmation
The system SHALL allow deleting rows with a confirmation dialog.

#### Scenario: Delete a row
- **WHEN** user clicks "Delete" on a row and confirms the dialog
- **THEN** system deletes the row from the database and refreshes the view

### Requirement: Full SQL editor
The system SHALL provide a textarea-based SQL editor where users can write and execute any SQL query. SELECT queries SHALL display results in a paginated table. INSERT/UPDATE/DELETE SHALL display affected row count. DDL queries (CREATE, ALTER, DROP) SHALL require confirmation before execution.

#### Scenario: Execute SELECT query
- **WHEN** user writes `SELECT * FROM users WHERE active = 1` and clicks "Run"
- **THEN** system displays results in a paginated table

#### Scenario: Execute DDL with confirmation
- **WHEN** user writes `DROP TABLE old_logs` and clicks "Run"
- **THEN** system shows confirmation dialog "This will modify database structure. Continue?", and on confirm executes the query

#### Scenario: Query error
- **WHEN** user writes invalid SQL and clicks "Run"
- **THEN** system displays the database error message with indication of syntax error location

### Requirement: Query history
The system SHALL maintain a history of the last 10 SQL queries executed in the current session. Each history entry SHALL be clickable to re-run the query.

#### Scenario: View query history
- **WHEN** user has executed 3 queries and clicks on query history
- **THEN** system displays the last 3 queries in reverse chronological order, each clickable to populate the editor

### Requirement: Table export
The system SHALL allow exporting table data to JSON or CSV format. Filename SHALL be auto-generated as `{table}_{YYYYMMDD_HHmmss}.{format}`.

#### Scenario: Export as JSON
- **WHEN** user selects a table, chooses JSON format, and clicks "Export"
- **THEN** system downloads a JSON file with all table data and metadata (table name, export timestamp, row count)

#### Scenario: Export as CSV
- **WHEN** user selects a table, chooses CSV format, and clicks "Export"
- **THEN** system downloads a CSV file with headers from column names and all row data

### Requirement: Multiple database connections
The system SHALL support multiple database connections per project. It SHALL read the primary connection from standard `DB_*` env vars and detect additional connections via naming convention `DB_CONNECTION_SECONDARY`, `DB_HOST_SECONDARY`, `DB_DATABASE_SECONDARY`, etc. A dropdown selector SHALL allow switching between connections.

#### Scenario: Switch database connection
- **WHEN** user selects "Secondary" from the connection dropdown
- **THEN** table list refreshes to show tables from the secondary database

#### Scenario: Project with single database
- **WHEN** project has only standard `DB_*` env vars configured
- **THEN** connection dropdown shows only the primary connection

### Requirement: Connection error handling
The system SHALL display a user-friendly error message when database connection fails, including a checklist of common issues (check .env, check service running, check credentials).

#### Scenario: Connection failure
- **WHEN** database connection fails (wrong credentials, service down, etc.)
- **THEN** system displays error message with troubleshooting checklist
