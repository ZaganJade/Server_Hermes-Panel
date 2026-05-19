import { expect, test } from "@playwright/test";
import { ensurePanelReachable, openFloatingTerminal } from "./_setup";

test.describe("real-time terminal — reconnect & replay", () => {
	test.beforeAll(async ({ request }) => {
		await ensurePanelReachable(test, request);
	});

	test("survives a mid-stream page reload via buffer replay", async ({
		page,
	}) => {
		await page.goto("/panel/dashboard");

		const term = await openFloatingTerminal(page);

		// 6-second loop produces output once per second so we have a clear
		// "before reload" / "after reload" boundary.
		const command =
			"for i in 1 2 3 4 5 6; do echo step-$i; sleep 1; done; echo done-recover";
		await term.input.fill(command);
		await term.input.press("Enter");

		// Wait for the early steps to land before the reload.
		await expect(term.canvas).toContainText("step-1", { timeout: 10_000 });
		await expect(term.canvas).toContainText("step-2", { timeout: 10_000 });

		// Reload the page mid-stream.
		await page.reload();

		const recovered = await openFloatingTerminal(page);

		// Replay must surface what we already saw …
		await expect(recovered.canvas).toContainText("step-1", {
			timeout: 10_000,
		});
		await expect(recovered.canvas).toContainText("step-2", {
			timeout: 10_000,
		});

		// … and live streaming continues.
		await expect(recovered.canvas).toContainText("step-6", {
			timeout: 15_000,
		});
		await expect(recovered.canvas).toContainText("done-recover", {
			timeout: 15_000,
		});
		await expect(recovered.canvas).toContainText(/\[exit\s*0\]/, {
			timeout: 15_000,
		});
	});
});
