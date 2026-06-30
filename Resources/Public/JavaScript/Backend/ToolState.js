/**
 * Tool state JavaScript module for the TYPO3 backend (ES6 Module).
 *
 * Toggles the global enable/disable override of a single agent tool from the
 * Tool Playground module. Mirrors SkillList.js: event delegation on the body
 * and a state-changing AJAX POST via AjaxRequest, which injects TYPO3's CSRF
 * token (and sets the request content-type) automatically.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { readAjaxError } from '@netresearch/nr-llm/Backend/AjaxError.js';

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
        new AjaxRequest(url)
            .post(formData)
            .then(response => response.resolve())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    Notification.error('Error', data.error || 'Unknown error');
                    btn.disabled = false;
                }
            })
            .catch(async err => {
                Notification.error('Error', await readAjaxError(err));
                btn.disabled = false;
            });
    }
}

export default new ToolState();
