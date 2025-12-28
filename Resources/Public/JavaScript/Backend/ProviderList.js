/**
 * Provider list JavaScript module for TYPO3 backend.
 */
class ProviderList {
    constructor() {
        this.init();
    }

    init() {
        // Toggle active status
        document.querySelectorAll('.js-toggle-active').forEach((btn) => {
            btn.addEventListener('click', (e) => this.handleToggleActive(e));
        });

        // Test connection
        document.querySelectorAll('.js-test-connection').forEach((btn) => {
            btn.addEventListener('click', (e) => this.handleTestConnection(e));
        });
    }

    handleToggleActive(e) {
        const btn = e.currentTarget;
        const uid = btn.dataset.uid;
        const url = TYPO3.settings.ajaxUrls['nrllm_provider_toggle_active'];

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

    handleTestConnection(e) {
        const btn = e.currentTarget;
        const uid = btn.dataset.uid;
        const url = TYPO3.settings.ajaxUrls['nrllm_provider_test_connection'];

        console.log('Test connection clicked, uid:', uid, 'url:', url);

        if (!url) {
            top.TYPO3.Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const resultDiv = document.getElementById('test-result');
        const loadingDiv = document.getElementById('test-loading');
        const successDiv = document.getElementById('test-success');
        const errorDiv = document.getElementById('test-error');

        resultDiv.style.display = 'block';
        loadingDiv.style.display = 'block';
        successDiv.style.display = 'none';
        errorDiv.style.display = 'none';

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
                document.getElementById('test-success-message').textContent = data.message || 'Connection successful';
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
    document.addEventListener('DOMContentLoaded', () => new ProviderList());
} else {
    new ProviderList();
}
