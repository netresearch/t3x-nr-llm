/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Tool playground JavaScript module for the TYPO3 backend (ES6 Module).
 *
 * Renders the admin-only tool playground: the operator picks an LLM
 * configuration, types a prompt and runs the bounded agent loop. The AJAX
 * runAction returns the trace as JSON; this module renders each tool call,
 * its arguments, its result and the final answer.
 *
 * Security: tool arguments, tool results and the final answer are untrusted
 * (results can contain sys_log content). Every server/LLM string is written
 * via textContent (which never interprets HTML) and the final answer's HTML
 * preview lives inside a fully sandboxed iframe whose srcdoc is built from
 * escapeHtml() output. This module never assigns a server/LLM string to
 * innerHTML.
 *
 * State-changing AJAX uses AjaxRequest, which injects TYPO3's CSRF token.
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
        this.output = document.getElementById('nrllm-tool-output');

        this.runButton?.addEventListener('click', () => this.run());

        console.debug('[ToolPlayground] Initialized');
    }

    run() {
        const url = TYPO3.settings.ajaxUrls[this.route];
        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const configuration = this.configSelect?.value || '';
        const prompt = this.promptInput?.value || '';
        if (prompt.trim() === '') {
            Notification.warning('Prompt required', 'Please enter a prompt to run.');
            return;
        }

        const formData = new FormData();
        formData.append('configuration', configuration);
        formData.append('prompt', prompt);

        // Restrict this run to the ticked tools. When none are ticked, the
        // `tools` key is omitted and the server defaults to the enabled set.
        this.root.querySelectorAll('.js-tool-select:checked').forEach((checkbox) => {
            formData.append('tools[]', checkbox.value);
        });

        if (this.runButton) {
            this.runButton.disabled = true;
        }
        this.renderStatus('Running…');

        new AjaxRequest(url)
            .post(formData)
            .then(response => response.resolve())
            .then(data => this.renderResult(data))
            .catch(async err => {
                const message = await readAjaxError(err);
                this.renderError(message);
                Notification.error('Error', message);
            })
            .finally(() => {
                if (this.runButton) {
                    this.runButton.disabled = false;
                }
            });
    }

    /**
     * Render the JSON trace XSS-safely. All dynamic values are written via
     * textContent / sandboxed iframe srcdoc — never innerHTML.
     *
     * @param {object} data - The runAction JSON payload.
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

        const body = document.createElement('div');
        body.className = 'card-body';

        body.appendChild(this.buildMeta(data));

        const trace = Array.isArray(data.trace) ? data.trace : [];
        trace.forEach((invocation, index) => {
            body.appendChild(this.buildTraceCard(invocation, index));
        });

        body.appendChild(this.buildFinalAnswer(data.finalContent || ''));

        this.output.appendChild(body);
    }

    /**
     * Build the meta line (iterations, truncated flag, total tokens).
     */
    buildMeta(data) {
        const meta = document.createElement('p');
        meta.className = 'text-body-secondary small mb-3';
        const tokens = data.usage && data.usage.totalTokens != null ? data.usage.totalTokens : '-';
        const parts = [
            `Iterations: ${Number(data.iterations) || 0}`,
            `Truncated: ${data.truncated ? 'yes' : 'no'}`,
            `Total tokens: ${tokens}`,
        ];
        meta.textContent = parts.join(' · ');
        return meta;
    }

    /**
     * Build one trace card: tool name, escaped argument JSON, escaped result,
     * and an error badge when the tool reported a failure.
     */
    buildTraceCard(invocation, index) {
        const card = document.createElement('div');
        card.className = 'card mb-2';

        const header = document.createElement('div');
        header.className = 'card-header d-flex justify-content-between align-items-center';

        const name = document.createElement('code');
        // textContent never interprets HTML — safe for the untrusted tool name.
        name.textContent = `#${index + 1} ${invocation && invocation.name ? invocation.name : '(unknown)'}`;
        header.appendChild(name);

        if (invocation && invocation.isError) {
            const badge = document.createElement('span');
            badge.className = 'badge bg-danger';
            badge.textContent = 'error';
            header.appendChild(badge);
        }
        card.appendChild(header);

        const cardBody = document.createElement('div');
        cardBody.className = 'card-body';

        cardBody.appendChild(this.labelledPre('Arguments', this.stringifyArguments(invocation && invocation.arguments)));
        cardBody.appendChild(this.labelledPre('Result', invocation && typeof invocation.result === 'string' ? invocation.result : ''));

        card.appendChild(cardBody);
        return card;
    }

    /**
     * A small label + a <pre> whose textContent is the (untrusted) value.
     * textContent is the XSS-safe primitive: the browser never parses it as
     * markup, so no escapeHtml round-trip is needed (and combining the two
     * would double-escape).
     */
    labelledPre(label, value) {
        const wrapper = document.createElement('div');
        wrapper.className = 'mb-2';

        const heading = document.createElement('strong');
        heading.className = 'd-block small text-body-secondary';
        heading.textContent = label;
        wrapper.appendChild(heading);

        const pre = document.createElement('pre');
        pre.style.cssText = 'margin:0;white-space:pre-wrap;word-break:break-word';
        pre.textContent = value;
        wrapper.appendChild(pre);

        return wrapper;
    }

    /**
     * Pretty-print the tool arguments object as JSON.
     */
    stringifyArguments(args) {
        if (args == null) {
            return '';
        }
        try {
            return JSON.stringify(args, null, 2);
        } catch {
            return String(args);
        }
    }

    /**
     * Render the final answer inside a fully sandboxed iframe (sandbox="")
     * whose srcdoc is built from escapeHtml() output. This mirrors
     * TaskExecute.js's HTML preview: the untrusted answer is escaped before
     * the HTML document string is assembled, and the empty sandbox attribute
     * blocks script execution and access to the parent DOM.
     */
    buildFinalAnswer(finalContent) {
        const wrapper = document.createElement('div');
        wrapper.className = 'mt-3';

        const heading = document.createElement('h3');
        heading.className = 'h5';
        heading.textContent = 'Final answer';
        wrapper.appendChild(heading);

        const iframe = document.createElement('iframe');
        iframe.sandbox = '';
        iframe.style.cssText = 'width:100%;height:240px;border:1px solid #ddd;border-radius:4px;background:#fff;';
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

    /**
     * Show a transient status message (e.g. while the loop runs).
     */
    renderStatus(message) {
        this.clearOutput();
        if (!this.output) {
            return;
        }
        const body = document.createElement('div');
        body.className = 'card-body text-body-secondary';
        body.textContent = message;
        this.output.appendChild(body);
    }

    /**
     * Show an error message via textContent (never innerHTML).
     */
    renderError(message) {
        this.clearOutput();
        if (!this.output) {
            return;
        }
        const body = document.createElement('div');
        body.className = 'card-body text-danger';
        body.textContent = message;
        this.output.appendChild(body);
    }

    clearOutput() {
        if (this.output) {
            this.output.textContent = '';
        }
    }
}

export default new ToolPlayground();
