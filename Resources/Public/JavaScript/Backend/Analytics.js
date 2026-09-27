/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * LLM Analytics dashboard charts (ES6 module).
 *
 * Reads the JSON payload embedded by the controller and renders a trend line
 * (cost + requests on dual axes) plus three breakdown bar charts. Chart.js is
 * provided as a global `window.Chart` by the vendored UMD build loaded via
 * PageRenderer::addJsFile (the UMD build auto-registers all chart types).
 *
 * Colours are not hardcoded: they are read at render time from the
 * `--nrllm-chart-*` custom properties defined in Analytics.css, which are
 * light-dark() pairs following the backend colour scheme. On a scheme change
 * the charts are destroyed and re-rendered with the then-current palette.
 */
class Analytics {
    constructor() {
        this.charts = [];
        this.data = {};
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        const el = document.getElementById('nrllm-analytics-data');
        if (!el || globalThis.Chart === undefined) {
            console.warn('[nrllm-analytics] data element or Chart.js not available');
            return;
        }

        try {
            this.data = JSON.parse(el.textContent || '{}');
        } catch (e) {
            console.error('[nrllm-analytics] failed to parse data', e);
            return;
        }

        this.render();
        this.observeSchemeChanges();
    }

    render() {
        for (const chart of this.charts) {
            chart.destroy();
        }
        this.charts = [];

        this.colors = this.readColors();
        this.renderTrend(this.data.trend || []);
        this.renderBreakdown('nrllm-provider-chart', this.data.byProvider || []);
        this.renderBreakdown('nrllm-model-chart', this.data.byModel || []);
        this.renderBreakdown('nrllm-service-chart', this.data.byService || []);
        this.renderBreakdown('nrllm-source-chart', this.data.bySource || []);
    }

    /**
     * Resolve the scheme-dependent chart palette from the CSS custom
     * properties on the module wrapper.
     *
     * A custom property is returned as written, so `getPropertyValue()` would
     * hand Chart.js the unevaluated `light-dark(…)` string. Assigning the
     * token to a real colour property and reading the computed value lets the
     * browser pick the branch for the active scheme and returns an rgb() value.
     */
    readColors() {
        const scope = document.querySelector('.nrllm-analytics') || document.body;
        const probe = document.createElement('span');
        probe.hidden = true;
        scope.appendChild(probe);
        const read = (name) => {
            probe.style.color = `var(${name})`;
            return globalThis.getComputedStyle(probe).color;
        };

        const colors = {
            series1: read('--nrllm-chart-series-1'),
            series1Fill: read('--nrllm-chart-series-1-fill'),
            series2: read('--nrllm-chart-series-2'),
            text: read('--nrllm-chart-text'),
            grid: read('--nrllm-chart-grid'),
        };
        probe.remove();

        return colors;
    }

    /**
     * Re-render when the backend colour scheme flips: either the explicit
     * TYPO3 toggle (data-color-scheme attribute) or the OS preference.
     */
    observeSchemeChanges() {
        const observer = new MutationObserver(() => this.render());
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-color-scheme'],
        });
        globalThis.matchMedia('(prefers-color-scheme: dark)')
            .addEventListener('change', () => this.render());
    }

    /**
     * Put the chart's numbers next to it as a visually hidden table. A canvas
     * is a picture; its role="img" label names it, and this table is where a
     * screen reader finds the values. Re-rendering replaces the table.
     */
    describeChart(canvas, headers, rows) {
        const holder = canvas.parentElement;
        holder.querySelector(':scope > table.visually-hidden')?.remove();
        const table = document.createElement('table');
        table.className = 'visually-hidden';
        const caption = document.createElement('caption');
        caption.textContent = canvas.getAttribute('aria-label') || '';
        table.appendChild(caption);
        const head = table.createTHead().insertRow();
        headers.forEach((text) => {
            const th = document.createElement('th');
            th.scope = 'col';
            th.textContent = text;
            head.appendChild(th);
        });
        const body = table.createTBody();
        rows.forEach((cells) => {
            const tr = body.insertRow();
            cells.forEach((value, i) => {
                const cell = document.createElement(i === 0 ? 'th' : 'td');
                if (i === 0) {
                    cell.scope = 'row';
                }
                cell.textContent = String(value ?? '');
                tr.appendChild(cell);
            });
        });
        holder.appendChild(table);
    }

    axisOptions() {
        return {
            ticks: { color: this.colors.text },
            grid: { color: this.colors.grid },
        };
    }

    renderTrend(trend) {
        const canvas = document.getElementById('nrllm-trend-chart');
        if (!canvas) {
            return;
        }
        this.describeChart(canvas, ['Date', 'Est. cost ($)', 'Requests'], trend.map((r) => [r.date, r.cost, r.requests]));
        this.charts.push(new globalThis.Chart(canvas, {
            type: 'line',
            data: {
                labels: trend.map((r) => r.date),
                datasets: [
                    {
                        label: 'Est. cost ($)',
                        data: trend.map((r) => r.cost),
                        borderColor: this.colors.series1,
                        backgroundColor: this.colors.series1Fill,
                        yAxisID: 'yCost',
                        tension: 0.25,
                        fill: true,
                    },
                    {
                        label: 'Requests',
                        data: trend.map((r) => r.requests),
                        borderColor: this.colors.series2,
                        yAxisID: 'yReq',
                        tension: 0.25,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { labels: { color: this.colors.text } } },
                scales: {
                    x: this.axisOptions(),
                    yCost: {
                        type: 'linear',
                        position: 'left',
                        title: { display: true, text: 'Cost ($)', color: this.colors.text },
                        ...this.axisOptions(),
                    },
                    yReq: {
                        type: 'linear',
                        position: 'right',
                        title: { display: true, text: 'Requests', color: this.colors.text },
                        ticks: { color: this.colors.text },
                        grid: { drawOnChartArea: false, color: this.colors.grid },
                    },
                },
            },
        }));
    }

    renderBreakdown(canvasId, rows) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            return;
        }
        this.describeChart(canvas, ['Name', 'Est. cost ($)'], rows.map((r) => [r.label, r.cost]));
        this.charts.push(new globalThis.Chart(canvas, {
            type: 'bar',
            data: {
                labels: rows.map((r) => r.label),
                datasets: [
                    {
                        label: 'Est. cost ($)',
                        data: rows.map((r) => r.cost),
                        backgroundColor: this.colors.series1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: this.axisOptions(),
                    y: this.axisOptions(),
                },
            },
        }));
    }
}

export default new Analytics();
