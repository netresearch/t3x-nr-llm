/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Admin tool-playground inspector (ES module).
 *
 * The operator picks a configuration, types a prompt, optionally force-injects
 * skills/snippets and overrides options, then runs (or dry-runs) the agent
 * loop. The runAction JSON carries the full run trace; this module renders a
 * summary strip, a step list of the nr_llm ↔ LLM dialog, and a detail pane
 * with Structured / Raw JSON / Messages-sent / Thinking tabs.
 *
 * Security: every server/LLM string is written via textContent (never
 * innerHTML); the final answer's HTML preview lives inside a fully sandboxed
 * iframe whose srcdoc is built from escapeHtml() output. State-changing AJAX
 * uses AjaxRequest, which injects TYPO3's CSRF token.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

import { escapeHtml } from '@netresearch/nr-llm/Backend/HtmlEscape.js';
import { readAjaxError } from '@netresearch/nr-llm/Backend/AjaxError.js';

class ToolPlayground {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        this.root = document.getElementById('nrllm-tool-playground');
        if (!this.root) {
            return;
        }

        this.route = this.root.dataset.ajaxRoute || '';
        this.configSelect = document.getElementById('nrllm-tool-config');
        this.promptInput = document.getElementById('nrllm-tool-prompt');
        this.runButton = document.getElementById('nrllm-tool-run');
        this.dryRunButton = document.getElementById('nrllm-tool-dryrun');
        this.output = document.getElementById('nrllm-tool-output');

        this.runButton?.addEventListener('click', () => this.run(false));
        this.dryRunButton?.addEventListener('click', () => this.run(true));
    }

    run(dryRun) {
        const url = TYPO3.settings.ajaxUrls[this.route];
        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const prompt = this.promptInput?.value || '';
        if (prompt.trim() === '') {
            Notification.warning('Prompt required', 'Please enter a prompt to run.');
            return;
        }

        const formData = new FormData();
        formData.append('configuration', this.configSelect?.value || '');
        formData.append('prompt', prompt);
        if (dryRun) {
            formData.append('dryRun', '1');
        }
        if (document.getElementById('nrllm-pg-raw')?.checked) {
            formData.append('captureRaw', '1');
        }
        const system = document.getElementById('nrllm-pg-system')?.value || '';
        if (system.trim() !== '') {
            formData.append('systemPrompt', system);
        }
        const rounds = document.getElementById('nrllm-pg-rounds')?.value || '';
        if (rounds !== '') {
            formData.append('maxRounds', rounds);
        }
        this.appendChecked(formData, '.js-tool-select', 'tools[]');
        this.appendChecked(formData, '.js-skill-select', 'forcedSkills[]');
        this.appendChecked(formData, '.js-snippet-select', 'forcedSnippets[]');

        this.setBusy(true);
        this.renderStatus(dryRun ? 'Assembling…' : 'Running…');

        new AjaxRequest(url)
            .post(formData)
            .then(response => response.resolve())
            .then(data => this.renderResult(data))
            .catch(async err => {
                const message = await readAjaxError(err);
                this.renderError(message);
                Notification.error('Error', message);
            })
            .finally(() => this.setBusy(false));
    }

    appendChecked(formData, selector, field) {
        this.root.querySelectorAll(`${selector}:checked`).forEach((checkbox) => {
            formData.append(field, checkbox.value);
        });
    }

    setBusy(busy) {
        if (this.runButton) {
            this.runButton.disabled = busy;
        }
        if (this.dryRunButton) {
            this.dryRunButton.disabled = busy;
        }
    }

    /**
     * Render the full inspector from the trace JSON. All dynamic values are
     * written via textContent / sandboxed iframe — never innerHTML.
     */
    renderResult(data) {
        this.clearOutput();
        if (!data || data.success === false) {
            this.renderError(data && data.error ? data.error : 'Unknown error');
            return;
        }
        if (!this.output) {
            return;
        }

        const steps = Array.isArray(data.steps) ? data.steps : [];

        this.output.appendChild(this.buildSummary(data, steps));

        const split = document.createElement('div');
        split.className = 'nrllm-pg-split';

        const list = document.createElement('div');
        list.className = 'nrllm-pg-steplist';
        const detail = document.createElement('div');
        detail.className = 'nrllm-pg-detail';

        steps.forEach((step, index) => {
            const row = this.buildStepRow(step, index);
            row.addEventListener('click', () => {
                list.querySelectorAll('.nrllm-pg-slrow').forEach(r => r.classList.remove('is-active'));
                row.classList.add('is-active');
                this.showDetail(detail, step, data);
            });
            list.appendChild(row);
        });

        split.appendChild(list);
        split.appendChild(detail);
        this.output.appendChild(split);

        // Select the last step (the final answer / assembled messages) by default.
        const rows = list.querySelectorAll('.nrllm-pg-slrow');
        if (rows.length > 0) {
            rows[rows.length - 1].click();
        } else {
            this.showEmptyDetail(detail);
        }
    }

    buildSummary(data, steps) {
        const strip = document.createElement('div');
        strip.className = 'nrllm-pg-summary';

        const llm = steps.filter(s => s.kind === 'llm');
        const tools = steps.filter(s => s.kind === 'tool').length;
        const wall = steps.reduce((sum, s) => sum + (Number(s.durationMs) || 0), 0);
        const usage = data.usage || {};
        const cost = usage.estimatedCost;

        const cells = [
            ['Rounds', String(llm.length)],
            ['Tool calls', String(tools)],
            ['Tokens', `${this.num(usage.totalTokens)} (${this.num(usage.promptTokens)}p / ${this.num(usage.completionTokens)}c)`],
            ['Est. cost', cost != null ? `$${Number(cost).toFixed(4)}` : '—'],
            ['Wall time', `${(wall / 1000).toFixed(2)}s`],
        ];
        cells.forEach(([k, v]) => strip.appendChild(this.cell(k, v)));

        const status = document.createElement('span');
        status.className = 'nrllm-pg-status';
        if (data.dryRun) {
            status.classList.add('is-dry');
            status.textContent = '⧉ Dry run — assembled, not sent';
        } else if (data.truncated) {
            status.classList.add('is-warn');
            status.textContent = '⚠ Truncated (cap or budget)';
        } else {
            status.classList.add('is-ok');
            status.textContent = '✓ Completed';
        }
        const wrap = document.createElement('div');
        wrap.className = 'nrllm-pg-status-cell';
        wrap.appendChild(status);
        strip.appendChild(wrap);

        return strip;
    }

    cell(key, value) {
        const c = document.createElement('div');
        c.className = 'nrllm-pg-cell';
        const k = document.createElement('div');
        k.className = 'nrllm-pg-cell-k';
        k.textContent = key;
        const v = document.createElement('div');
        v.className = 'nrllm-pg-cell-v';
        v.textContent = value;
        c.appendChild(k);
        c.appendChild(v);
        return c;
    }

    buildStepRow(step, index) {
        const row = document.createElement('div');
        row.className = 'nrllm-pg-slrow';

        const icon = document.createElement('span');
        icon.className = 'nrllm-pg-si';
        const title = document.createElement('div');
        title.className = 'nrllm-pg-st1';
        const sub = document.createElement('div');
        sub.className = 'nrllm-pg-st2';
        const metrics = document.createElement('div');
        metrics.className = 'nrllm-pg-sr';

        if (step.kind === 'assembled') {
            icon.classList.add('is-assembled');
            icon.textContent = '⧉';
            title.textContent = 'Assembled prompt';
            sub.textContent = `${(step.messagesSent || []).length} messages`;
        } else if (step.kind === 'tool') {
            icon.classList.add(step.toolIsError ? 'is-toolerr' : 'is-tool');
            icon.textContent = '⚙';
            title.textContent = step.toolName || '(tool)';
            sub.textContent = step.toolIsError ? 'error' : 'ok';
            metrics.textContent = this.ms(step.durationMs);
        } else {
            const hasCalls = Array.isArray(step.requestedToolCalls) && step.requestedToolCalls.length > 0;
            icon.classList.add(hasCalls ? 'is-req' : 'is-final');
            icon.textContent = hasCalls ? '⬇' : '✓';
            title.textContent = hasCalls ? `Round ${step.round} — requests tool` : `Round ${step.round} — answer`;
            sub.textContent = step.finishReason ? `finish: ${step.finishReason}` : '';
            metrics.textContent = `${this.ms(step.durationMs)} · ${this.num(step.totalTokens)}t`;
        }

        const body = document.createElement('div');
        body.className = 'nrllm-pg-sbody';
        body.appendChild(title);
        body.appendChild(sub);

        row.appendChild(icon);
        row.appendChild(body);
        row.appendChild(metrics);
        return row;
    }

    showDetail(detail, step, data) {
        detail.textContent = '';

        if (step.kind === 'tool') {
            detail.appendChild(this.detailHeader(`Tool · ${step.toolName || ''}`, this.ms(step.durationMs), step.toolIsError ? 'error' : 'ok'));
            const tabs = this.tabBox([
                ['Arguments', () => this.pre(this.json(step.toolArguments))],
                ['Result', () => this.pre(typeof step.toolResult === 'string' ? step.toolResult : '')],
            ]);
            detail.appendChild(tabs);
            return;
        }

        if (step.kind === 'assembled') {
            detail.appendChild(this.detailHeader('Assembled prompt (dry run)', '', ''));
            detail.appendChild(this.messagesList(step.messagesSent || []));
            return;
        }

        // llm
        const isFinal = !(Array.isArray(step.requestedToolCalls) && step.requestedToolCalls.length > 0);
        detail.appendChild(this.detailHeader(
            `Round ${step.round} — ${isFinal ? 'answer' : 'tool request'}`,
            `${this.ms(step.durationMs)} · ${this.num(step.totalTokens)} tok (${this.num(step.promptTokens)}p / ${this.num(step.completionTokens)}c)`,
            step.finishReason || '',
        ));

        const tabs = [
            ['Structured', () => this.structuredPane(step, data, isFinal)],
            ['Raw JSON', () => step.raw != null ? this.pre(this.json(step.raw)) : this.note('Not captured. Enable “Capture raw provider response”.')],
            ['Messages sent', () => this.messagesList(step.messagesSent || [])],
            ['Thinking', () => step.thinking?.trim() ? this.pre(step.thinking) : this.note('No reasoning returned for this round.')],
        ];
        detail.appendChild(this.tabBox(tabs));
    }

    structuredPane(step, data, isFinal) {
        const wrap = document.createElement('div');
        if (typeof step.content === 'string' && step.content !== '') {
            wrap.appendChild(this.pre(step.content));
        }
        (step.requestedToolCalls || []).forEach((call) => {
            const line = document.createElement('div');
            line.className = 'nrllm-pg-toolcall';
            const fn = document.createElement('span');
            fn.className = 'nrllm-pg-fn';
            fn.textContent = call.name || '(tool)';
            line.appendChild(fn);
            const args = document.createElement('span');
            args.textContent = ` (${this.json(call.arguments)})`;
            line.appendChild(args);
            wrap.appendChild(line);
        });
        if (isFinal) {
            wrap.appendChild(this.buildFinalAnswer(data.finalContent || step.content || ''));
        }
        return wrap;
    }

    messagesList(messages) {
        const wrap = document.createElement('div');
        wrap.className = 'nrllm-pg-mlist';
        messages.forEach((msg) => {
            const row = document.createElement('div');
            row.className = 'nrllm-pg-mrow';
            const head = document.createElement('div');
            head.className = 'nrllm-pg-mh';
            head.textContent = String(msg.role || '?');
            row.appendChild(head);
            const content = document.createElement('pre');
            content.className = 'nrllm-pg-mc';
            content.textContent = typeof msg.content === 'string' ? msg.content : this.json(msg);
            row.appendChild(content);
            wrap.appendChild(row);
        });
        if (messages.length === 0) {
            wrap.appendChild(this.note('No messages.'));
        }
        return wrap;
    }

    tabBox(tabs) {
        const box = document.createElement('div');
        const bar = document.createElement('div');
        bar.className = 'nrllm-pg-tabs';
        const pane = document.createElement('div');
        pane.className = 'nrllm-pg-tabpane';

        tabs.forEach(([label, build], i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'nrllm-pg-tab';
            btn.textContent = label;
            btn.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
            btn.addEventListener('click', () => {
                bar.querySelectorAll('.nrllm-pg-tab').forEach(b => b.setAttribute('aria-selected', 'false'));
                btn.setAttribute('aria-selected', 'true');
                pane.textContent = '';
                pane.appendChild(build());
            });
            bar.appendChild(btn);
        });

        box.appendChild(bar);
        box.appendChild(pane);
        if (tabs.length > 0) {
            pane.appendChild(tabs[0][1]());
        }
        return box;
    }

    detailHeader(titleText, metricText, finishText) {
        const head = document.createElement('div');
        head.className = 'nrllm-pg-dh';
        const t = document.createElement('span');
        t.className = 'nrllm-pg-dh-title';
        t.textContent = titleText;
        head.appendChild(t);
        if (metricText) {
            const m = document.createElement('span');
            m.className = 'nrllm-pg-dh-metric';
            m.textContent = metricText;
            head.appendChild(m);
        }
        if (finishText) {
            const f = document.createElement('span');
            f.className = 'nrllm-pg-dh-finish';
            f.textContent = `finish: ${finishText}`;
            head.appendChild(f);
        }
        return head;
    }

    /**
     * Final answer inside a fully sandboxed iframe (sandbox="") whose srcdoc is
     * built from escapeHtml() output.
     */
    buildFinalAnswer(finalContent) {
        const wrapper = document.createElement('div');
        wrapper.className = 'nrllm-pg-final';
        const heading = document.createElement('div');
        heading.className = 'nrllm-pg-final-h';
        heading.textContent = 'Final answer';
        wrapper.appendChild(heading);

        const iframe = document.createElement('iframe');
        iframe.sandbox = '';
        iframe.className = 'nrllm-pg-final-frame';
        iframe.srcdoc = [
            '<!DOCTYPE html><html><head><meta charset="utf-8"><style>',
            'body{font-family:system-ui,sans-serif;font-size:14px;padding:12px;margin:0;color:#333;line-height:1.5;white-space:pre-wrap;word-break:break-word}',
            '</style></head><body>',
            escapeHtml(finalContent),
            '</body></html>',
        ].join('');
        wrapper.appendChild(iframe);
        return wrapper;
    }

    pre(value) {
        const pre = document.createElement('pre');
        pre.className = 'nrllm-pg-pre';
        pre.textContent = typeof value === 'string' ? value : this.json(value);
        return pre;
    }

    note(text) {
        const p = document.createElement('p');
        p.className = 'nrllm-pg-note';
        p.textContent = text;
        return p;
    }

    showEmptyDetail(detail) {
        detail.appendChild(this.note('No steps recorded.'));
    }

    json(value) {
        if (value == null) {
            return '';
        }
        try {
            return JSON.stringify(value, null, 2);
        } catch {
            return String(value);
        }
    }

    num(value) {
        const n = Number(value);
        return Number.isFinite(n) ? n.toLocaleString('en-US') : '0';
    }

    ms(value) {
        const n = Number(value) || 0;
        return n >= 1000 ? `${(n / 1000).toFixed(2)}s` : `${Math.round(n)}ms`;
    }

    renderStatus(message) {
        this.clearOutput();
        if (!this.output) {
            return;
        }
        this.output.appendChild(this.note(message));
    }

    renderError(message) {
        this.clearOutput();
        if (!this.output) {
            return;
        }
        const p = document.createElement('p');
        p.className = 'nrllm-pg-error';
        p.textContent = message;
        this.output.appendChild(p);
    }

    clearOutput() {
        if (this.output) {
            this.output.textContent = '';
        }
    }
}

export default new ToolPlayground();
