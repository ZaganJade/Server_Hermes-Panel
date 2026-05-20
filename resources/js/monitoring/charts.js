import uPlot from "uplot";
import "uplot/dist/uPlot.min.css";

/**
 * uPlot configuration helpers themed to match the panel's editorial
 * dark palette (--ink / --paper / --copper). All callers pass their
 * own data + width; this module owns the look-and-feel.
 */
const COLORS = {
	axis: "#8a8275",
	grid: "rgba(244, 237, 225, 0.10)",
	copper: "#d4a45c",
	copperFill: "rgba(212, 164, 92, 0.10)",
	rust: "#b85c44",
	verdigris: "#5a7a5a",
	paper: "#f4ede1",
};

/**
 * Build a full-size line chart for the Monitoring tab.
 *
 * data shape: [tsArray, valueArray]
 */
export function createLineChart(container, data, opts = {}) {
	const width = container.clientWidth || 600;
	const height = opts.height ?? 200;

	const chart = new uPlot(
		{
			width,
			height,
			scales: {
				x: { time: true },
				y: { auto: true },
			},
			axes: [
				{ stroke: COLORS.axis, grid: { stroke: COLORS.grid } },
				{ stroke: COLORS.axis, grid: { stroke: COLORS.grid } },
			],
			cursor: { drag: { x: true, y: false } },
			series: [
				{},
				{
					stroke: opts.stroke ?? COLORS.copper,
					fill: opts.fill ?? COLORS.copperFill,
					width: 1.5,
					label: opts.label ?? "value",
				},
			],
		},
		data,
		container,
	);

	return chart;
}

/**
 * Build a tiny sparkline for the Dashboard health strip. No axes,
 * no labels — just a 80×32 line on a transparent background.
 */
export function createSparkline(container, data) {
	const width = container.clientWidth || 80;
	const height = 32;

	return new uPlot(
		{
			width,
			height,
			padding: [2, 2, 2, 2],
			scales: { x: { time: true }, y: { auto: true } },
			axes: [{ show: false }, { show: false }],
			cursor: { show: false },
			legend: { show: false },
			series: [
				{},
				{
					stroke: COLORS.copper,
					fill: COLORS.copperFill,
					width: 1,
				},
			],
		},
		data,
		container,
	);
}

export const monitoringTheme = COLORS;
