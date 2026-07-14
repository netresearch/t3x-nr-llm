/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Skill list JavaScript module for TYPO3 backend (ES6 Module).
 *
 * Handles AJAX sync of skill sources, enable/disable toggling of ingested
 * skills, and storing a GitHub token (as a vault UUID) for a source.
 *
 * Uses TYPO3 Backend Notification API and event delegation for reliable
 * handling inside TYPO3 v14+ iframe modules. State-changing AJAX uses
 * AjaxRequest, which injects TYPO3's CSRF token.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { readAjaxError } from '@netresearch/nr-llm/Backend/AjaxError.js';
import { postAndReload, resolveAjaxUrl } from '@netresearch/nr-llm/Backend/ModuleAction.js';

class SkillList {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        document.body.addEventListener('click', (e) => {
            const syncBtn = e.target.closest('.js-skill-sync');
            if (syncBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleSync(syncBtn);
                return;
            }

            const toggleBtn = e.target.closest('.js-skill-toggle');
            if (toggleBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleToggle(toggleBtn);
                return;
            }

            const tokenBtn = e.target.closest('.js-skill-token');
            if (tokenBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleSetToken(tokenBtn);
            }
        });

        console.debug('[SkillList] Initialized with event delegation');
    }

    handleSync(btn) {
        const source = btn.dataset.source;
        const url = resolveAjaxUrl('nrllm_skill_sync');
        if (!url) {
            return;
        }

        const formData = new FormData();
        formData.append('source', source);

        postAndReload(url, formData, btn, (data) => {
            Notification.success(
                'Sync complete',
                `Status: ${data.status}, created: ${data.created}, updated: ${data.updated}, auto-disabled: ${data.disabledOnChange}, orphaned: ${data.orphaned}`
            );
        });
    }

    handleToggle(btn) {
        const skill = btn.dataset.skill;
        const enabled = btn.dataset.enabled === '1' ? 0 : 1;
        const url = resolveAjaxUrl('nrllm_skill_toggle');
        if (!url) {
            return;
        }

        const formData = new FormData();
        formData.append('skill', skill);
        formData.append('enabled', String(enabled));

        postAndReload(url, formData, btn);
    }

    handleSetToken(btn) {
        const source = btn.dataset.source;
        const url = resolveAjaxUrl('nrllm_skill_token');
        if (!url) {
            return;
        }

        const token = globalThis.prompt('Enter a GitHub access token for this source (stored encrypted via vault):');
        if (token === null) {
            return;
        }

        const formData = new FormData();
        formData.append('source', source);
        formData.append('token', token);

        new AjaxRequest(url)
            .post(formData)
            .then(response => response.resolve())
            .then(data => {
                if (data.success) {
                    Notification.success('Token stored', 'The GitHub token was stored securely.');
                } else {
                    Notification.error('Error', data.error || 'Unknown error');
                }
            })
            .catch(async err => {
                Notification.error('Error', await readAjaxError(err));
            });
    }
}

export default new SkillList();
