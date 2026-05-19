import { FitAddon } from "@xterm/addon-fit";
import { SearchAddon } from "@xterm/addon-search";
import { WebLinksAddon } from "@xterm/addon-web-links";
import { Terminal } from "@xterm/xterm";

/**
 * Build a configured xterm instance plus its addons.
 *
 * Story 07 will call `terminal.open(container)` to mount it. The fit
 * addon resizes columns/rows to the container; the search addon adds
 * Ctrl+F; web-links makes URLs clickable.
 *
 * Theme matches the panel's editorial dark palette so output blends
 * with the surrounding UI rather than looking like a generic console.
 */
export function createXtermInstance() {
	const term = new Terminal({
		cursorBlink: true,
		fontFamily:
			'"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace',
		fontSize: 13,
		lineHeight: 1.35,
		convertEol: true,
		scrollback: 5000,
		theme: {
			background: "#0e0d0a",
			foreground: "#f4ede1",
			cursor: "#d4a45c",
			cursorAccent: "#0e0d0a",
			selectionBackground: "rgba(212, 164, 92, 0.35)",
			selectionForeground: "#f4ede1",
			black: "#0e0d0a",
			red: "#b85c44",
			green: "#5a7a5a",
			yellow: "#d4a45c",
			blue: "#7a8eaa",
			magenta: "#a87aa3",
			cyan: "#6a8a8a",
			white: "#ddd2bd",
			brightBlack: "#3a3631",
			brightRed: "#d47a5a",
			brightGreen: "#7aa07a",
			brightYellow: "#e6c178",
			brightBlue: "#9aaecf",
			brightMagenta: "#c79ac0",
			brightCyan: "#8aa5a5",
			brightWhite: "#f4ede1",
		},
	});

	const fitAddon = new FitAddon();
	const searchAddon = new SearchAddon();
	const webLinksAddon = new WebLinksAddon();

	term.loadAddon(fitAddon);
	term.loadAddon(searchAddon);
	term.loadAddon(webLinksAddon);

	return { term, fitAddon, searchAddon, webLinksAddon };
}
