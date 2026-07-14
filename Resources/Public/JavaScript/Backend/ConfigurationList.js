/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Configuration list JavaScript module for TYPO3 backend (ES6 Module).
 *
 * Uses TYPO3 Backend Notification API and Modal.
 * Uses event delegation for reliable event handling in TYPO3 v14+ iframe modules.
 * State-changing AJAX uses AjaxRequest, which injects TYPO3's CSRF token.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import { readAjaxError } from '@netresearch/nr-llm/Backend/AjaxError.js';
import { postAndReload, postUidAndReload, resolveAjaxUrl } from '@netresearch/nr-llm/Backend/ModuleAction.js';
import { escapeHtml } from '@netresearch/nr-llm/Backend/HtmlEscape.js';

class ConfigurationList {
    constructor() {
        // Wait for DOM to be ready before initializing
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        // Use event delegation - attach to document body for reliable handling
        // This works even if elements are added to DOM after script initialization
        document.body.addEventListener('click', (e) => {
            // Toggle active status
            const toggleBtn = e.target.closest('.js-toggle-active');
            if (toggleBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleToggleActive(toggleBtn);
                return;
            }

            // Set as default
            const defaultBtn = e.target.closest('.js-set-default');
            if (defaultBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleSetDefault(defaultBtn);
                return;
            }

            // Test configuration
            const testBtn = e.target.closest('.js-test-config');
            if (testBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleTestConfig(testBtn);
                return;
            }

            // Import a pending configuration preset (ADR-056)
            const importBtn = e.target.closest('.js-import-preset');
            if (importBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleImportPreset(importBtn);
                return;
            }

            // Review + apply a drifted preset update (ADR-056)
            const reviewBtn = e.target.closest('.js-review-preset-update');
            if (reviewBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleReviewPresetUpdate(reviewBtn);
            }
        });

        console.debug('[ConfigurationList] Initialized with event delegation');
    }

    handleToggleActive(btn) {
        postUidAndReload('nrllm_config_toggle_active', btn);
    }

    handleSetDefault(btn) {
        postUidAndReload('nrllm_config_set_default', btn);
    }

    handleImportPreset(btn) {
        const identifier = btn.dataset.identifier;
        const url = resolveAjaxUrl('nrllm_preset_import');

        if (!url) {
            return;
        }

        const formData = new FormData();
        formData.append('identifier', identifier);

        postAndReload(url, formData, btn);
    }

    handleReviewPresetUpdate(btn) {
        const identifier = btn.dataset.identifier;
        const name = btn.dataset.name || 'Configuration';
        const diffUrl = TYPO3.settings.ajaxUrls['nrllm_preset_diff'];
        const updateUrl = TYPO3.settings.ajaxUrls['nrllm_preset_update'];

        if (!diffUrl || !updateUrl) {
            Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        new AjaxRequest(diffUrl)
            .withQueryArguments({ identifier })
            .get()
            .then(response => response.resolve())
            .then(data => {
                if (!data.success) {
                    Notification.error('Error', data.error || 'Unknown error');
                    return;
                }
                this.openPresetUpdateModal(name, identifier, data.changes || [], updateUrl);
            })
            .catch(async err => {
                Notification.error('Error', await readAjaxError(err));
            });
    }

    openPresetUpdateModal(name, identifier, changes, updateUrl) {
        Modal.advanced({
            title: `Review preset update: ${name}`,
            content: this.buildPresetDiffContent(changes),
            severity: Severity.warning,
            size: Modal.sizes.medium,
            buttons: [
                {
                    text: 'Cancel',
                    btnClass: 'btn-default',
                    trigger: () => Modal.dismiss(),
                },
                {
                    text: 'Apply update',
                    btnClass: 'btn-warning',
                    trigger: () => this.applyPresetUpdate(identifier, updateUrl),
                },
            ],
        });
    }

    /**
     * Build the diff table as a DOM element. Every declared/current value is a
     * text node (textContent), so untrusted preset content cannot execute as
     * markup.
     */
    buildPresetDiffContent(changes) {
        const container = document.createElement('div');

        const intro = document.createElement('p');
        intro.textContent = 'Applying this update overwrites the current record values shown below with the '
            + 'declared values. Your activation, default flag, backend-group assignment and fallback chain are preserved.';
        container.appendChild(intro);

        if (changes.length === 0) {
            const note = document.createElement('p');
            note.className = 'text-body-secondary mb-0';
            note.textContent = 'The declaration changed but no record field differs; applying will clear the change hint.';
            container.appendChild(note);
            return container;
        }

        const wrap = document.createElement('div');
        wrap.className = 'table-fit mb-0';
        const table = document.createElement('table');
        table.className = 'table table-striped';

        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        ['Field', 'Current value', 'Declared value'].forEach(label => {
            const th = document.createElement('th');
            th.scope = 'col';
            th.textContent = label;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        changes.forEach(change => {
            const row = document.createElement('tr');

            const fieldCell = document.createElement('td');
            const code = document.createElement('code');
            code.textContent = change.field;
            fieldCell.appendChild(code);
            row.appendChild(fieldCell);

            [change.current, change.declared].forEach(value => {
                const cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
            });

            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        container.appendChild(wrap);

        return container;
    }

    applyPresetUpdate(identifier, updateUrl) {
        const formData = new FormData();
        formData.append('identifier', identifier);

        new AjaxRequest(updateUrl)
            .post(formData)
            .then(response => response.resolve())
            .then(data => {
                if (data.success) {
                    Modal.dismiss();
                    location.reload();
                } else {
                    Notification.error('Error', data.error || 'Unknown error');
                }
            })
            .catch(async err => {
                Notification.error('Error', await readAjaxError(err));
            });
    }

    handleTestConfig(btn) {
        const uid = btn.dataset.uid;
        const name = btn.dataset.name || 'Configuration';
        const url = TYPO3.settings.ajaxUrls['nrllm_config_test'];

        console.debug('[ConfigurationList] Test config clicked for UID:', uid, 'URL:', url);

        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            console.error('[ConfigurationList] AJAX URL not found. TYPO3.settings.ajaxUrls:', TYPO3.settings?.ajaxUrls);
            return;
        }

        // Show modal with loading state using TYPO3 Modal API
        // Create DOM element container for modal content (TYPO3 v14 requires DOM element, not HTML string)
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="config-test-loading text-center py-4" id="config-test-loading">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Testing configuration...</span>
                </div>
                <p class="text-body-secondary">Testing configuration ${escapeHtml(name)}...</p>
            </div>
            <div class="config-test-success" id="config-test-success" style="display: none;">
                <div class="text-center py-3 mb-3">
                    <span class="badge text-bg-success fs-4 p-3 rounded-circle">
                        <span class="icon icon-size-large">
                            <span class="icon-markup">&#10003;</span>
                        </span>
                    </span>
                </div>
                <h5 class="text-success text-center">Configuration Test Successful</h5>
                <div class="config-test-details mt-3">
                    <blockquote class="blockquote border-start border-success border-3 ps-3 py-2 mb-3 bg-success-subtle rounded-end" id="config-test-response" style="font-size: 1.05em;"></blockquote>
                    <div class="row small text-body-secondary">
                        <div class="col-6">
                            <strong>Model:</strong> <span id="config-test-model">-</span>
                        </div>
                        <div class="col-6">
                            <strong>Tokens:</strong> <span id="config-test-tokens">-</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="config-test-error alert alert-danger" id="config-test-error" style="display: none;">
                <h5 class="alert-heading">Configuration Test Failed</h5>
                <p id="config-test-error-message"></p>
            </div>
        `;

        Modal.advanced({
            title: `Test Configuration: ${name} (UID: ${uid})`,
            content: container,
            severity: Severity.info,
            size: Modal.sizes.default,
            buttons: [
                {
                    text: 'Close',
                    btnClass: 'btn-default',
                    trigger: function() {
                        Modal.dismiss();
                    }
                }
            ]
        });

        const formData = new FormData();
        formData.append('uid', uid);

        new AjaxRequest(url)
        .post(formData)
        .then(response => response.resolve())
        .then(data => {
            console.debug('[ConfigurationList] Test response:', data);
            // Use container reference instead of document.getElementById()
            // because TYPO3 Modal places content in a different DOM context
            const loadingDiv = container.querySelector('#config-test-loading');

            if (loadingDiv) loadingDiv.style.display = 'none';

            if (data.success) {
                this.renderTestSuccess(container, data);
            } else {
                this.renderTestError(container, data.error || data.message || 'Unknown error');
            }
        })
        .catch(async err => {
            console.error('[ConfigurationList] Test error:', err);
            // Use container reference instead of document.getElementById()
            const loadingDiv = container.querySelector('#config-test-loading');
            const errorDiv = container.querySelector('#config-test-error');

            if (loadingDiv) loadingDiv.style.display = 'none';
            if (errorDiv) {
                errorDiv.style.display = 'block';
                const msgEl = container.querySelector('#config-test-error-message');
                if (msgEl) msgEl.textContent = await readAjaxError(err);
            }
        });
    }

    renderTestSuccess(container, data) {
        const successDiv = container.querySelector('#config-test-success');
        if (!successDiv) {
            return;
        }

        successDiv.style.display = 'block';

        const responseEl = container.querySelector('#config-test-response');
        if (responseEl && data.content) {
            responseEl.textContent = data.content;
        }

        const modelEl = container.querySelector('#config-test-model');
        if (modelEl) {
            modelEl.textContent = data.model || '-';
        }

        const tokensEl = container.querySelector('#config-test-tokens');
        if (tokensEl && data.usage) {
            tokensEl.textContent = `${data.usage.totalTokens} (prompt: ${data.usage.promptTokens}, completion: ${data.usage.completionTokens})`;
        }
    }

    renderTestError(container, message) {
        const errorDiv = container.querySelector('#config-test-error');
        if (errorDiv) {
            errorDiv.style.display = 'block';
            const msgEl = container.querySelector('#config-test-error-message');
            if (msgEl) msgEl.textContent = message;
        }
    }
}

// Initialize module
export default new ConfigurationList();
