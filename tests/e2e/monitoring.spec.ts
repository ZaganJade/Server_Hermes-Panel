import { expect, test } from "@playwright/test";
import { ensurePanelReachable } from "./_setup";

test.describe("v3.2 monitoring — dashboard health strip + tab", () => {
	test.beforeAll(async ({ request }) => {
		await ensurePanelReachable(test, request);
	});

	test("dashboard renders 4 health-strip cards with sparkline placeholders", async ({
		page,
	}) => {
		await page.goto("/panel/dashboard");

		// All four metric labels in the strip.
		for (const label of ["CPU", "Memori", "Disk", "Jaringan"]) {
			await expect(page.getByText(label, { exact: true })).toBeVisible({
				timeout: 10_000,
			});
		}

		// Each card has a canvas the sparkline mounts into.
		const canvases = page.locator(".animate-fade-up canvas");
		await expect(canvases).toHaveCount(4, { timeout: 10_000 });
	});

	test("clicking CPU card navigates to monitoring tab anchored at #cpu", async ({
		page,
	}) => {
		await page.goto("/panel/dashboard");

		const cpuCard = page.locator('a[href*="/panel/monitoring#cpu"]').first();
		await expect(cpuCard).toBeVisible({ timeout: 10_000 });
		await cpuCard.click();

		await expect(page).toHaveURL(/\/panel\/monitoring#cpu$/, {
			timeout: 10_000,
		});

		// Window selector is one of the most distinctive elements on the
		// monitoring view — assert it rendered.
		await expect(page.getByRole("button", { name: "5m" })).toBeVisible({
			timeout: 10_000,
		});
		await expect(page.getByRole("button", { name: "24h" })).toBeVisible({
			timeout: 10_000,
		});
	});

	test("switching window selector triggers a series fetch", async ({
		page,
	}) => {
		await page.goto("/panel/monitoring");

		// Default window is 15m. Watch for the XHR triggered by clicking 1h.
		const seriesRequest = page.waitForRequest(
			(req) =>
				req.url().includes("/panel/api/monitoring/series") &&
				req.url().includes("window=1h"),
		);

		await page.getByRole("button", { name: "1h" }).click();
		await seriesRequest;

		// The 1h button is now the active one.
		const oneHourButton = page.getByRole("button", { name: "1h" });
		await expect(oneHourButton).toHaveClass(/text-copper/);
	});
});
