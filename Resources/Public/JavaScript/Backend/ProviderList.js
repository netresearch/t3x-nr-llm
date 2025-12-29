/**
 * Provider list JavaScript module for TYPO3 backend (ES6 Module).
 *
 * Uses TYPO3 Backend Notification API and Bootstrap Modal.
 * Uses event delegation for reliable event handling in TYPO3 v14+ iframe modules.
 */
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

class ProviderList {
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

            // Test connection
            const testBtn = e.target.closest('.js-test-connection');
            if (testBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleTestConnection(testBtn);
                return;
            }
        });

        console.debug('[ProviderList] Initialized with event delegation');
    }

    handleToggleActive(btn) {
        const uid = btn.dataset.uid;
        const url = TYPO3.settings.ajaxUrls['nrllm_provider_toggle_active'];

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

    handleTestConnection(btn) {
        const uid = btn.dataset.uid;
        const name = btn.dataset.name || 'Provider';
        const url = TYPO3.settings.ajaxUrls['nrllm_provider_test_connection'];

        console.debug('[ProviderList] Test connection clicked for UID:', uid, 'URL:', url);

        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            console.error('[ProviderList] AJAX URL not found. TYPO3.settings.ajaxUrls:', TYPO3.settings?.ajaxUrls);
            return;
        }

        // Show modal with loading state using TYPO3 Modal API
        // Create DOM element container for modal content (TYPO3 v14 requires DOM element, not HTML string)
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="modal-loading text-center py-4" id="provider-test-loading">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Testing connection...</span>
                </div>
                <p class="text-muted">Testing connection to ${this.escapeHtml(name)}...</p>
            </div>
            <div class="modal-success text-center py-4" id="provider-test-success" style="display: none;">
                <div class="mb-3">
                    <span class="badge bg-success fs-4 p-3 rounded-circle">
                        <span class="icon icon-size-large">
                            <span class="icon-markup">&#10003;</span>
                        </span>
                    </span>
                </div>
                <h4 class="text-success">Connection Successful</h4>
                <p class="text-muted" id="provider-test-success-message"></p>
            </div>
            <div class="modal-error alert alert-danger" id="provider-test-error" style="display: none;">
                <h5 class="alert-heading">Connection Failed</h5>
                <p id="provider-test-error-message"></p>
            </div>
        `;

        const modal = Modal.advanced({
            title: `Test Connection: ${name}`,
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
            console.debug('[ProviderList] Test response:', data);
            const loadingDiv = document.getElementById('provider-test-loading');
            const successDiv = document.getElementById('provider-test-success');
            const errorDiv = document.getElementById('provider-test-error');

            if (loadingDiv) loadingDiv.style.display = 'none';

            if (data.success) {
                if (successDiv) {
                    successDiv.style.display = 'block';
                    const msgEl = document.getElementById('provider-test-success-message');
                    if (msgEl) msgEl.textContent = data.message || 'Connection successful';
                }
            } else {
                if (errorDiv) {
                    errorDiv.style.display = 'block';
                    const msgEl = document.getElementById('provider-test-error-message');
                    if (msgEl) msgEl.textContent = data.error || data.message || 'Unknown error';
                }
            }
        })
        .catch(err => {
            console.error('[ProviderList] Test error:', err);
            const loadingDiv = document.getElementById('provider-test-loading');
            const errorDiv = document.getElementById('provider-test-error');

            if (loadingDiv) loadingDiv.style.display = 'none';
            if (errorDiv) {
                errorDiv.style.display = 'block';
                const msgEl = document.getElementById('provider-test-error-message');
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
export default new ProviderList();
