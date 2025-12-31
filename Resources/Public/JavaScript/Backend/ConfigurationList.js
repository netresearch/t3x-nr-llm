/**
 * Configuration list JavaScript module for TYPO3 backend (ES6 Module).
 *
 * Uses TYPO3 Backend Notification API and Modal.
 * Uses event delegation for reliable event handling in TYPO3 v14+ iframe modules.
 */
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

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
        });

        console.debug('[ConfigurationList] Initialized with event delegation');
    }

    handleToggleActive(btn) {
        const uid = btn.dataset.uid;
        const url = TYPO3.settings.ajaxUrls['nrllm_config_toggle_active'];

        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const formData = new FormData();
        formData.append('uid', uid);

        fetch(url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                Notification.error('Error', data.error || 'Unknown error');
            }
        })
        .catch(err => {
            Notification.error('Error', err.message);
        });
    }

    handleSetDefault(btn) {
        const uid = btn.dataset.uid;
        const url = TYPO3.settings.ajaxUrls['nrllm_config_set_default'];

        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const formData = new FormData();
        formData.append('uid', uid);

        fetch(url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                Notification.error('Error', data.error || 'Unknown error');
            }
        })
        .catch(err => {
            Notification.error('Error', err.message);
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
                <p class="text-body-secondary">Testing configuration ${this.escapeHtml(name)}...</p>
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
                    <div class="mb-2">
                        <strong>Response:</strong>
                        <p class="text-body-secondary small mb-1" id="config-test-response"></p>
                    </div>
                    <div class="row small">
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

        const modal = Modal.advanced({
            title: `Test Configuration: ${name}`,
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

        fetch(url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            console.debug('[ConfigurationList] Test response:', data);
            // Use container reference instead of document.getElementById()
            // because TYPO3 Modal places content in a different DOM context
            const loadingDiv = container.querySelector('#config-test-loading');
            const successDiv = container.querySelector('#config-test-success');
            const errorDiv = container.querySelector('#config-test-error');

            if (loadingDiv) loadingDiv.style.display = 'none';

            if (data.success) {
                if (successDiv) {
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
            } else {
                if (errorDiv) {
                    errorDiv.style.display = 'block';
                    const msgEl = container.querySelector('#config-test-error-message');
                    if (msgEl) msgEl.textContent = data.error || data.message || 'Unknown error';
                }
            }
        })
        .catch(err => {
            console.error('[ConfigurationList] Test error:', err);
            // Use container reference instead of document.getElementById()
            const loadingDiv = container.querySelector('#config-test-loading');
            const errorDiv = container.querySelector('#config-test-error');

            if (loadingDiv) loadingDiv.style.display = 'none';
            if (errorDiv) {
                errorDiv.style.display = 'block';
                const msgEl = container.querySelector('#config-test-error-message');
                if (msgEl) msgEl.textContent = err.message;
            }
        });
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize module
export default new ConfigurationList();
