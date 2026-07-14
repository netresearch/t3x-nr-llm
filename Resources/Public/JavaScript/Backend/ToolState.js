/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Tool state JavaScript module for the TYPO3 backend (ES6 Module).
 *
 * Toggles the global enable/disable override of a single agent tool from the
 * Tool Playground module. Event delegation on the body; the state-changing
 * POST/reload flow lives in the shared ModuleAction helper (AjaxRequest
 * underneath, which injects TYPO3's CSRF token automatically).
 */
import Notification from '@typo3/backend/notification.js';
import { postAndReload } from '@netresearch/nr-llm/Backend/ModuleAction.js';

class ToolState {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        document.body.addEventListener('click', (e) => {
            const groupBtn = e.target.closest('.js-toolgroup-toggle');
            if (groupBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleGroupToggle(groupBtn);
                return;
            }
            const toggleBtn = e.target.closest('.js-tool-toggle');
            if (toggleBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleToggle(toggleBtn);
            }
        });

        console.debug('[ToolState] Initialized with event delegation');
    }

    handleGroupToggle(btn) {
        const group = btn.dataset.group;
        const enabled = btn.dataset.enabled === '1' ? 0 : 1;
        const url = TYPO3.settings.ajaxUrls['nrllm_toolgroup_toggle'];
        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const formData = new FormData();
        formData.append('group', group);
        formData.append('enabled', String(enabled));

        postAndReload(url, formData, btn);
    }

    handleToggle(btn) {
        const tool = btn.dataset.tool;
        const enabled = btn.dataset.enabled === '1' ? 0 : 1;
        const url = TYPO3.settings.ajaxUrls['nrllm_tool_toggle'];
        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const formData = new FormData();
        formData.append('tool', tool);
        formData.append('enabled', String(enabled));

        postAndReload(url, formData, btn);
    }
}

export default new ToolState();
