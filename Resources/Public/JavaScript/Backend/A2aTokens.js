/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * A2A token module (ADR-294): create a token for one's own account, generate
 * or rotate a technical client's token, revoke one.
 *
 * Each change is an AJAX POST through `post()` of ModuleAction.js, which uses
 * TYPO3's AjaxRequest and so carries the route token the core checks. The
 * token a response returns is written into one read-only field, shown once,
 * and removed again when the page is left or the panel is closed: it is never
 * put in a notification, the URL, the document title or browser storage.
 *
 * Keyboard and screen reader support: every action is a button, the panel that
 * shows the token takes focus when it opens and announces itself, and the form
 * error is a live alert.
 */
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';
import { post, resolveAjaxUrl } from '@netresearch/nr-llm/Backend/ModuleAction.js';

class A2aTokens {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        this.root = document.querySelector('.nrllm-a2a-tokens');
        if (!this.root) {
            return;
        }
        this.panel = this.root.querySelector('#nrllm-a2a-token-reveal');
        this.field = this.root.querySelector('#nrllm-a2a-token-value');

        this.root.addEventListener('click', (event) => {
            const issue = event.target.closest('.js-a2a-issue');
            if (issue) {
                event.preventDefault();
                this.confirmIssue(issue);
                return;
            }
            const revoke = event.target.closest('.js-a2a-revoke');
            if (revoke) {
                event.preventDefault();
                this.confirmRevoke(revoke);
                return;
            }
            if (event.target.closest('.js-a2a-copy')) {
                event.preventDefault();
                void this.copy();
                return;
            }
            if (event.target.closest('.js-a2a-done')) {
                event.preventDefault();
                this.close();
            }
        });

        const form = this.root.querySelector('#nrllm-a2a-token-create');
        if (form) {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                this.create(form);
            });
        }

        // Leave nothing behind in a page the browser keeps for Back/Forward.
        window.addEventListener('pagehide', () => this.clear());
    }

    label(name) {
        return this.root.dataset[name] || '';
    }

    confirmIssue(btn) {
        // A first token replaces nothing; a rotation ends the old token at once.
        if (btn.dataset.hasToken !== '1') {
            this.issue(btn);
            return;
        }
        Modal.confirm(
            this.label('labelRotateTitle'),
            `${this.label('labelRotateMessage')} ${btn.dataset.title || ''}`.trim(),
            Severity.warning,
            [
                { text: this.label('labelCancel'), btnClass: 'btn-default', trigger: (_e, modal) => modal.hideModal() },
                {
                    text: this.label('labelRotateButton'),
                    btnClass: 'btn-warning',
                    trigger: (_e, modal) => {
                        modal.hideModal();
                        this.issue(btn);
                    },
                },
            ],
        );
    }

    issue(btn) {
        const url = resolveAjaxUrl('nrllm_a2a_token_issue');
        if (!url || !this.beginIssuing()) {
            return;
        }
        const data = new FormData();
        data.append('uid', btn.dataset.uid);
        const release = () => this.endIssuing();
        post(url, data, btn, (response) => this.reveal(response.token), { onFailure: release, onError: release, reenableOnSuccess: false });
    }

    /**
     * One issuing at a time, and none while a token is on screen: a second
     * response would replace the token the first one showed, which is then
     * issued and lost. Every control that issues is disabled until the panel is
     * closed, or until a failed request releases them.
     */
    beginIssuing() {
        if (this.issuing || !this.panel.hidden) {
            return false;
        }
        this.issuing = true;
        this.setIssuingControls(true);
        return true;
    }

    endIssuing() {
        this.issuing = false;
        this.setIssuingControls(false);
    }

    setIssuingControls(disabled) {
        this.root.querySelectorAll('.js-a2a-issue, #nrllm-a2a-token-create button[type="submit"]').forEach((control) => {
            control.disabled = disabled;
        });
    }

    confirmRevoke(btn) {
        Modal.confirm(
            this.label('labelRevokeTitle'),
            `${this.label('labelRevokeMessage')} ${btn.dataset.title || ''}`.trim(),
            Severity.warning,
            [
                { text: this.label('labelCancel'), btnClass: 'btn-default', trigger: (_e, modal) => modal.hideModal() },
                {
                    text: this.label('labelRevokeButton'),
                    btnClass: 'btn-danger',
                    trigger: (_e, modal) => {
                        modal.hideModal();
                        this.revoke(btn);
                    },
                },
            ],
        );
    }

    revoke(btn) {
        const url = resolveAjaxUrl('nrllm_a2a_token_revoke');
        if (!url) {
            return;
        }
        const data = new FormData();
        data.append('uid', btn.dataset.uid);
        post(url, data, btn, () => location.reload(), { reenableOnSuccess: false });
    }

    create(form) {
        const url = resolveAjaxUrl('nrllm_a2a_token_create');
        if (!url) {
            return;
        }
        const error = form.querySelector('#nrllm-a2a-form-error');
        error.hidden = true;
        error.textContent = '';

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        if (!this.beginIssuing()) {
            return;
        }
        const submit = form.querySelector('button[type="submit"]');
        const data = new FormData(form);
        const refuse = (response) => {
            this.endIssuing();
            error.textContent = response.error || '';
            error.hidden = false;
            error.focus?.();
        };
        post(url, data, submit, (response) => {
            form.reset();
            this.reveal(response.token);
        }, { onFailure: refuse, onError: refuse, reenableOnSuccess: false });
    }

    reveal(token) {
        if (typeof token !== 'string' || token === '') {
            return;
        }
        this.field.value = token;
        this.panel.hidden = false;
        this.panel.scrollIntoView({ block: 'nearest' });
        this.panel.focus();
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.field.value);
            Notification.success(this.label('labelCopied'), '');
        } catch {
            // No clipboard permission: select the text so the user can copy it.
            this.field.select();
            Notification.warning(this.label('labelCopyFailed'), '');
        }
    }

    clear() {
        if (this.field) {
            this.field.value = '';
        }
    }

    close() {
        this.clear();
        location.reload();
    }
}

export default new A2aTokens();
