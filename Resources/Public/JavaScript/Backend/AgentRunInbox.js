/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Agent Runs approvals inbox — progressive enhancement only (ADR-109, ADR-276).
 *
 * The page is fully operable without this module (native <form> POST, native
 * <details>, native form validation). It does two things on top:
 *
 * - On a 422 re-render it moves focus to the error summary, which `autofocus`
 *   on a non-control element does not do reliably across browsers.
 * - It submits a decision by fetch and asks for NDJSON (ADR-276). The server
 *   continues the run in that request and sends a line per step, so a reverse
 *   proxy's read timeout does not cut a long continuation off. The lines carry a
 *   step count only. When the server says `settled`, or answers with its
 *   redirect, the list is loaded, and it shows the outcome as a flash message,
 *   as after a native POST. A 422 is answered by submitting the same form
 *   natively, so the server's own page shows the error: an invalid input is
 *   refused before anything is claimed (ADR-105), so the second submission has
 *   no side effect.
 */
class AgentRunInbox {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        // On a 422 re-render exactly one error summary is present. Move focus to
        // it so the failure is announced and the operator's caret is at the
        // problem, not scrolled off at the document top.
        const summary = document.querySelector('[id^="input-errors-"]');
        if (summary instanceof HTMLElement) {
            // A frame yields to the browser's own initial focus handling first.
            window.requestAnimationFrame(() => summary.focus());
        }

        const root = document.querySelector('[data-nrllm-list-url]');
        if (!(root instanceof HTMLElement) || root.dataset.nrllmListUrl === '' || !this.canStream()) {
            return;
        }

        document.querySelectorAll('form[data-nrllm-continuation]').forEach((form) => {
            form.addEventListener('submit', (event) => this.onSubmit(event, form, root));
        });
    }

    canStream() {
        return typeof fetch === 'function' && typeof ReadableStream === 'function' && typeof TextDecoder === 'function';
    }

    onSubmit(event, form, root) {
        if (form.dataset.nrllmNative === '1' || event.defaultPrevented) {
            return;
        }
        event.preventDefault();

        // FormData(form) leaves out the button that submitted it; Approve and
        // Deny differ only in that button's value.
        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        const data = new FormData(form);
        if (submitter && submitter.name) {
            data.append(submitter.name, submitter.value);
        }

        // One decision per click: a second one would only meet the claim the
        // first holds.
        this.setBusy(form, true);
        const status = form.querySelector('[data-nrllm-continuation-status]');
        this.say(status, root.dataset.msgContinuing || '');

        this.send(form, data, submitter, root, status).catch(() => this.toList(root));
    }

    async send(form, data, submitter, root, status) {
        const response = await fetch(form.action, {
            method: 'POST',
            body: data,
            headers: { Accept: 'application/x-ndjson' },
            credentials: 'same-origin',
            // The redirect is followed by loading the list, not by fetch: a
            // fetch would render the list, and with it consume the flash
            // message the redirect was for.
            redirect: 'manual',
        });

        if (response.type === 'opaqueredirect') {
            this.toList(root);
            return;
        }

        if (response.status === 422) {
            this.setBusy(form, false);
            this.say(status, '');
            form.dataset.nrllmNative = '1';
            form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            return;
        }

        const type = response.headers.get('Content-Type') || '';
        if (!response.ok || !response.body || !type.includes('application/x-ndjson')) {
            this.toList(root);
            return;
        }

        await this.read(response.body, (event) => {
            if (event.event === 'progress' && Number.isInteger(event.steps)) {
                this.say(status, (root.dataset.msgContinuingSteps || '').replace('%d', String(event.steps)));
            }
        });

        // Settled, or the stream was cut: the run ends on the server either
        // way, and the list shows where it stands.
        this.toList(root);
    }

    async read(body, onEvent) {
        const reader = body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        for (;;) {
            const { value, done } = await reader.read();
            if (done) {
                return;
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
                try {
                    event = JSON.parse(line);
                } catch {
                    continue;
                }
                if (event.event === 'settled') {
                    return;
                }
                onEvent(event);
            }
        }
    }

    setBusy(form, busy) {
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
        form.querySelectorAll('button, input[type="submit"]').forEach((button) => {
            button.disabled = busy;
        });
    }

    say(status, text) {
        // The live region stays in the page and only its text changes: a region
        // that appears together with its text is not reliably announced.
        if (status instanceof HTMLElement) {
            status.textContent = text;
        }
    }

    toList(root) {
        window.location.assign(root.dataset.nrllmListUrl);
    }
}

export default new AgentRunInbox();
