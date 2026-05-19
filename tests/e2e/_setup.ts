import {
	type APIRequestContext,
	expect,
	type Page,
	type TestType,
} from "@playwright/test";

/**
 * Skip the suite entirely if the panel isn't reachable. Lets contributors
 * run `composer test:e2e` without a running container; CI is expected to
 * spin one up before invoking Playwright.
 */
export async function ensurePanelReachable(
	test: TestType<unknown, unknown>,
	request: APIRequestContext,
): Promise<void> {
	try {
		const response = await request.get("/up", { timeout: 5_000 });
		if (!response.ok()) {
			test.skip(
				true,
				`Panel responded ${response.status()} on /up — start the container before running E2E.`,
			);
		}
	} catch (error) {
		test.skip(
			true,
			`Panel not reachable: ${(error as Error).message}. Start the container or set PANEL_E2E_URL.`,
		);
	}
}

/**
 * Open the floating terminal and return references to its canvas and
 * command input. Idempotent — works whether the panel was already open.
 */
export async function openFloatingTerminal(page: Page) {
	const toggle = page.locator('aside button[title="Toggle terminal"]').first();
	await expect(toggle).toBeVisible({ timeout: 10_000 });
	await toggle.click();

	const root = page.locator(".hermes-terminal-root").first();
	const canvas = root.locator(".xterm-screen, .xterm").first();
	const input = root
		.locator('input[placeholder*="command"], input[placeholder*="running"]')
		.first();

	await expect(canvas).toBeVisible({ timeout: 10_000 });
	await expect(input).toBeVisible({ timeout: 10_000 });

	return { root, canvas, input };
}
