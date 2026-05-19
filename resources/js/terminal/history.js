/**
 * Per-project command-history navigator.
 *
 * Wraps a flat command list with up/down-arrow traversal and an
 * optional client-side filter. Server-side history (50 most recent
 * commands per project) is fetched via /panel/api/terminal/state and
 * fed into this manager via `setItems`.
 *
 * Story v3.1-07 will plug it into the keyboard handler.
 */
export class TerminalHistory {
	constructor(items = []) {
		this.items = items;
		this.index = -1; // -1 = no selection (input-as-typed)
	}

	setItems(items) {
		this.items = Array.isArray(items) ? items : [];
		this.index = -1;
	}

	push(command) {
		if (!command) {
			return;
		}
		this.items.unshift({ ts: Date.now(), command, exit_code: null });
		this.items = this.items.slice(0, 50);
		this.index = -1;
	}

	prev() {
		if (this.items.length === 0) {
			return null;
		}
		this.index = Math.min(this.index + 1, this.items.length - 1);
		return this.items[this.index]?.command ?? null;
	}

	next() {
		if (this.index <= 0) {
			this.index = -1;
			return "";
		}
		this.index -= 1;
		return this.items[this.index]?.command ?? "";
	}

	reset() {
		this.index = -1;
	}

	filter(needle) {
		if (!needle) {
			return this.items;
		}
		const q = needle.toLowerCase();
		return this.items.filter((entry) =>
			(entry.command ?? "").toLowerCase().includes(q),
		);
	}
}
