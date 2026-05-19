# End-to-end tests

These specs drive the Hermes Panel through a real Chromium browser via
Playwright. They cover the v3.1 real-time terminal and any future flows
that need actual JavaScript execution (xterm rendering, WebSocket,
Alpine reactivity).

## Running locally

1. **Start the panel.** Either:
   ```bash
   docker compose up -d --build
   ```
   or run a local PHP dev server with `php artisan serve --host=127.0.0.1 --port=8080`.
   Build assets first with `npm run build`.

2. **Set the panel password.** The Playwright config sends `X-Panel-Password` headers automatically. Match it to your `.env`:
   ```bash
   export PANEL_E2E_PASSWORD=changeme
   export PANEL_E2E_URL=http://127.0.0.1:8080  # default
   ```

3. **Install browsers** (one-time):
   ```bash
   npx playwright install chromium
   ```

4. **Run the suite:**
   ```bash
   composer test:e2e
   # or directly:
   npx playwright test
   ```

## What's covered

- `terminal-stream.spec.ts` — open the floating terminal, run a command, observe streaming output, exit chunk, and history navigation.
- `terminal-reconnect.spec.ts` — start a longer command, reload mid-execution, and verify replay + continued streaming.

Tests skip themselves gracefully when the panel isn't reachable, so this
suite stays optional in environments without a running container.
