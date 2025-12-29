/**
 * Model list JavaScript module for TYPO3 backend (ES6 Module).
 *
 * Uses TYPO3 Backend Notification API and Modal.
 * Uses event delegation for reliable event handling in TYPO3 v14+ iframe modules.
 */
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

class ModelList {
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

            // Test model
            const testBtn = e.target.closest('.js-test-model');
            if (testBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleTestModel(testBtn);
                return;
            }
        });

        console.debug('[ModelList] Initialized with event delegation');
    }

    handleToggleActive(btn) {
        const uid = btn.dataset.uid;
        const url = TYPO3.settings.ajaxUrls['nrllm_model_toggle_active'];

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
        const url = TYPO3.settings.ajaxUrls['nrllm_model_set_default'];

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

    handleTestModel(btn) {
        const uid = btn.dataset.uid;
        const name = btn.dataset.name || 'Model';
        const url = TYPO3.settings.ajaxUrls['nrllm_model_test'];

        console.debug('[ModelList] Test model clicked for UID:', uid, 'URL:', url);

        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            console.error('[ModelList] AJAX URL not found. TYPO3.settings.ajaxUrls:', TYPO3.settings?.ajaxUrls);
            return;
        }

        // Show modal with loading state using TYPO3 Modal API
        const modalContent = `
            <div class="modal-loading text-center py-4" id="model-test-loading">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Testing model...</span>
                </div>
                <p class="text-muted">Testing model ${this.escapeHtml(name)}...</p>
            </div>
            <div class="modal-success text-center py-4" id="model-test-success" style="display: none;">
                <div class="mb-3">
                    <span class="badge bg-success fs-4 p-3 rounded-circle">
                        <span class="icon icon-size-large">
                            <span class="icon-markup">&#10003;</span>
                        </span>
                    </span>
                </div>
                <h4 class="text-success">Model Test Successful</h4>
                <p class="text-muted" id="model-test-success-message"></p>
            </div>
            <div class="modal-error alert alert-danger" id="model-test-error" style="display: none;">
                <h5 class="alert-heading">Model Test Failed</h5>
                <p id="model-test-error-message"></p>
            </div>
        `;

        const modal = Modal.advanced({
            title: `Test Model: ${name}`,
            content: modalContent,
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
            console.debug('[ModelList] Test response:', data);
            const loadingDiv = document.getElementById('model-test-loading');
            const successDiv = document.getElementById('model-test-success');
            const errorDiv = document.getElementById('model-test-error');

            if (loadingDiv) loadingDiv.style.display = 'none';

            if (data.success) {
                if (successDiv) {
                    successDiv.style.display = 'block';
                    const msgEl = document.getElementById('model-test-success-message');
                    if (msgEl) msgEl.textContent = data.message || 'Model test successful';
                }
            } else {
                if (errorDiv) {
                    errorDiv.style.display = 'block';
                    const msgEl = document.getElementById('model-test-error-message');
                    if (msgEl) msgEl.textContent = data.error || data.message || 'Unknown error';
                }
            }
        })
        .catch(err => {
            console.error('[ModelList] Test error:', err);
            const loadingDiv = document.getElementById('model-test-loading');
            const errorDiv = document.getElementById('model-test-error');

            if (loadingDiv) loadingDiv.style.display = 'none';
            if (errorDiv) {
                errorDiv.style.display = 'block';
                const msgEl = document.getElementById('model-test-error-message');
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
export default new ModelList();
