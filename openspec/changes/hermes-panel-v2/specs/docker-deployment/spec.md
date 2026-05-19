## ADDED Requirements

### Requirement: Dockerfile for hermes-panel
The system SHALL include a Dockerfile based on PHP 8.3 FPM Alpine with Nginx, managed by supervisord. The Dockerfile SHALL install PHP extensions: pdo_mysql, pdo_pgsql, zip, pcntl, proc_open.

#### Scenario: Build Docker image
- **WHEN** `docker build` is executed with the Dockerfile
- **THEN** image is created with PHP 8.3 FPM, Nginx, supervisord, and all required PHP extensions installed

#### Scenario: Container starts
- **WHEN** container starts
- **THEN** both PHP-FPM and Nginx processes are running via supervisord

### Requirement: docker-compose configuration
The system SHALL include a `docker-compose.yml` with a `hermes-panel` service on port 8080. The `Project/` directory SHALL be mounted as a volume at `/var/www/html/Project`.

#### Scenario: Start services
- **WHEN** `docker-compose up -d` is executed
- **THEN** hermes-panel service starts and is accessible at port 8080, with `Project/` directory available inside the container

#### Scenario: Project files persist
- **WHEN** container is stopped and restarted
- **THEN** all files in the `Project/` volume mount persist across restarts

### Requirement: Optional database service
The system SHALL include an optional `hermes-db` service in docker-compose.yml (commented out by default) using MySQL 8 or PostgreSQL for panel's own data storage if needed.

#### Scenario: Enable panel database
- **WHEN** user uncomments the `hermes-db` service in docker-compose.yml and runs `docker-compose up -d`
- **THEN** database service starts and is accessible to the hermes-panel container via internal Docker network

### Requirement: Non-root container user
The container SHALL run as a non-root user for security. The user SHALL have read/write access to the `Project/` volume mount.

#### Scenario: Container security
- **WHEN** container is running
- **THEN** the primary process runs as a non-root user (UID > 0), and the `Project/` volume is writable by this user
