/**
 * Skill list JavaScript module for TYPO3 backend (ES6 Module).
 *
 * Handles AJAX sync of skill sources, enable/disable toggling of ingested
 * skills, and storing a GitHub token (as a vault UUID) for a source.
 *
 * Uses TYPO3 Backend Notification API and event delegation for reliable
 * handling inside TYPO3 v14+ iframe modules.
 */
import Notification from '@typo3/backend/notification.js';

class SkillList {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        document.body.addEventListener('click', (e) => {
            const syncBtn = e.target.closest('.js-skill-sync');
            if (syncBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleSync(syncBtn);
                return;
            }

            const toggleBtn = e.target.closest('.js-skill-toggle');
            if (toggleBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleToggle(toggleBtn);
                return;
            }

            const tokenBtn = e.target.closest('.js-skill-token');
            if (tokenBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.handleSetToken(tokenBtn);
            }
        });

        console.debug('[SkillList] Initialized with event delegation');
    }

    handleSync(btn) {
        const source = btn.dataset.source;
        const url = TYPO3.settings.ajaxUrls['nrllm_skill_sync'];
        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const formData = new FormData();
        formData.append('source', source);

        btn.disabled = true;
        fetch(url, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Notification.success(
                        'Sync complete',
                        `Status: ${data.status}, created: ${data.created}, updated: ${data.updated}, auto-disabled: ${data.disabledOnChange}, orphaned: ${data.orphaned}`
                    );
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

    handleToggle(btn) {
        const skill = btn.dataset.skill;
        const enabled = btn.dataset.enabled === '1' ? 0 : 1;
        const url = TYPO3.settings.ajaxUrls['nrllm_skill_toggle'];
        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const formData = new FormData();
        formData.append('skill', skill);
        formData.append('enabled', String(enabled));

        fetch(url, { method: 'POST', body: formData })
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

    handleSetToken(btn) {
        const source = btn.dataset.source;
        const url = TYPO3.settings.ajaxUrls['nrllm_skill_token'];
        if (!url) {
            Notification.error('Error', 'AJAX URL not configured');
            return;
        }

        const token = window.prompt('Enter a GitHub access token for this source (stored encrypted via vault):');
        if (token === null) {
            return;
        }

        const formData = new FormData();
        formData.append('source', source);
        formData.append('token', token);

        fetch(url, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Notification.success('Token stored', 'The GitHub token was stored securely.');
                } else {
                    Notification.error('Error', data.error || 'Unknown error');
                }
            })
            .catch(err => {
                Notification.error('Error', err.message);
            });
    }
}

export default new SkillList();
