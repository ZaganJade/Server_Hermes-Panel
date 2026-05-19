import Echo from "laravel-echo";
import Pusher from "pusher-js";

/**
 * Wires up Laravel Echo against the Reverb server.
 *
 * Reverb implements the Pusher protocol so we use `pusher-js` as the
 * underlying client. The host and scheme come from Vite-exposed env
 * vars defined in `.env.example` — keep them in sync with the
 * server-side `REVERB_*` values.
 *
 * In production behind HTTPS:
 *     VITE_REVERB_PORT=443
 *     VITE_REVERB_SCHEME=https
 *     reverse proxy forwards `/app` to the container's Reverb on 8081
 *
 * In local HTTP development with the container exposing 8081 directly:
 *     VITE_REVERB_PORT=8081
 *     VITE_REVERB_SCHEME=http
 */
window.Pusher = Pusher;

const reverbScheme = import.meta.env.VITE_REVERB_SCHEME ?? "https";
const useTLS = reverbScheme === "https";

window.Echo = new Echo({
	broadcaster: "reverb",
	key: import.meta.env.VITE_REVERB_APP_KEY,
	wsHost: import.meta.env.VITE_REVERB_HOST,
	wsPort: import.meta.env.VITE_REVERB_PORT ?? (useTLS ? 443 : 80),
	wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
	forceTLS: useTLS,
	enabledTransports: ["ws", "wss"],
	// Send the panel session cookie so /broadcasting/auth recognises us.
	authEndpoint: "/broadcasting/auth",
});

export default window.Echo;
