/**
 * Manages the WS subscription + REST round-trips for the terminal.
 *
 * This is the skeleton from story v3.1-06 — it knows how to talk to
 * the API, but story v3.1-07 will fill in the chunk-handling glue and
 * the reconnect/replay flow once the floating panel UI is in place.
 */
export class TerminalSession {
	constructor() {
		this.project = null;
		this.sessionId = null;
		this.echoChannel = null;
		this.csrf =
			document.querySelector('meta[name="csrf-token"]')?.content ?? "";
	}

	/**
	 * Subscribe to the project's private channel. No-op when Echo is
	 * unavailable (e.g. trusted-network bypass mode where broadcasting
	 * is disabled and the client uses /execute-sync only).
	 */
	connect(project) {
		this.project = project;
		if (!window.Echo || !project) {
			return null;
		}
		this.echoChannel = window.Echo.private(`terminal.${project}`);
		return this.echoChannel;
	}

	disconnect() {
		if (this.echoChannel && this.project) {
			window.Echo.leave(`terminal.${this.project}`);
		}
		this.echoChannel = null;
		this.project = null;
	}

	/** Listen for `terminal.output` events with the supplied handler. */
	onChunk(handler) {
		if (!this.echoChannel) {
			return;
		}
		this.echoChannel.listen(".terminal.output", handler);
	}

	/** Fetch the current state for a project (cwd, session, history). */
	async fetchState(project) {
		const url = `/panel/api/terminal/state?project=${encodeURIComponent(project)}`;
		const response = await fetch(url, {
			headers: this._headers(),
			credentials: "same-origin",
		});
		if (!response.ok) {
			throw new Error(`State fetch failed: ${response.status}`);
		}
		return response.json();
	}

	/** Spawn a new session on the server. Returns 202 + session_id on success. */
	async execute(project, command) {
		const response = await fetch("/panel/api/terminal/execute", {
			method: "POST",
			headers: { ...this._headers(), "Content-Type": "application/json" },
			credentials: "same-origin",
			body: JSON.stringify({ project, command }),
		});
		const body = await response.json().catch(() => ({}));
		return { status: response.status, body };
	}

	async stop(sessionId) {
		const response = await fetch(`/panel/api/terminal/${sessionId}/stop`, {
			method: "POST",
			headers: this._headers(),
			credentials: "same-origin",
		});
		return response.ok;
	}

	async replay(sessionId) {
		const response = await fetch(`/panel/api/terminal/${sessionId}/replay`, {
			headers: this._headers(),
			credentials: "same-origin",
		});
		if (!response.ok) {
			return { session: null, chunks: [], status: "idle" };
		}
		return response.json();
	}

	_headers() {
		return {
			"X-Requested-With": "XMLHttpRequest",
			"X-CSRF-TOKEN": this.csrf,
			Accept: "application/json",
		};
	}
}
