## ADDED Requirements

### Requirement: Authentication enforcement and trusted-network bypass
The system SHALL enforce authentication by default. Authentication is gated by two boolean configuration flags: `PANEL_AUTH_ENABLED` (mapped to `config('panel.auth_enabled')`, default `true`) and `PANEL_DEV_BYPASS` (mapped to `config('panel.dev_bypass')`, default `false`). The middleware short-circuits to a pass-through ONLY when BOTH flags resolve to the bypass combination (`auth_enabled=false` AND `dev_bypass=true`). In production, `AppServiceProvider` SHALL refuse to boot if `auth_enabled=false` without `dev_bypass=true` — throwing a `RuntimeException` rather than silently serving traffic without auth.

#### Scenario: Auth enabled by default
- **WHEN** `PANEL_AUTH_ENABLED` is unset (default `true`) and any panel route is requested
- **THEN** the `OwnerAccess` middleware evaluates session, header password, and WhatsApp sender in priority order

#### Scenario: Trusted-network bypass requires both flags
- **WHEN** `PANEL_AUTH_ENABLED=false` AND `PANEL_DEV_BYPASS=true`
- **THEN** the `OwnerAccess` middleware short-circuits and grants access without any credential check

#### Scenario: Auth disabled without dev bypass refuses to boot
- **WHEN** `APP_ENV=production`, `PANEL_AUTH_ENABLED=false`, and `PANEL_DEV_BYPASS=false`
- **THEN** `AppServiceProvider::boot()` throws a `RuntimeException` and the panel cannot serve any request

#### Scenario: Login screen reflects the flag combination
- **WHEN** auth is in bypass mode (both flags satisfy the bypass combination) and user visits `/panel/login`
- **THEN** the system redirects to `/panel/dashboard` instead of rendering the login form

### Requirement: Password login form
When auth is enforced, the system SHALL provide a login page at `/panel/login` with username and password fields. Credentials SHALL be configured via `PANEL_USERNAME` and `PANEL_PASSWORD` environment variables. On successful authentication, the system SHALL store the session and redirect to `/panel/dashboard`.

#### Scenario: Successful login
- **WHEN** auth is enforced and user submits correct `PANEL_USERNAME` and `PANEL_PASSWORD`
- **THEN** system creates a session with key `panel_auth`, stamps `panel_auth_time`, and redirects to the originally requested route or `/panel/dashboard`

#### Scenario: Failed login
- **WHEN** auth is enforced and user submits incorrect credentials
- **THEN** system increments the throttle counter, throws a validation error "Invalid credentials.", and does not create a session

### Requirement: Header password authentication
When auth is enforced, the system SHALL accept authentication via the `X-Panel-Password` HTTP header (header-only). The provided value SHALL be compared to `PANEL_PASSWORD` using a constant-time comparison (`hash_equals`) to prevent timing attacks. The system SHALL NOT accept the password via query string (`?password=`) because query parameters leak into web server access logs, browser history, and the Referer header. The header path SHALL not require a session and SHALL not be rate-limited.

#### Scenario: Valid header password
- **WHEN** request includes `X-Panel-Password` matching `PANEL_PASSWORD`
- **THEN** system grants access without creating a session

#### Scenario: Invalid header password
- **WHEN** request includes `X-Panel-Password` that does not match
- **THEN** system falls through to the next check (WhatsApp sender)

#### Scenario: Query-string password is rejected
- **WHEN** request includes `?password=<value>` matching `PANEL_PASSWORD` but no `X-Panel-Password` header
- **THEN** system does NOT grant access and falls through to the next check

### Requirement: Rate limiting on login
The login form SHALL be rate-limited to 5 failed attempts per `username|ip` key per 60 seconds. The form route SHALL also be wrapped in `throttle:10,1` to cap total login submission rate. After exceeding the limit, the system SHALL block further submissions until the window expires. A successful login SHALL clear the throttle counter.

#### Scenario: Rate limit exceeded
- **WHEN** user submits 5 failed login attempts within 60 seconds
- **THEN** system raises a validation error stating how many seconds remain before the next attempt is allowed

#### Scenario: Rate limit reset after success
- **WHEN** user submits a successful login after some failed attempts
- **THEN** system clears the throttle counter for that `username|ip` key

### Requirement: WhatsApp number authentication
When auth is enforced, the system SHALL accept authentication via WhatsApp number through the `X-WA-Sender` header or `sender` request input. Numbers SHALL be normalized (digits only, leading `0` replaced with `62`, otherwise prepended with `62`) and matched against `PANEL_OWNER_NUMBERS` (comma-separated in `.env`).

#### Scenario: Valid WhatsApp number
- **WHEN** request includes `X-WA-Sender` with a number matching an entry in `PANEL_OWNER_NUMBERS` after normalization
- **THEN** system grants access without requiring a session

#### Scenario: Invalid WhatsApp number
- **WHEN** request includes `X-WA-Sender` with a number not in `PANEL_OWNER_NUMBERS`
- **THEN** system denies the request (no further fallback in the chain)

### Requirement: Session lifetime sliding window
When auth is enforced, the system SHALL refresh `panel_auth_time` on every authenticated request. When the elapsed time since `panel_auth_time` exceeds `PANEL_SESSION_LIFETIME` minutes (default 120), the system SHALL forget `panel_auth`, `panel_auth_time`, and `active_project` from the session and treat the request as unauthenticated.

#### Scenario: Active session is refreshed
- **WHEN** authenticated user makes any panel request before the lifetime expires
- **THEN** system updates `panel_auth_time` to the current timestamp

#### Scenario: Stale session is dropped
- **WHEN** more than `PANEL_SESSION_LIFETIME` minutes have passed since the last authenticated request
- **THEN** system clears auth keys from the session and forces re-authentication

### Requirement: Route protection and denial response
When auth is enforced and every credential check fails, the system SHALL deny the request. Web routes SHALL receive a redirect to `/panel/login`; routes under the `panel/api/*` prefix or any request that expects JSON SHALL receive HTTP 401 with a JSON body `{"error": "Unauthorized"}`.

#### Scenario: Unauthenticated browser request
- **WHEN** auth is enforced and an unauthenticated user navigates to `/panel/dashboard`
- **THEN** system redirects to `/panel/login` while preserving the intended URL

#### Scenario: Unauthenticated API request
- **WHEN** auth is enforced and an unauthenticated client posts to `/panel/api/files/save`
- **THEN** system responds with HTTP 401 and a JSON error payload

### Requirement: Logout
The system SHALL provide a logout endpoint at `POST /panel/logout` that forgets the `panel_auth`, `panel_auth_time`, `active_project`, and `query_history` session keys. When auth is enforced the response SHALL redirect to `/panel/login`; when auth is in bypass mode the response SHALL redirect to `/panel/dashboard`.

#### Scenario: User logs out (auth enforced)
- **WHEN** authenticated user submits POST to `/panel/logout`
- **THEN** system clears all listed session keys and redirects to `/panel/login`

#### Scenario: Logout while auth is in bypass mode
- **WHEN** any user submits POST to `/panel/logout` with both `PANEL_AUTH_ENABLED=false` and `PANEL_DEV_BYPASS=true`
- **THEN** system clears any leftover auth keys and redirects to `/panel/dashboard`

### Requirement: CSRF protection
Web routes outside `panel/api/*` SHALL be protected by Laravel's CSRF token verification, registered via `preventRequestForgery(['panel/api/*'])` in `bootstrap/app.php`. API routes are intentionally exempt because they are session-authenticated AJAX calls from the same origin or external scripts that supply the header password.

#### Scenario: Missing CSRF token on web route
- **WHEN** a non-API POST request is submitted without a valid CSRF token
- **THEN** system returns HTTP 419 (Page Expired)

#### Scenario: API request without CSRF token
- **WHEN** a `POST /panel/api/*` request is submitted with valid auth (or auth in bypass mode) and no CSRF token
- **THEN** system processes the request normally
