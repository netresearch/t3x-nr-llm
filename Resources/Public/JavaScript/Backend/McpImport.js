/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Triggers an MCP catalogue import from the MCP server module (ES6 Module).
 *
 * Event delegation on the body, the POST/reload flow from the shared
 * ModuleAction helper (AjaxRequest underneath, which injects TYPO3's CSRF
 * token). The button is disabled while the request is in flight, which is how
 * every other action in this backend reports that it is working.
 *
 * A partial import is reported rather than silently celebrated: an import that
 * skipped half a catalogue is a success by status and a problem in fact, and
 * the operator only finds out if the count is shown.
 */
import Notification from '@typo3/backend/notification.js';
import { postAndReload, resolveAjaxUrl } from '@netresearch/nr-llm/Backend/ModuleAction.js';

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
            const btn = e.target.closest('[data-nrllm-mcp-import]');
            if (!btn) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            this.handleImport(btn);
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
}

export default new McpImport();
