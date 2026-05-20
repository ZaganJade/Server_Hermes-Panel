import { createLineChart, createSparkline, monitoringTheme } from "./charts";
import { clearServicesCache, fetchSeries } from "./series";
import store from "./snapshot-store";

const SPARKLINE_LENGTH = 60;

/**
 * Alpine factory for the Dashboard health strip (4 sparkline cards).
 * Each card subscribes to the snapshot store and updates its sparkline
 * + current value reading on every snapshot.
 */
export function hermesHealthStrip() {
	return {
		current: {
			cpu: "—",
			mem: "—",
			disk: "—",
			net: "—",
		},
		alerts: { cpu: "ok", mem: "ok", disk: "ok", net: "ok" },
		_sparks: {},
		_history: { cpu: [], mem: [], disk: [], net: [] },

		init() {
			store.connect();
			this._unsubscribe = store.subscribe((payload) =>
				this._onSnapshot(payload),
			);

			// Build sparklines lazily on first snapshot to avoid layout
			// thrash before the first reading.
		},

		destroy() {
			this._unsubscribe?.();
			for (const s of Object.values(this._sparks)) {
				s?.destroy?.();
			}
		},

		_onSnapshot(payload) {
			if (!payload?.entries) return;

			const ts = Math.floor(Date.now() / 1000);
			this._pushHistory("cpu", ts, payload.entries.cpu?.usage_pct_total ?? 0);
			this._pushHistory("mem", ts, this._memPct(payload.entries.memory));
			this._pushHistory(
				"disk",
				ts,
				this._diskPctMax(payload.entries.disk_usage),
			);
			this._pushHistory("net", ts, this._netRxTotal(payload.entries.network));

			this.current.cpu = this._fmtPct(payload.entries.cpu?.usage_pct_total);
			this.current.mem = this._fmtPct(this._memPct(payload.entries.memory));
			this.current.disk = this._fmtPct(
				this._diskPctMax(payload.entries.disk_usage),
			);
			this.current.net = this._fmtBps(
				this._netRxTotal(payload.entries.network) +
					this._netTxTotal(payload.entries.network),
			);

			this._updateAlerts(payload.alerts ?? []);
			this._renderSparklines();
		},

		_renderSparklines() {
			for (const key of ["cpu", "mem", "disk", "net"]) {
				const ref = this.$refs[`spark_${key}`];
				if (!ref) continue;

				const data = this._buildSeries(this._history[key]);
				if (!this._sparks[key]) {
					this._sparks[key] = createSparkline(ref, data);
				} else {
					this._sparks[key].setData(data);
				}
			}
		},

		_pushHistory(key, ts, value) {
			const arr = this._history[key];
			arr.push({ ts, value: Number(value ?? 0) });
			while (arr.length > SPARKLINE_LENGTH) {
				arr.shift();
			}
		},

		_buildSeries(history) {
			return [history.map((p) => p.ts), history.map((p) => p.value)];
		},

		_memPct(memory) {
			if (!memory?.total_kb || !memory?.used_kb) return 0;
			return (memory.used_kb / memory.total_kb) * 100;
		},

		_diskPctMax(diskUsage) {
			if (!Array.isArray(diskUsage) || !diskUsage.length) return 0;
			return Math.max(...diskUsage.map((d) => d.used_pct ?? 0));
		},

		_netRxTotal(network) {
			if (!Array.isArray(network)) return 0;
			return network.reduce((sum, r) => sum + (r.rx_bytes_per_sec ?? 0), 0);
		},

		_netTxTotal(network) {
			if (!Array.isArray(network)) return 0;
			return network.reduce((sum, r) => sum + (r.tx_bytes_per_sec ?? 0), 0);
		},

		_fmtPct(value) {
			if (value === null || value === undefined) return "—";
			return `${Number(value).toFixed(1)}%`;
		},

		_fmtBps(bytesPerSec) {
			if (!bytesPerSec) return "0 B/s";
			const units = ["B/s", "KB/s", "MB/s", "GB/s"];
			let v = bytesPerSec;
			let i = 0;
			while (v >= 1024 && i < units.length - 1) {
				v /= 1024;
				i++;
			}
			return `${v.toFixed(1)} ${units[i]}`;
		},

		_updateAlerts(alerts) {
			const map = {};
			for (const alert of alerts) {
				const id = alert.rule_id ?? "";
				if (id.startsWith("cpu")) map.cpu = alert.level;
				else if (id.startsWith("mem")) map.mem = alert.level;
				else if (id.startsWith("disk")) map.disk = alert.level;
			}
			Object.assign(
				this.alerts,
				{ cpu: "ok", mem: "ok", disk: "ok", net: "ok" },
				map,
			);
		},
	};
}

/**
 * Alpine factory for the full Monitoring tab. Hosts 6 charts + service
 * table + alerts log.
 */
export function hermesMonitoring() {
	return {
		window: "15m",
		latestPayload: null,
		services: [],
		processes: [],
		ports: [],
		alerts: [],
		_charts: {},
		_unsubscribe: null,
		showProcesses: false,
		showPorts: false,
		refreshing: false,

		init() {
			store.connect();
			this._unsubscribe = store.subscribe((payload) =>
				this._onSnapshot(payload),
			);

			// Backfill charts with the chosen window.
			this.$nextTick(() => this.loadSeries());
		},

		destroy() {
			this._unsubscribe?.();
			for (const c of Object.values(this._charts)) {
				c?.destroy?.();
			}
		},

		setWindow(w) {
			this.window = w;
			this.loadSeries();
		},

		async loadSeries() {
			try {
				const result = await fetchSeries(
					[
						"cpu.usage_pct",
						"mem.used_kb",
						"disk.read_bytes_per_sec",
						"disk.write_bytes_per_sec",
						"net.tcp_established",
					],
					this.window,
				);
				this._renderHistorical(result);
			} catch (err) {
				console.warn("[monitoring] series fetch failed", err);
			}
		},

		async refreshServices() {
			this.refreshing = true;
			await clearServicesCache();
			this.refreshing = false;
		},

		_onSnapshot(payload) {
			if (!payload?.entries) return;
			this.latestPayload = payload;
			this.services = Array.isArray(payload.entries.services)
				? payload.entries.services
				: [];
			this.processes = Array.isArray(payload.entries.process)
				? payload.entries.process
				: [];
			this.ports = Array.isArray(payload.entries.ports)
				? payload.entries.ports
				: [];
			this.alerts = Array.isArray(payload.alerts) ? payload.alerts : [];
		},

		_renderHistorical(result) {
			const series = result.series ?? {};
			for (const [metric, values] of Object.entries(series)) {
				const ref = this.$refs[`chart_${this._slug(metric)}`];
				if (!ref) continue;

				const points =
					result.resolution === "raw"
						? Object.entries(values).map(([ts, v]) => [Number(ts), Number(v)])
						: Object.entries(values).map(([ts, v]) => [
								Number(ts),
								Number(v.avg ?? 0),
							]);

				if (!points.length) continue;

				const data = [points.map((p) => p[0]), points.map((p) => p[1])];

				if (!this._charts[metric]) {
					this._charts[metric] = createLineChart(ref, data, {
						height: 200,
					});
				} else {
					this._charts[metric].setData(data);
				}
			}
		},

		_slug(metric) {
			return metric.replace(/[^a-z0-9]+/gi, "_");
		},

		statusColor(level) {
			if (level === "critical") return monitoringTheme.rust;
			if (level === "warning") return monitoringTheme.copper;
			return monitoringTheme.verdigris;
		},
	};
}
