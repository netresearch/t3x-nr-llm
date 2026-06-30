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
 */
class Analytics {
    constructor() {
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

        let data;
        try {
            data = JSON.parse(el.textContent || '{}');
        } catch (e) {
            console.error('[nrllm-analytics] failed to parse data', e);
            return;
        }

        this.renderTrend(data.trend || []);
        this.renderBreakdown('nrllm-provider-chart', data.byProvider || []);
        this.renderBreakdown('nrllm-model-chart', data.byModel || []);
        this.renderBreakdown('nrllm-service-chart', data.byService || []);
    }

    renderTrend(trend) {
        const canvas = document.getElementById('nrllm-trend-chart');
        if (!canvas) {
            return;
        }
        new globalThis.Chart(canvas, {
            type: 'line',
            data: {
                labels: trend.map((r) => r.date),
                datasets: [
                    {
                        label: 'Est. cost ($)',
                        data: trend.map((r) => r.cost),
                        borderColor: '#2f99a4',
                        backgroundColor: 'rgba(47,153,164,0.15)',
                        yAxisID: 'yCost',
                        tension: 0.25,
                        fill: true,
                    },
                    {
                        label: 'Requests',
                        data: trend.map((r) => r.requests),
                        borderColor: '#e8a33d',
                        yAxisID: 'yReq',
                        tension: 0.25,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    yCost: { type: 'linear', position: 'left', title: { display: true, text: 'Cost ($)' } },
                    yReq: { type: 'linear', position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Requests' } },
                },
            },
        });
    }

    renderBreakdown(canvasId, rows) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            return;
        }
        new globalThis.Chart(canvas, {
            type: 'bar',
            data: {
                labels: rows.map((r) => r.label),
                datasets: [
                    {
                        label: 'Est. cost ($)',
                        data: rows.map((r) => r.cost),
                        backgroundColor: '#2f99a4',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
            },
        });
    }
}

export default new Analytics();
