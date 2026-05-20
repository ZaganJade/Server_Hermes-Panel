/**
 * Singleton subscriber to the v3.2 monitoring snapshot stream.
 *
 * Multiple Alpine components (Dashboard health strip, full Monitoring
 * tab) register callbacks here instead of each opening their own WS
 * subscription. The store fans out the latest snapshot to every
 * subscriber and falls back to HTTP polling when:
 *   - Echo is not initialized (build issue or auth-disabled mode), or
 *   - The panel runs in trusted-network bypass mode (no session, so
 *     /broadcasting/auth would reject the WS handshake anyway).
 *
 * Bypass mode is signaled by the `<meta name="hermes-auth-bypass">` tag
 * the layout injects.
 */
class MonitoringSnapshotStore {
	constructor() {
		this.latest = null;
		this.subscribers = new Set();
		this.channel = null;
		this.pollInterval = null;
		this.bypassMode =
			document.querySelector('meta[name="hermes-auth-bypass"]')?.content ===
			"1";
	}

	connect() {
		if (this.channel || this.pollInterval) {
			return;
		}

		// First load: hydrate immediately so the UI doesn't render empty
		// for 5 s waiting on the next tick.
		this.fetchSnapshotOnce();

		if (this.bypassMode || !window.Echo) {
			this.startPolling();
			return;
		}

		try {
			this.channel = window.Echo.private("monitoring.host");
			this.channel.listen(".monitoring.snapshot", (payload) =>
				this._dispatch(payload),
			);
		} catch (err) {
			console.warn(
				"[monitoring-store] Echo subscribe failed, polling instead",
				err,
			);
			this.startPolling();
		}
	}

	startPolling() {
		this.pollInterval = setInterval(() => this.fetchSnapshotOnce(), 5000);
	}

	fetchSnapshotOnce() {
		fetch("/panel/api/monitoring/snapshot", {
			headers: {
				"X-Requested-With": "XMLHttpRequest",
				Accept: "application/json",
			},
			credentials: "same-origin",
		})
			.then((r) => r.json())
			.then((payload) => this._dispatch(payload))
			.catch(() => {});
	}

	subscribe(callback) {
		this.subscribers.add(callback);
		if (this.latest) {
			callback(this.latest);
		}
		return () => this.subscribers.delete(callback);
	}

	_dispatch(payload) {
		if (!payload || (!payload.ts && payload.ts !== null)) {
			return;
		}
		this.latest = payload;
		for (const fn of this.subscribers) {
			try {
				fn(payload);
			} catch (err) {
				console.warn("[monitoring-store] subscriber threw", err);
			}
		}
	}
}

window.HermesMonitoring =
	window.HermesMonitoring || new MonitoringSnapshotStore();

export default window.HermesMonitoring;
