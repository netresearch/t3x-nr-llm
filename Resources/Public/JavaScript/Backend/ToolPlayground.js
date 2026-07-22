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
 * summary strip, a step list of the nr_llm ↔ LLM dialog (request steps stream
 * in BEFORE the model answers), and a per-step detail pane.
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
        this.msgTruncated = this.root.dataset.msgTruncated || 'Response truncated — the model hit the max-tokens limit. Raise Max tokens in Advanced and run again.';
        this.msgRunning = this.root.dataset.msgRunning || 'Running…';
        this.msgWaiting = this.root.dataset.msgWaiting || 'Waiting for model…';
        this.msgRequest = this.root.dataset.msgRequest || 'Request';
        this.msgToolSpecs = this.root.dataset.msgToolspecs || 'Tools offered';
        this.msgMessagesSent = this.root.dataset.msgMessagessent || 'Messages sent';
        this.msgNoTools = this.root.dataset.msgNotools || 'No tools offered for this round.';
        this.msgNoMessages = this.root.dataset.msgNomessages || 'No messages.';
        this.msgAssembling = this.root.dataset.msgAssembling || 'Assembling…';
        this.configSelect = document.getElementById('nrllm-tool-config');
        this.promptInput = document.getElementById('nrllm-tool-prompt');
        this.runButton = document.getElementById('nrllm-tool-run');
        this.dryRunButton = document.getElementById('nrllm-tool-dryrun');
        this.output = document.getElementById('nrllm-tool-output');
        this.formToggle = document.getElementById('nrllm-pg-form-toggle');
        this.formBody = document.getElementById('nrllm-pg-form-body');
        this.formSummary = document.getElementById('nrllm-pg-form-summary');

        // Tool-group tri-state checkboxes: a group box (de)selects its
        // children; a child updates its group box (checked / indeterminate).
        this.root.addEventListener('change', (e) => {
            const groupBox = e.target.closest('.js-toolgroup-select');
            if (groupBox) {
                this.root.querySelectorAll(`.js-tool-select[data-group="${CSS.escape(groupBox.dataset.group)}"]`)
                    .forEach((box) => { box.checked = groupBox.checked; });
                groupBox.indeterminate = false;
                return;
            }
            const toolBox = e.target.closest('.js-tool-select');
            if (toolBox && toolBox.dataset.group) {
                this.syncGroupCheckbox(toolBox.dataset.group);
            }
        });
        this.root.querySelectorAll('.js-toolgroup-select')
            .forEach((box) => this.syncGroupCheckbox(box.dataset.group));

        this.runButton?.addEventListener('click', () => this.run(false));
        this.dryRunButton?.addEventListener('click', () => this.run(true));
        this.formToggle?.addEventListener('click', () => this.setFormCollapsed(this.formToggle.getAttribute('aria-expanded') === 'true'));
    }

    /**
     * Collapse or expand the run-configuration panel. Form state is kept — the
     * body is only hidden. When collapsed, the header shows a one-line summary
     * (configuration name + prompt excerpt) of what is running.
     */
    setFormCollapsed(collapsed) {
        if (!this.formToggle || !this.formBody) {
            return;
        }
        this.formToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        this.formBody.hidden = collapsed;
        if (this.formSummary) {
            if (collapsed) {
                const config = this.configSelect?.selectedOptions?.[0]?.textContent?.trim() || '';
                const prompt = (this.promptInput?.value || '').trim().replace(/\s+/g, ' ');
                const excerpt = prompt.length > 80 ? `${prompt.slice(0, 80)}…` : prompt;
                this.formSummary.textContent = [config, excerpt].filter(Boolean).join(' · ');
            } else {
                this.formSummary.textContent = '';
            }
        }
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

        const formData = this.buildFormData(dryRun, prompt);

        this.setBusy(true);
        this.renderStatus(dryRun ? this.msgAssembling : this.msgRunning);

        // Give the streaming inspector the room: collapse the form (state is
        // kept; the header re-expands it) and bring the inspector into view.
        this.setFormCollapsed(true);
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
        this.output?.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });

        // Live path: stream each step as it happens. Falls back to the batch
        // request when the browser can't read a streaming body.
        if (typeof fetch === 'function' && typeof ReadableStream === 'function') {
            formData.append('stream', '1');
            this.runStreaming(url, formData)
                .catch(async err => {
                    const message = err?.message || String(err) || 'Run failed';
                    this.renderError(message);
                    Notification.error('Error', message);
                })
                .finally(() => this.setBusy(false));
            return;
        }

        this.runBatch(url, formData);
    }

    buildFormData(dryRun, prompt) {
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
        this.appendIfSet(formData, 'nrllm-pg-rounds', 'maxRounds');
        this.appendIfSet(formData, 'nrllm-pg-maxtokens', 'maxTokens');
        this.appendIfSet(formData, 'nrllm-pg-temperature', 'temperature');
        this.appendIfSet(formData, 'nrllm-pg-think', 'think');
        this.appendChecked(formData, '.js-tool-select', 'tools[]');
        this.appendChecked(formData, '.js-skill-select', 'forcedSkills[]');
        this.appendChecked(formData, '.js-snippet-select', 'forcedSnippets[]');
        return formData;
    }

    appendIfSet(formData, elementId, field) {
        const value = document.getElementById(elementId)?.value ?? '';
        if (String(value).trim() !== '') {
            formData.append(field, value);
        }
    }

    /**
     * Read the NDJSON event stream and render each step as it arrives. A
     * `step` event appends to the live trace and re-renders; `done` finalises
     * the summary; `error` surfaces the real message.
     */
    async runStreaming(url, formData) {
        const response = await fetch(url, { method: 'POST', body: formData, headers: { Accept: 'application/x-ndjson' } });
        if (!response.ok || !response.body) {
            // Fallback: consume the whole body and treat it as a batch payload.
            const text = await response.text();
            let data = null;
            try { data = JSON.parse(text); } catch { /* not JSON */ }
            if (data) {
                this.safeRender(data);
            } else {
                this.renderError(`The server returned an error (HTTP ${response.status}).`);
            }
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        const live = { success: true, running: true, steps: [], usage: {}, finalContent: '', truncated: false, dryRun: false };
        let buffer = '';
        let sawTerminal = false;

        for (;;) {
            const { value, done } = await reader.read();
            if (done) {
                break;
            }
            buffer += decoder.decode(value, { stream: true });
            let newline;
            while ((newline = buffer.indexOf('\n')) >= 0) {
                const line = buffer.slice(0, newline).trim();
                buffer = buffer.slice(newline + 1);
                if (line === '') {
                    continue;
                }
                let event;
                try { event = JSON.parse(line); } catch { continue; }

                if (event.event === 'step' && event.step) {
                    live.steps.push(event.step);
                    this.safeRender(live);
                } else if (event.event === 'done') {
                    live.running = false;
                    live.finalContent = event.finalContent || '';
                    live.iterations = event.iterations;
                    live.truncated = !!event.truncated;
                    live.dryRun = !!event.dryRun;
                    live.usage = event.usage || {};
                    sawTerminal = true;
                    this.safeRender(live);
                } else if (event.event === 'error') {
                    sawTerminal = true;
                    const message = event.error || 'Run failed';
                    this.renderError(message);
                    Notification.error('Error', message);
                    return;
                }
            }
        }

        if (!sawTerminal) {
            // Stream ended without a done/error line — show whatever arrived.
            live.running = false;
            this.safeRender(live);
        }
    }

    runBatch(url, formData) {
        new AjaxRequest(url)
            .post(formData)
            .then(response => response.resolve())
            .then(data => this.safeRender(data))
            .catch(async err => {
                const message = await readAjaxError(err);
                this.renderError(message);
                Notification.error('Error', message);
            })
            .finally(() => this.setBusy(false));
    }

    /**
     * Render, keeping a rendering exception out of any AJAX catch (whose
     * readAjaxError only understands transport errors and would mask a
     * client-side bug as a bare "Unknown error").
     */
    safeRender(data) {
        try {
            this.renderResult(data);
        } catch (e) {
            const message = `Could not render the run result: ${e?.message || String(e)}`;
            this.renderError(message);
            Notification.error('Error', message);
        }
    }

    /**
     * Reflect the children's state onto a group checkbox: all checked ->
     * checked, none -> unchecked, mixed -> indeterminate.
     */
    syncGroupCheckbox(group) {
        const groupBox = this.root.querySelector(`.js-toolgroup-select[data-group="${CSS.escape(group)}"]`);
        if (!groupBox) {
            return;
        }
        const children = [...this.root.querySelectorAll(`.js-tool-select[data-group="${CSS.escape(group)}"]`)];
        const checked = children.filter((box) => box.checked).length;
        groupBox.checked = checked > 0 && checked === children.length;
        groupBox.indeterminate = checked > 0 && checked < children.length;
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

        // A model round that stopped on the token limit was cut off mid-answer;
        // say so plainly rather than showing a silent half-sentence.
        if (steps.some(s => s.kind === 'llm' && s.finishReason === 'length')) {
            this.output.appendChild(this.buildTruncationBanner());
        }

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

        // A request whose response has not arrived yet: the model is working —
        // show a live waiting row so the stream never looks stalled.
        if (data.running && steps.length > 0 && steps[steps.length - 1].kind === 'request') {
            list.appendChild(this.buildWaitingRow());
        }

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
        // While streaming, the summed usage arrives only with the final `done`
        // event, so fall back to summing the per-round token counts so far.
        const sumTokens = key => llm.reduce((s, x) => s + (Number(x[key]) || 0), 0);
        const total = usage.totalTokens != null ? usage.totalTokens : sumTokens('totalTokens');
        const promptT = usage.promptTokens != null ? usage.promptTokens : sumTokens('promptTokens');
        const completionT = usage.completionTokens != null ? usage.completionTokens : sumTokens('completionTokens');

        const cells = [
            ['Rounds', String(llm.length)],
            ['Tool calls', String(tools)],
            ['Tokens', `${this.num(total)} (${this.num(promptT)}p / ${this.num(completionT)}c)`],
            ['Est. cost', cost != null ? `$${Number(cost).toFixed(4)}` : '—'],
            ['Wall time', `${(wall / 1000).toFixed(2)}s`],
        ];
        cells.forEach(([k, v]) => strip.appendChild(this.cell(k, v)));

        const status = document.createElement('span');
        status.className = 'nrllm-pg-status';
        if (data.running) {
            status.classList.add('is-run');
            status.textContent = `● ${this.msgRunning}`;
        } else if (data.dryRun) {
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

        if (step.kind === 'request') {
            icon.classList.add('is-out');
            icon.textContent = '⬆';
            title.textContent = `${this.msgRequest} · round ${step.round}`;
            const tools = (step.toolSpecs || []).length;
            sub.textContent = `${(step.messagesSent || []).length} messages · ${tools} tools`;
        } else if (step.kind === 'assembled') {
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
            const tabs = [
                ['Arguments', () => this.pre(this.json(step.toolArguments))],
                ['Result', () => this.pre(typeof step.toolResult === 'string' ? step.toolResult : '')],
            ];
            // ADR-108: run-only artifacts, shown only when present. Never
            // provider-facing; hardcoded label matches the sibling tab literals.
            if (Array.isArray(step.toolArtifacts) && step.toolArtifacts.length > 0) {
                tabs.push(['Artifacts', () => this.artifacts(step.toolArtifacts)]);
            }
            detail.appendChild(this.tabBox(tabs));
            return;
        }

        if (step.kind === 'request') {
            detail.appendChild(this.detailHeader(
                `${this.msgRequest} · round ${step.round}`,
                `${(step.messagesSent || []).length} messages · ${(step.toolSpecs || []).length} tools`,
                '',
            ));
            detail.appendChild(this.tabBox([
                [this.msgMessagesSent, () => this.messagesList(step.messagesSent || [])],
                [this.msgToolSpecs, () => (step.toolSpecs || []).length > 0
                    ? this.pre((step.toolSpecs || []).join('\n'))
                    : this.note(this.msgNoTools)],
            ]));
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

        // The messages sent live on the round's request step, not here.
        const tabs = [
            ['Structured', () => this.structuredPane(step, data, isFinal)],
            ['Raw JSON', () => step.raw != null ? this.pre(this.json(step.raw)) : this.note('Not captured. Enable “Capture raw provider response”.')],
            ['Thinking', () => step.thinking?.trim() ? this.pre(step.thinking) : this.note('No reasoning returned for this round.')],
        ];
        detail.appendChild(this.tabBox(tabs));
    }

    /**
     * Render a step's run-only artifacts (ADR-108). NEVER provider-facing; every
     * value is written via textContent (never innerHTML) since artifacts carry
     * attacker-influenceable tool bytes. An unknown type (a newer backend, or a
     * REDACTED-masked discriminator) degrades to a JSON dump.
     */
    artifacts(list) {
        const wrap = document.createElement('div');
        (Array.isArray(list) ? list : []).forEach((a) => {
            const box = document.createElement('div');
            box.className = 'nrllm-pg-artifact';
            const label = document.createElement('div');
            label.className = 'nrllm-pg-artifact-label';
            label.textContent = (a && typeof a.label === 'string') ? a.label : 'Artifact';
            box.appendChild(label);
            const data = (a && a.data && typeof a.data === 'object') ? a.data : {};
            if (a && a.type === 'table' && Array.isArray(data.columns) && Array.isArray(data.rows)) {
                box.appendChild(this.artifactTable(data.columns, data.rows));
            } else if (a && a.type === 'text') {
                box.appendChild(this.pre(typeof data.text === 'string' ? data.text : ''));
            } else {
                box.appendChild(this.pre(this.json(a)));
            }
            wrap.appendChild(box);
        });
        return wrap;
    }

    artifactTable(columns, rows) {
        const table = document.createElement('table');
        table.className = 'nrllm-pg-artifact-table';
        const thead = document.createElement('thead');
        const htr = document.createElement('tr');
        columns.forEach((col) => {
            const th = document.createElement('th');
            th.textContent = typeof col === 'string' ? col : String(col);
            htr.appendChild(th);
        });
        thead.appendChild(htr);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        (Array.isArray(rows) ? rows : []).forEach((row) => {
            const tr = document.createElement('tr');
            (Array.isArray(row) ? row : []).forEach((cell) => {
                const td = document.createElement('td');
                td.textContent = typeof cell === 'string' ? cell : String(cell);
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        return table;
    }

    /**
     * Live row shown while a request is out and the model has not answered yet.
     * The text label carries the state (the pulse dot is decorative only).
     */
    buildWaitingRow() {
        const row = document.createElement('div');
        row.className = 'nrllm-pg-waiting';
        row.setAttribute('role', 'status');
        const dot = document.createElement('span');
        dot.className = 'nrllm-pg-waiting-dot';
        dot.setAttribute('aria-hidden', 'true');
        const text = document.createElement('span');
        text.textContent = this.msgWaiting;
        row.appendChild(dot);
        row.appendChild(text);
        return row;
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
            wrap.appendChild(this.note(this.msgNoMessages));
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

    buildTruncationBanner() {
        const banner = document.createElement('div');
        banner.className = 'nrllm-pg-banner is-warn';
        banner.setAttribute('role', 'status');
        const icon = document.createElement('span');
        icon.className = 'nrllm-pg-banner-ico';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '⚠';
        const text = document.createElement('span');
        text.textContent = this.msgTruncated;
        banner.appendChild(icon);
        banner.appendChild(text);
        return banner;
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
