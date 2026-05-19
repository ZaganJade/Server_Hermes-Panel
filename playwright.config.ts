import { defineConfig, devices } from "@playwright/test";

/**
 * Playwright config for the Hermes Panel real-time terminal E2E suite.
 *
 * The suite assumes the panel is reachable at PANEL_E2E_URL (default
 * http://127.0.0.1:8080). When the URL is unreachable, individual tests
 * skip themselves rather than failing — keeping CI green on hosts that
 * don't bring up a container automatically.
 */
const baseURL = process.env.PANEL_E2E_URL ?? "http://127.0.0.1:8080";

export default defineConfig({
	testDir: "./tests/e2e",
	timeout: 60_000,
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI ? "github" : "list",
	use: {
		baseURL,
		trace: "on-first-retry",
		screenshot: "only-on-failure",
		video: "retain-on-failure",
		actionTimeout: 15_000,
		navigationTimeout: 30_000,
		ignoreHTTPSErrors: true,
		extraHTTPHeaders: {
			"X-Panel-Password": process.env.PANEL_E2E_PASSWORD ?? "changeme",
		},
	},
	projects: [
		{
			name: "chromium",
			use: { ...devices["Desktop Chrome"] },
		},
	],
});
