import { TerminalHistory } from "./history";
import { TerminalSession } from "./session";
import { createXtermInstance } from "./xterm-instance";

const STORAGE_KEY_OPEN = "hermes:terminal:open";
const STORAGE_KEY_EXPANDED = "hermes:terminal:expanded";

const STATUS_LABEL = {
	idle: "idle",
	running: "running",
	exiting: "exiting",
	done: "done",
};

const STATUS_GLYPH = {
	idle: "◌",
	running: "⚡",
	exiting: "⏳",
	done: "✓",
};

/**
 * Alpine data factory for the floating terminal panel.
 *
 * Mounts xterm into the DOM, talks to /panel/api/terminal/* via
 * TerminalSession, and listens for terminal.output broadcasts on the
 * project's private channel.
 */
export function hermesTerminal() {
	return {
		// Visibility
		open: false,
		expanded: false,
		minimized: false,
		showWelcome: true,

		// Project context
		project: null,
		cwd: "/",
		display: "",

		// Lifecycle
		status: "idle",
		sessionId: null,

		// Input state
		inputBuffer: "",

		// Internals
		_xterm: null,
		_fitAddon: null,
		_searchAddon: null,
		_session: null,
		_history: null,
		_resizeObserver: null,
		_keyHandler: null,
		_authBypass: false,

		init() {
			this._session = new TerminalSession();
			this._history = new TerminalHistory();
			this._authBypass =
				document.querySelector('meta[name="hermes-auth-bypass"]')?.content ===
				"1";
			this.project =
				document.querySelector('meta[name="hermes-active-project"]')?.content ||
				null;

			// Restore open / expanded preferences across navigation.
			try {
				this.open = localStorage.getItem(STORAGE_KEY_OPEN) === "1";
				this.expanded = localStorage.getItem(STORAGE_KEY_EXPANDED) === "1";
			} catch (_) {
				// localStorage may be unavailable (private mode etc.)
			}

			this._publishStatus();

			// Initial state fetch + replay if a session is already running.
			if (this.project) {
				this.loadState();
			}
		},

		statusLabel() {
			return STATUS_LABEL[this.status] ?? this.status;
		},

		toggle() {
			this.open = !this.open;
			this.persistViewState();

			if (this.open) {
				this.$nextTick(() => this.mountXterm());
				this.$nextTick(() => {
					this.$refs.termInput?.focus();
				});
			}
		},

		close() {
			this.open = false;
			this.persistViewState();
		},

		persistViewState() {
			try {
				localStorage.setItem(STORAGE_KEY_OPEN, this.open ? "1" : "0");
				localStorage.setItem(STORAGE_KEY_EXPANDED, this.expanded ? "1" : "0");
			} catch (_) {}

			// xterm needs a refit after layout changes.
			if (this.open && this._fitAddon) {
				this.$nextTick(() => this._fitAddon?.fit());
			}
		},

		mountXterm() {
			if (this._xterm || !this.$refs.termContainer) {
				return;
			}

			const { term, fitAddon, searchAddon } = createXtermInstance();
			this._xterm = term;
			this._fitAddon = fitAddon;
			this._searchAddon = searchAddon;

			term.open(this.$refs.termContainer);

			try {
				fitAddon.fit();
			} catch (_) {}

			// Resize-on-container-change keeps xterm crisp.
			if (typeof ResizeObserver !== "undefined") {
				this._resizeObserver = new ResizeObserver(() => {
					try {
						fitAddon.fit();
					} catch (_) {}
				});
				this._resizeObserver.observe(this.$refs.termContainer);
			}

			this._keyHandler = (event) => this.handleHotkey(event);
			window.addEventListener("keydown", this._keyHandler);

			// Subscribe to the project's private channel + replay state once
			// the canvas is mounted.
			this.subscribe();
			this.replayCurrent();
		},

		handleHotkey(event) {
			if (!this.open) return;

			// Ctrl+L → clear xterm screen
			if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "l") {
				event.preventDefault();
				this._xterm?.clear();
			}

			// Ctrl+F → search overlay (xterm-addon-search exposes nothing UI-wise,
			// so we simply prompt; story 08 may swap for a real overlay).
			if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "f") {
				event.preventDefault();
				const needle = window.prompt("Search terminal:");
				if (needle) {
					this._searchAddon?.findNext(needle);
				}
			}
		},

		async loadState() {
			if (!this.project) return;
			try {
				const state = await this._session.fetchState(this.project);
				this.cwd = state.cwd ?? this.cwd;
				this.display = state.display ?? "";
				this._history.setItems(state.history ?? []);

				if (state.session) {
					this.sessionId = state.session.session_id;
					this.status = state.session.status ?? "running";
				} else {
					this.sessionId = null;
					this.status = "idle";
				}
				this._publishStatus();
			} catch (err) {
				console.warn("[hermes-terminal] state fetch failed", err);
			}
		},

		subscribe() {
			if (this._authBypass || !this.project || !window.Echo) {
				return;
			}
			const channel = this._session.connect(this.project);
			if (!channel) return;

			this._session.onChunk((event) => this.onChunk(event));
		},

		async replayCurrent() {
			if (!this.sessionId || !this._xterm) return;
			try {
				const replay = await this._session.replay(this.sessionId);
				if (Array.isArray(replay.chunks)) {
					for (const chunk of replay.chunks) {
						this.writeChunk(chunk, false);
					}
				}
				if (replay.status) {
					this.status = replay.status;
					this._publishStatus();
				}
			} catch (err) {
				console.warn("[hermes-terminal] replay failed", err);
			}
		},

		onChunk(event) {
			if (!event) return;
			this.writeChunk(event, true);

			if (event.type === "exit") {
				this.status = "done";
				this.sessionId = null;
				this._publishStatus();
			}
		},

		writeChunk(chunk, _live) {
			if (!this._xterm) return;
			const data = chunk?.data ?? "";
			if (!data) return;

			// xterm.write uses CR/LF; tick-loop already sends raw stream output.
			this._xterm.write(data.replace(/\n/g, "\r\n"));
		},

		async run() {
			if (!this.project) {
				window.showToast?.(
					"Pilih proyek dulu sebelum jalanin perintah.",
					"error",
				);
				return;
			}
			const command = this.inputBuffer.trim();
			if (!command) return;

			this._xterm?.write(`\r\n\x1b[2;33m$ ${command}\x1b[0m\r\n`);
			this._history.push(command);
			this.inputBuffer = "";

			if (this._authBypass) {
				return this.runSync(command);
			}

			try {
				const { status, body } = await this._session.execute(
					this.project,
					command,
				);
				if (status === 202) {
					this.sessionId = body.session_id;
					this.status = "running";
					this.cwd = body.cwd ?? this.cwd;
					this.display = body.display ?? this.display;
					this._publishStatus();
				} else if (status === 401) {
					window.showToast?.(
						"Session login kadaluarsa. Refresh halaman.",
						"error",
					);
				} else if (status === 409) {
					this.sessionId = body.session_id;
					this.status = "running";
					window.showToast?.(
						"Sesi sebelumnya masih jalan. Stop dulu atau tunggu selesai.",
						"warning",
					);
				} else if (status === 422) {
					window.showToast?.(body.error ?? "Perintah ditolak.", "error");
				} else if (status === 429) {
					window.showToast?.("Terlalu cepat. Tunggu sebentar.", "warning");
				} else {
					window.showToast?.(body.error ?? `HTTP ${status}`, "error");
				}
			} catch (err) {
				window.showToast?.("Gagal menghubungi server.", "error");
				console.error(err);
			}
		},

		async runSync(command) {
			try {
				const response = await fetch("/panel/api/terminal/execute-sync", {
					method: "POST",
					headers: {
						"Content-Type": "application/json",
						"X-Requested-With": "XMLHttpRequest",
						"X-CSRF-TOKEN":
							document.querySelector('meta[name="csrf-token"]')?.content || "",
					},
					credentials: "same-origin",
					body: JSON.stringify({ command }),
				});
				const body = await response.json();
				if (body.output) {
					this._xterm?.write(body.output.replace(/\n/g, "\r\n"));
				}
				if (body.error) {
					this._xterm?.write(
						`\x1b[31m${body.error}\x1b[0m`.replace(/\n/g, "\r\n"),
					);
				}
				this.cwd = body.cwd ?? this.cwd;
				this.display = body.display ?? this.display;
			} catch (err) {
				window.showToast?.("Gagal menjalankan perintah.", "error");
				console.error(err);
			}
		},

		async stop() {
			if (!this.sessionId) return;
			this.status = "exiting";
			this._publishStatus();
			try {
				await this._session.stop(this.sessionId);
			} catch (err) {
				console.warn("[hermes-terminal] stop failed", err);
			}
		},

		async reset() {
			try {
				const response = await fetch("/panel/api/terminal/reset", {
					method: "POST",
					headers: {
						"Content-Type": "application/json",
						"X-Requested-With": "XMLHttpRequest",
						"X-CSRF-TOKEN":
							document.querySelector('meta[name="csrf-token"]')?.content || "",
					},
					credentials: "same-origin",
					body: JSON.stringify({ project: this.project }),
				});
				const body = await response.json();
				this.cwd = body.cwd ?? this.cwd;
				this.display = body.display ?? this.display;
				this.sessionId = null;
				this.status = "idle";
				this._xterm?.clear();
				this._publishStatus();
			} catch (_err) {
				window.showToast?.("Reset gagal.", "error");
			}
		},

		historyPrev() {
			const value = this._history.prev();
			if (value !== null) {
				this.inputBuffer = value;
			}
		},

		historyNext() {
			this.inputBuffer = this._history.next() ?? "";
		},

		onProjectChange(newProject) {
			if (!newProject || newProject === this.project) return;

			// Tear down current subscription, fetch new state.
			this._session.disconnect();
			this.project = newProject;
			this.sessionId = null;
			this.status = "idle";
			this._xterm?.clear();
			this._publishStatus();

			this.loadState().then(() => {
				this.subscribe();
				this.replayCurrent();
			});
		},

		_publishStatus() {
			try {
				window.Alpine?.store("hermesTerminalStatus", {
					status: this.status,
					label: STATUS_LABEL[this.status] ?? this.status,
					glyph: STATUS_GLYPH[this.status] ?? "◌",
				});
			} catch (_) {}
		},
	};
}

export { createXtermInstance, TerminalHistory, TerminalSession };
