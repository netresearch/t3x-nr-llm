/**
 * Model list JavaScript module for TYPO3 backend.
 */
class ModelList {
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
    }

    handleToggleActive(e) {
        const btn = e.currentTarget;
        const uid = btn.dataset.uid;
        const url = TYPO3.settings.ajaxUrls['nrllm_model_toggle_active'];

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
        const url = TYPO3.settings.ajaxUrls['nrllm_model_set_default'];

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
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new ModelList());
} else {
    new ModelList();
}
