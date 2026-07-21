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
 * `--nrllm-chart-*` custom properties defined in Analytics.css, which carry
 * light/dark values for both the OS preference and the explicit TYPO3
 * [data-color-scheme] backend toggle. On a scheme change the charts are
 * destroyed and re-rendered with the then-current palette.
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
    }

    /**
     * Resolve the scheme-dependent chart palette from the CSS custom
     * properties on the module wrapper (fallbacks mirror the light values
     * in Analytics.css).
     */
    readColors() {
        const scope = document.querySelector('.nrllm-analytics') || document.body;
        const style = globalThis.getComputedStyle(scope);
        const read = (name, fallback) => style.getPropertyValue(name).trim() || fallback;

        return {
            series1: read('--nrllm-chart-series-1', '#2f99a4'),
            series1Fill: read('--nrllm-chart-series-1-fill', 'rgba(47,153,164,0.15)'),
            series2: read('--nrllm-chart-series-2', '#e8a33d'),
            text: read('--nrllm-chart-text', '#495057'),
            grid: read('--nrllm-chart-grid', 'rgba(0,0,0,0.1)'),
        };
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
