## ADDED Requirements

### Requirement: Directory browsing
The File Manager SHALL display the contents of a directory with columns: icon, name, size, modified date, permissions, and actions. Folders SHALL always appear above files. Files SHALL be sortable by name, size, or date.

#### Scenario: Browse directory
- **WHEN** user navigates to Files tab
- **THEN** system displays contents of the current directory with folders listed first, sorted alphabetically, followed by files sorted alphabetically

#### Scenario: Enter subfolder
- **WHEN** user clicks on a folder
- **THEN** file listing updates to show the contents of that folder and breadcrumb updates

### Requirement: Breadcrumb navigation
The File Manager SHALL display a breadcrumb path at the top. Each segment SHALL be clickable to navigate to that directory level.

#### Scenario: Navigate via breadcrumb
- **WHEN** user clicks "desakta" in breadcrumb "root > desakta > app > Http"
- **THEN** file listing updates to show contents of the `desakta` directory

### Requirement: Root directory context
When a project is selected, the File Manager root SHALL be the project's folder. When no project is selected, the root SHALL be the `Project/` directory.

#### Scenario: With active project
- **WHEN** project "desakta" is active and user opens Files tab
- **THEN** file manager root is `Project/desakta/`

#### Scenario: Without active project
- **WHEN** no project is active and user opens Files tab
- **THEN** file manager root is `Project/` directory

### Requirement: File view and edit
The system SHALL open files in a code editor with syntax highlighting for supported languages (PHP, JS, CSS, HTML, JSON, SQL, YAML, MD, env). Files not in `editable_extensions` SHALL be view-only.

#### Scenario: Edit editable file
- **WHEN** user clicks on a `.php` file
- **THEN** code editor opens with syntax highlighting, editable, with Save and Cancel buttons

#### Scenario: View non-editable file
- **WHEN** user clicks on a `.png` file
- **THEN** code editor opens in read-only mode with "This file type is not editable" notice

### Requirement: Create file and folder
The system SHALL allow creating new empty files and folders in the current directory via toolbar buttons.

#### Scenario: Create file
- **WHEN** user clicks "New File", enters filename "test.php", and confirms
- **THEN** empty `test.php` file is created in current directory and listing refreshes

#### Scenario: Create folder
- **WHEN** user clicks "New Folder", enters name "uploads", and confirms
- **THEN** `uploads/` directory is created in current directory and listing refreshes

### Requirement: Rename file or folder
The system SHALL allow inline renaming of files and folders. Pressing Enter confirms, Escape cancels.

#### Scenario: Rename file
- **WHEN** user clicks rename on "old-name.php", types "new-name.php", and presses Enter
- **THEN** file is renamed to `new-name.php` and listing refreshes

### Requirement: Move and copy
The system SHALL allow selecting a file/folder, choosing Move or Copy, browsing to a target directory, and executing the operation.

#### Scenario: Move file
- **WHEN** user selects "config.php", clicks "Move", navigates to "backup/" folder, and confirms
- **THEN** `config.php` is moved to `backup/config.php` and removed from original location

#### Scenario: Copy file
- **WHEN** user selects "config.php", clicks "Copy", navigates to "backup/" folder, and confirms
- **THEN** `config.php` is copied to `backup/config.php` and original remains

### Requirement: Delete with confirmation
The system SHALL delete files and folders with a confirmation dialog. Folder deletion SHALL be recursive.

#### Scenario: Delete file
- **WHEN** user clicks delete on "temp.txt" and confirms
- **THEN** file is permanently deleted and listing refreshes

#### Scenario: Delete folder
- **WHEN** user clicks delete on "old-backup/" folder and confirms
- **THEN** folder and all its contents are recursively deleted

### Requirement: File upload
The system SHALL support file upload via drag-and-drop or file picker. Progress bar SHALL be shown during upload. Multiple files SHALL be uploadable simultaneously. Max file size SHALL be configurable via `PANEL_MAX_UPLOAD_SIZE` (default 10MB).

#### Scenario: Upload single file
- **WHEN** user drops a file into the upload zone
- **THEN** file is uploaded to the current directory with progress bar shown, listing refreshes on completion

#### Scenario: Upload exceeds size limit
- **WHEN** user uploads a file larger than `PANEL_MAX_UPLOAD_SIZE`
- **THEN** system displays error "File exceeds maximum upload size (10MB)"

### Requirement: Download file and folder as zip
The system SHALL allow downloading single files directly, and downloading folders as zip archives.

#### Scenario: Download single file
- **WHEN** user clicks download on "readme.md"
- **THEN** browser downloads `readme.md` file

#### Scenario: Download folder as zip
- **WHEN** user clicks download on "assets/" folder
- **THEN** browser downloads `assets.zip` containing all folder contents

### Requirement: File search
The system SHALL provide a search bar to search files by name in the current directory, with an option to include subdirectories.

#### Scenario: Search in current directory
- **WHEN** user types "config" in search bar with subdirectories disabled
- **THEN** system displays all files/folders matching "config" in the current directory

#### Scenario: Search with subdirectories
- **WHEN** user types "config" in search bar with "Include subdirectories" enabled
- **THEN** system displays all files/folders matching "config" in current directory and all subdirectories

### Requirement: Permission viewer and editor
The system SHALL display file permissions (chmod) in the file listing. Users SHALL be able to edit permissions via numeric input (e.g., 755, 644).

#### Scenario: View permissions
- **WHEN** user views file listing
- **THEN** permissions column shows numeric chmod value (e.g., "644") for each file/folder

#### Scenario: Edit permissions
- **WHEN** user clicks on permissions "644", enters "755", and confirms
- **THEN** file permissions are changed to 755 and listing refreshes

### Requirement: Path traversal protection
The system SHALL prevent path traversal attacks using `realpath()` validation. File operations SHALL only be allowed within the allowed base directory.

#### Scenario: Path traversal attempt
- **WHEN** user attempts to access `../../../etc/passwd` via file manager
- **THEN** system rejects the request and displays "Access denied"

### Requirement: Built-in web terminal
The system SHALL provide a web-based terminal (xterm.js) accessible from the File Manager toolbar. The terminal SHALL auto-cd into the active project folder and provide full shell access with no command restrictions.

#### Scenario: Open terminal
- **WHEN** user clicks "Terminal" button in File Manager toolbar
- **THEN** terminal panel opens below the file listing, auto-cd'd into the active project directory

#### Scenario: Execute command in terminal
- **WHEN** user types `ls -la` and presses Enter in the terminal
- **THEN** terminal displays the directory listing output in real-time

#### Scenario: Terminal isolation
- **WHEN** terminal is running inside Docker container
- **THEN** commands are limited to the container's filesystem (no host access)
