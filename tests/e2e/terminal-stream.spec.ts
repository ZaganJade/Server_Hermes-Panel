import { expect, test } from "@playwright/test";
import { ensurePanelReachable, openFloatingTerminal } from "./_setup";

test.describe("real-time terminal — streaming", () => {
	test.beforeAll(async ({ request }) => {
		await ensurePanelReachable(test, request);
	});

	test("runs a chained command and streams output progressively", async ({
		page,
	}) => {
		await page.goto("/panel/dashboard");

		const term = await openFloatingTerminal(page);

		const command = "echo hello-stream && sleep 1 && echo world-stream";
		await term.input.fill(command);
		await term.input.press("Enter");

		// Progressive output: the first echo arrives well before the second.
		await expect(term.canvas).toContainText("hello-stream", {
			timeout: 10_000,
		});

		// World shows up after the sleep completes.
		await expect(term.canvas).toContainText("world-stream", {
			timeout: 15_000,
		});

		// Exit chunk reaches the canvas eventually.
		await expect(term.canvas).toContainText(/\[exit\s*0\]/, {
			timeout: 15_000,
		});

		// History navigation: pressing up should bring back the command.
		await term.input.press("ArrowUp");
		await expect(term.input).toHaveValue(command);
	});
});
