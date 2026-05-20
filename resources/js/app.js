import Alpine from "alpinejs";

import "./bootstrap";
import "./echo";
import { hermesHealthStrip, hermesMonitoring } from "./monitoring";
import { hermesTerminal } from "./terminal";

import "@xterm/xterm/css/xterm.css";

// Expose Alpine globally for inline `x-data="…()"` usage in blade.
window.Alpine = Alpine;

// Register Alpine data factories before start so `<div x-data="…()">`
// in blade templates resolves on first render.
Alpine.data("hermesTerminal", hermesTerminal);
Alpine.data("hermesMonitoring", hermesMonitoring);
Alpine.data("hermesHealthStrip", hermesHealthStrip);

Alpine.start();
