## ADDED Requirements

### Requirement: Dark and light theme
The system SHALL support dark theme (default) and light theme. A toggle switch SHALL be provided in the sidebar footer to switch between themes. Theme preference SHALL be stored in browser `localStorage`.

#### Scenario: Switch to light theme
- **WHEN** user clicks the theme toggle in sidebar footer while in dark mode
- **THEN** UI switches to light theme and preference is saved to `localStorage`

#### Scenario: Persist theme preference
- **WHEN** user revisits the panel after previously selecting light theme
- **THEN** panel loads in light theme (read from `localStorage`)

### Requirement: Sidebar navigation
The system SHALL display a fixed sidebar with navigation links: Dashboard, Database, Files, Tools, Projects. The active tab SHALL be visually highlighted. A project switcher dropdown SHALL be displayed at the bottom of the sidebar.

#### Scenario: Navigate between tabs
- **WHEN** user clicks "Database" in sidebar
- **THEN** content area updates to show Database Manager without full page reload, and "Database" is highlighted in sidebar

#### Scenario: Project switcher
- **WHEN** user selects a different project from the sidebar dropdown
- **THEN** all tab content (Dashboard, Database, Files, Tools) switches context to the selected project

### Requirement: Header with breadcrumb
The system SHALL display a header bar above the content area with breadcrumb navigation (especially for File Manager) and the active project name.

#### Scenario: Breadcrumb in File Manager
- **WHEN** user navigates to `Project/desakta/app/Http` in File Manager
- **THEN** header displays breadcrumb: "root > desakta > app > Http" with each segment clickable

### Requirement: SPA-like tab navigation
The system SHALL implement tab-based navigation using Alpine.js. Clicking sidebar items SHALL update the content area without full page reload. Routes SHALL remain server-side rendered via Laravel Blade.

#### Scenario: Tab switching without reload
- **WHEN** user clicks "Tools" sidebar link
- **THEN** only the content area updates (no full page reload, no layout flash)

#### Scenario: Browser back/forward
- **WHEN** user clicks browser back button after switching from Dashboard to Database
- **THEN** panel returns to Dashboard tab

### Requirement: Responsive mobile layout
The system SHALL collapse the sidebar into a hamburger menu on screens narrower than 768px. Content area SHALL be full-width when sidebar is collapsed.

#### Scenario: Mobile view
- **WHEN** panel is viewed on a screen narrower than 768px
- **THEN** sidebar is hidden by default, a hamburger button is visible, clicking it toggles the sidebar as an overlay
