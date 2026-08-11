/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Triggers the two MCP server actions from the MCP server module (ES6 Module).
 *
 * Event delegation on the body, the POST/reload flow from the shared
 * ModuleAction helper (AjaxRequest underneath, which injects TYPO3's CSRF
 * token). The button is disabled while the request is in flight, which is how
 * every other action in this backend reports that it is working.
 *
 * A partial import is reported rather than silently celebrated: an import that
 * skipped half a catalogue is a success by status and a problem in fact, and
 * the operator only finds out if the count is shown.
 *
 * The connection test is the one action here that does NOT reload. Its answer
 * — the protocol revision the server chose and what the server calls itself —
 * is stored nowhere (ADR-154 decision 4), so it exists only in the response,
 * and a reload in the same tick would destroy it before it could be read. It
 * is written into the server's card instead, together with the refreshed
 * contact line, which is the only thing a reload would have brought.
 */
import Notification from '@typo3/backend/notification.js';
import { post, postAndReload, resolveAjaxUrl } from '@netresearch/nr-llm/Backend/ModuleAction.js';

class McpImport {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        document.body.addEventListener('click', (e) => {
            const importBtn = e.target.closest('[data-nrllm-mcp-import]');
            if (importBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleImport(importBtn);
                return;
            }

            const testBtn = e.target.closest('[data-nrllm-mcp-test]');
            if (testBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleTest(testBtn);
            }
        });
    }

    handleImport(btn) {
        const url = resolveAjaxUrl('nrllm_mcp_import');
        if (!url) {
            return;
        }

        const formData = new FormData();
        formData.append('server', btn.dataset.nrllmMcpImport);

        postAndReload(url, formData, btn, (data) => {
            const skipped = Number(data.skipped) || 0;
            const message = skipped > 0
                ? `${data.imported} imported, ${skipped} skipped`
                : `${data.imported} imported`;
            Notification.success('MCP import', message);
        });
    }

    handleTest(btn) {
        const url = resolveAjaxUrl('nrllm_mcp_test');
        if (!url) {
            return;
        }

        const uid = btn.dataset.nrllmMcpTest;
        const formData = new FormData();
        formData.append('server', uid);

        post(url, formData, btn, (data) => {
            // Composed and localised server-side. The report ends with what
            // the server wrote about itself, so it goes in as `textContent`:
            // remote text is never parsed as markup here.
            const report = document.querySelector(`[data-nrllm-mcp-report="${uid}"]`);
            if (report) {
                report.className = 'alert alert-success mb-2';
                report.textContent = data.report;
            }

            // The row said "never reached" until this handshake; leaving
            // either of these would contradict the report right above them.
            const contact = document.querySelector(`[data-nrllm-mcp-contact="${uid}"]`);
            if (contact) {
                contact.textContent = data.contact;
            }
            document.querySelector(`[data-nrllm-mcp-never="${uid}"]`)?.remove();
        });
    }
}

export default new McpImport();
