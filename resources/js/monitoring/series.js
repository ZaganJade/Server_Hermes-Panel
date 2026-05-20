/**
 * HTTP series fetcher with debounce + window-to-resolution mapping.
 *
 * The Monitoring tab calls fetchSeries() when the window selector
 * changes. We debounce 200 ms so rapid clicks don't fire multiple
 * round-trips, and abort the previous request when a new one starts.
 */
let abortController = null;
let debounceTimer = null;

const csrfToken = () =>
	document.querySelector('meta[name="csrf-token"]')?.content || "";

export function fetchSeries(metrics, window = "15m") {
	clearTimeout(debounceTimer);
	if (abortController) {
		abortController.abort();
	}
	abortController = new AbortController();

	return new Promise((resolve, reject) => {
		debounceTimer = setTimeout(() => {
			const params = new URLSearchParams();
			for (const m of metrics) {
				params.append("metrics[]", m);
			}
			params.set("window", window);

			fetch(`/panel/api/monitoring/series?${params.toString()}`, {
				headers: {
					"X-Requested-With": "XMLHttpRequest",
					"X-CSRF-TOKEN": csrfToken(),
					Accept: "application/json",
				},
				credentials: "same-origin",
				signal: abortController.signal,
			})
				.then((r) => {
					if (!r.ok) {
						throw new Error(`HTTP ${r.status}`);
					}
					return r.json();
				})
				.then(resolve)
				.catch((err) => {
					if (err.name !== "AbortError") {
						reject(err);
					}
				});
		}, 200);
	});
}

export function clearServicesCache() {
	return fetch("/panel/api/monitoring/services/refresh", {
		method: "POST",
		headers: {
			"X-Requested-With": "XMLHttpRequest",
			"X-CSRF-TOKEN": csrfToken(),
			Accept: "application/json",
		},
		credentials: "same-origin",
	})
		.then((r) => r.json())
		.catch(() => ({ success: false }));
}
