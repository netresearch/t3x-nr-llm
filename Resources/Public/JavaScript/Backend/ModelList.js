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
        // Create DOM element container for modal content (TYPO3 v14 requires DOM element, not HTML string)
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="modal-loading py-4" id="model-test-loading">
                <div class="text-center mb-4">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="visually-hidden">Testing model...</span>
                    </div>
                    <h5 class="mb-2">Testing model ${this.escapeHtml(name)}</h5>
                </div>
                <div class="progress-steps small">
                    <div class="d-flex align-items-center mb-2" id="step-connect">
                        <span class="spinner-border spinner-border-sm text-primary me-2" id="step-connect-spinner"></span>
                        <span class="text-muted" id="step-connect-text">Connecting to provider...</span>
                    </div>
                    <div class="d-flex align-items-center mb-2 text-muted" id="step-send" style="opacity: 0.5;">
                        <span class="me-2">○</span>
                        <span id="step-send-text">Sending test prompt...</span>
                    </div>
                    <div class="d-flex align-items-center mb-2 text-muted" id="step-wait" style="opacity: 0.5;">
                        <span class="me-2">○</span>
                        <span id="step-wait-text">Waiting for response...</span>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <small class="text-muted">Elapsed: <span id="elapsed-time">0</span>s</small>
                </div>
                <div class="alert alert-info mt-3 small">
                    <strong>Note:</strong> Large models or models with thinking/reasoning capabilities may take longer to respond.
                </div>
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
                <small class="text-muted">Completed in <span id="success-elapsed">0</span>s</small>
            </div>
            <div class="modal-error alert alert-danger" id="model-test-error" style="display: none;">
                <h5 class="alert-heading">Model Test Failed</h5>
                <p id="model-test-error-message"></p>
                <small class="text-muted">Failed after <span id="error-elapsed">0</span>s</small>
            </div>
        `;

        const modal = Modal.advanced({
            title: `Test Model: ${name}`,
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

        // Start elapsed time counter
        const startTime = Date.now();
        let timerInterval = setInterval(() => {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            const elapsedEl = document.getElementById('elapsed-time');
            if (elapsedEl) {
                elapsedEl.textContent = elapsed.toString();
            }
        }, 1000);

        // Update progress steps with timing
        const updateStep = (stepNum) => {
            const steps = ['connect', 'send', 'wait'];
            steps.forEach((step, idx) => {
                const stepEl = document.getElementById(`step-${step}`);
                const spinnerEl = document.getElementById(`step-${step}-spinner`);
                if (!stepEl) return;

                if (idx < stepNum) {
                    // Completed step
                    stepEl.style.opacity = '1';
                    stepEl.classList.remove('text-muted');
                    stepEl.classList.add('text-success');
                    if (spinnerEl) {
                        spinnerEl.outerHTML = '<span class="text-success me-2">✓</span>';
                    } else {
                        const icon = stepEl.querySelector('span:first-child');
                        if (icon) icon.outerHTML = '<span class="text-success me-2">✓</span>';
                    }
                } else if (idx === stepNum) {
                    // Current step
                    stepEl.style.opacity = '1';
                    stepEl.classList.remove('text-muted');
                    const icon = stepEl.querySelector('span:first-child');
                    if (icon && !icon.classList.contains('spinner-border')) {
                        icon.outerHTML = '<span class="spinner-border spinner-border-sm text-primary me-2"></span>';
                    }
                }
            });
        };

        // Simulate progress (actual API call is single request)
        setTimeout(() => updateStep(1), 200);  // "Sending test prompt"
        setTimeout(() => updateStep(2), 500);  // "Waiting for response"

        const formData = new FormData();
        formData.append('uid', uid);

        fetch(url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            clearInterval(timerInterval);
            const elapsed = Math.floor((Date.now() - startTime) / 1000);

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
                    const elapsedEl = document.getElementById('success-elapsed');
                    if (elapsedEl) elapsedEl.textContent = elapsed.toString();
                }
            } else {
                if (errorDiv) {
                    errorDiv.style.display = 'block';
                    const msgEl = document.getElementById('model-test-error-message');
                    if (msgEl) msgEl.textContent = data.error || data.message || 'Unknown error';
                    const elapsedEl = document.getElementById('error-elapsed');
                    if (elapsedEl) elapsedEl.textContent = elapsed.toString();
                }
            }
        })
        .catch(err => {
            clearInterval(timerInterval);
            const elapsed = Math.floor((Date.now() - startTime) / 1000);

            console.error('[ModelList] Test error:', err);
            const loadingDiv = document.getElementById('model-test-loading');
            const errorDiv = document.getElementById('model-test-error');

            if (loadingDiv) loadingDiv.style.display = 'none';
            if (errorDiv) {
                errorDiv.style.display = 'block';
                const msgEl = document.getElementById('model-test-error-message');
                if (msgEl) msgEl.textContent = err.message;
                const elapsedEl = document.getElementById('error-elapsed');
                if (elapsedEl) elapsedEl.textContent = elapsed.toString();
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
