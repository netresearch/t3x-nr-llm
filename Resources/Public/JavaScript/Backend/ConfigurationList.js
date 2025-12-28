/**
 * Configuration list JavaScript module for TYPO3 backend.
 */
class ConfigurationList {
    constructor() {
        this.init();
    }

    init() {
        // Toggle active status
        document.querySelectorAll('.js-toggle-active').forEach((btn) => {
            btn.addEventListener('click', (e) => this.handleToggleActive(e));
        });

        // Set as default
        document.querySelectorAll('.js-set-default').forEach((btn) => {
            btn.addEventListener('click', (e) => this.handleSetDefault(e));
        });

        // Test configuration
        document.querySelectorAll('.js-test-config').forEach((btn) => {
            btn.addEventListener('click', (e) => this.handleTestConfig(e));
        });
    }

    handleToggleActive(e) {
        const btn = e.currentTarget;
        const uid = btn.dataset.uid;
        const url = TYPO3.settings.ajaxUrls['nrllm_config_toggle_active'];

        console.log('Toggle active clicked, uid:', uid, 'url:', url);

        if (!url) {
            top.TYPO3.Notification.error('Error', 'AJAX URL not configured');
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
                top.TYPO3.Notification.error('Error', data.error || 'Unknown error');
            }
        })
        .catch(err => {
            top.TYPO3.Notification.error('Error', err.message);
        });
    }

    handleSetDefault(e) {
        const btn = e.currentTarget;
        const uid = btn.dataset.uid;
        const url = TYPO3.settings.ajaxUrls['nrllm_config_set_default'];

        console.log('Set default clicked, uid:', uid, 'url:', url);

        if (!url) {
            top.TYPO3.Notification.error('Error', 'AJAX URL not configured');
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
                top.TYPO3.Notification.error('Error', data.error || 'Unknown error');
            }
        })
        .catch(err => {
            top.TYPO3.Notification.error('Error', err.message);
        });
    }

    handleTestConfig(e) {
        const btn = e.currentTarget;
        const uid = btn.dataset.uid;
        const url = TYPO3.settings.ajaxUrls['nrllm_config_test'];

        console.log('Test config clicked, uid:', uid, 'url:', url);

        if (!url) {
            top.TYPO3.Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const resultDiv = document.getElementById('test-result');
        const loadingDiv = document.getElementById('test-loading');
        const successDiv = document.getElementById('test-success');
        const errorDiv = document.getElementById('test-error');
        const detailsDiv = document.getElementById('test-details');

        resultDiv.style.display = 'block';
        loadingDiv.style.display = 'block';
        successDiv.style.display = 'none';
        errorDiv.style.display = 'none';
        if (detailsDiv) {
            detailsDiv.style.display = 'none';
        }

        const formData = new FormData();
        formData.append('uid', uid);

        fetch(url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            loadingDiv.style.display = 'none';

            if (data.success) {
                successDiv.style.display = 'block';
                if (detailsDiv && data.content) {
                    detailsDiv.style.display = 'block';
                    document.getElementById('test-response').textContent = data.content;
                    document.getElementById('test-model').textContent = data.model || '-';
                    if (data.usage) {
                        document.getElementById('test-tokens').textContent =
                            data.usage.totalTokens + ' (prompt: ' + data.usage.promptTokens +
                            ', completion: ' + data.usage.completionTokens + ')';
                    }
                }
            } else {
                errorDiv.style.display = 'block';
                document.getElementById('test-error-message').textContent = data.error || data.message || 'Unknown error';
            }
        })
        .catch(err => {
            loadingDiv.style.display = 'none';
            errorDiv.style.display = 'block';
            document.getElementById('test-error-message').textContent = err.message;
        });
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new ConfigurationList());
} else {
    new ConfigurationList();
}
