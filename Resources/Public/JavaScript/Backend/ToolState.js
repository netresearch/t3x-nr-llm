/**
 * Tool state JavaScript module for the TYPO3 backend (ES6 Module).
 *
 * Toggles the global enable/disable override of a single agent tool from the
 * Tool Playground module. Mirrors SkillList.js: event delegation on the body
 * and a CSRF-tokenised AJAX POST (the per-route token is embedded in
 * TYPO3.settings.ajaxUrls).
 */
import Notification from '@typo3/backend/notification.js';

class ToolState {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        document.body.addEventListener('click', (e) => {
            const toggleBtn = e.target.closest('.js-tool-toggle');
            if (toggleBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleToggle(toggleBtn);
            }
        });

        console.debug('[ToolState] Initialized with event delegation');
    }

    handleToggle(btn) {
        const tool = btn.dataset.tool;
        const enabled = btn.dataset.enabled === '1' ? 0 : 1;
        const url = TYPO3.settings.ajaxUrls['nrllm_tool_toggle'];
        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const formData = new FormData();
        formData.append('tool', tool);
        formData.append('enabled', String(enabled));

        btn.disabled = true;
        fetch(url, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    Notification.error('Error', data.error || 'Unknown error');
                    btn.disabled = false;
                }
            })
            .catch(err => {
                Notification.error('Error', err.message);
                btn.disabled = false;
            });
    }
}

export default new ToolState();
