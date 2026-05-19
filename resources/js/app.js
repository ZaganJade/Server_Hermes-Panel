import Alpine from "alpinejs";

import "./bootstrap";
import "./echo";
import { hermesTerminal } from "./terminal";

import "@xterm/xterm/css/xterm.css";

// Expose Alpine globally for inline `x-data="…()"` usage in blade.
window.Alpine = Alpine;

// Register the floating terminal data factory before Alpine starts so
// `<div x-data="hermesTerminal()">` resolves correctly when story 07
// adds the panel partial.
Alpine.data("hermesTerminal", hermesTerminal);

Alpine.start();
