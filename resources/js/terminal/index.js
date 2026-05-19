import { TerminalHistory } from "./history";
import { TerminalSession } from "./session";
import { createXtermInstance } from "./xterm-instance";

/**
 * Alpine data factory for the floating terminal panel.
 *
 * Story v3.1-06 ships only the skeleton: dependencies installed, modules
 * wired, factory exported. Actual UI behaviour (open/close, project
 * switch, keyboard shortcuts, replay-on-mount) lands in story v3.1-07.
 */
export function hermesTerminal() {
	return {
		// Visibility
		open: false,
		expanded: false,
		minimized: false,

		// Project context
		project: null,
		cwd: "/",
		display: "",

		// Lifecycle
		status: "idle", // 'idle' | 'running' | 'exiting' | 'done'
		sessionId: null,

		// History
		history: [],
		historyIndex: -1,
		inputBuffer: "",

		// xterm + WS handles (filled lazily by story 07)
		term: null,
		fitAddon: null,
		searchAddon: null,
		echoChannel: null,

		_xterm: null,
		_session: null,
		_historyManager: null,

		init() {
			// Lazy-init the supporting objects; story 07 will mount xterm
			// into the DOM and call `_session.connect(this.project)`.
			this._historyManager = new TerminalHistory();
			this._session = new TerminalSession();
		},

		// UI handlers (no-op until story 07)
		toggle() {
			this.open = !this.open;
		},
		close() {
			this.open = false;
		},
		run() {
			/* TODO v3.1-07 */
		},
		stop() {
			/* TODO v3.1-07 */
		},
		reset() {
			/* TODO v3.1-07 */
		},
	};
}

export { createXtermInstance, TerminalHistory, TerminalSession };
