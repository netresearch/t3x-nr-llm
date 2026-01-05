/**
 * Task Execute module for handling task execution in the backend.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

class TaskExecute {
    constructor() {
        this.container = document.querySelector('[data-task-execute]');
        if (!this.container) return;

        // Create reusable element for HTML escaping
        this._escapeEl = document.createElement('div');

        this.taskUid = this.container.dataset.taskUid;
        this.executeUrl = TYPO3.settings.ajaxUrls.nrllm_task_execute;
        this.listTablesUrl = TYPO3.settings.ajaxUrls.nrllm_task_list_tables;
        this.fetchRecordsUrl = TYPO3.settings.ajaxUrls.nrllm_task_fetch_records;
        this.refreshInputUrl = TYPO3.settings.ajaxUrls.nrllm_task_refresh_input;
        this.loadRecordDataUrl = TYPO3.settings.ajaxUrls.nrllm_task_load_record_data;

        this.elapsedTimer = null;

        this.initializeElements();
        this.bindEvents();
    }

    initializeElements() {
        this.executeBtn = document.getElementById('executeBtn');
        this.btnContent = this.executeBtn?.querySelector('.btn-content');
        this.btnLoading = this.executeBtn?.querySelector('.btn-loading');
        this.taskInput = document.getElementById('taskInput');
        this.outputStatus = document.getElementById('outputStatus');
        this.outputPlaceholder = document.getElementById('outputPlaceholder');
        this.outputLoading = document.getElementById('outputLoading');
        this.outputError = document.getElementById('outputError');
        this.outputResult = document.getElementById('outputResult');
        this.outputContent = document.getElementById('outputContent');
        this.outputModel = document.getElementById('outputModel');
        this.outputTokens = document.getElementById('outputTokens');
        this.errorMessage = document.getElementById('errorMessage');
        this.elapsedTimeEl = document.getElementById('elapsedTime');
        this.copyOutputBtn = document.getElementById('copyOutputBtn');
        this.refreshInputBtn = document.getElementById('refreshInputBtn');
        this.emptyDataWarning = document.getElementById('emptyDataWarning');

        // Table picker elements
        this.tablePickerCollapse = document.getElementById('tablePickerCollapse');
        this.tableSelect = document.getElementById('tableSelect');
        this.recordSelect = document.getElementById('recordSelect');
        this.loadRecordsBtn = document.getElementById('loadRecordsBtn');
    }

    bindEvents() {
        // Load tables when picker is opened
        if (this.tablePickerCollapse) {
            this.tablePickerCollapse.addEventListener('show.bs.collapse', async () => {
                if (this.tableSelect.options.length <= 1) {
                    await this.loadTables();
                }
            });
        }

        // Load records when table is selected
        this.tableSelect?.addEventListener('change', () => this.onTableChange());

        // Load selected records into input
        this.loadRecordsBtn?.addEventListener('click', () => this.loadSelectedRecords());

        // Refresh input data for auto-fetch tasks
        this.refreshInputBtn?.addEventListener('click', () => this.refreshInput());

        // Execute task
        this.executeBtn?.addEventListener('click', () => this.executeTask());

        // Copy output
        this.copyOutputBtn?.addEventListener('click', () => this.copyOutput());
    }

    async loadTables() {
        this.tableSelect.innerHTML = '<option value="">Loading...</option>';
        try {
            const response = await new AjaxRequest(this.listTablesUrl)
                .post({})
                .then(r => r.resolve());

            if (response.success && response.tables) {
                this.tableSelect.innerHTML = '<option value="">-- Select a table --</option>';
                response.tables.forEach(table => {
                    const option = document.createElement('option');
                    option.value = table.name;
                    option.textContent = `${table.label} (${table.name})`;
                    this.tableSelect.appendChild(option);
                });
            } else {
                throw new Error(response.error || 'Failed to load tables');
            }
        } catch (error) {
            this.tableSelect.innerHTML = '<option value="">Error loading tables</option>';
            Notification.error('Error', error.message || 'Failed to load tables');
        }
    }

    async onTableChange() {
        const table = this.tableSelect.value;
        if (!table) {
            this.recordSelect.innerHTML = '<option value="">Select a table first</option>';
            this.recordSelect.disabled = true;
            this.loadRecordsBtn.disabled = true;
            return;
        }

        this.recordSelect.innerHTML = '<option value="">Loading records...</option>';
        this.recordSelect.disabled = true;

        try {
            const formData = new FormData();
            formData.append('table', table);
            formData.append('limit', '100');

            const response = await new AjaxRequest(this.fetchRecordsUrl)
                .post(formData)
                .then(r => r.resolve());

            if (response.success && response.records) {
                this.recordSelect.innerHTML = '';
                if (response.records.length === 0) {
                    this.recordSelect.innerHTML = '<option value="">No records found</option>';
                } else {
                    response.records.forEach(record => {
                        const option = document.createElement('option');
                        option.value = record.uid;
                        option.textContent = `[${record.uid}] ${record.label}`;
                        this.recordSelect.appendChild(option);
                    });
                    this.recordSelect.disabled = false;
                    this.loadRecordsBtn.disabled = false;
                }
            } else {
                throw new Error(response.error || 'Failed to load records');
            }
        } catch (error) {
            this.recordSelect.innerHTML = '<option value="">Error loading records</option>';
            Notification.error('Error', error.message || 'Failed to load records');
        }
    }

    async loadSelectedRecords() {
        const table = this.tableSelect.value;
        const selectedOptions = Array.from(this.recordSelect.selectedOptions);
        const selectedUids = selectedOptions.map(opt => opt.value);

        if (!table || selectedUids.length === 0) {
            Notification.warning('Selection Required', 'Please select a table and at least one record.');
            return;
        }

        this.loadRecordsBtn.disabled = true;
        const originalBtnHtml = this.loadRecordsBtn.innerHTML;
        this.loadRecordsBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';

        try {
            const formData = new FormData();
            formData.append('table', table);
            formData.append('uids', selectedUids.join(','));

            const response = await new AjaxRequest(this.loadRecordDataUrl)
                .post(formData)
                .then(r => r.resolve());

            if (response.success) {
                this.taskInput.value = response.data;
                Notification.success('Data Loaded', `Loaded ${response.recordCount} record(s) from ${table}`);

                // Collapse the picker
                const bsCollapse = bootstrap.Collapse.getInstance(this.tablePickerCollapse);
                if (bsCollapse) {
                    bsCollapse.hide();
                }
            } else {
                throw new Error(response.error || 'Failed to load records');
            }
        } catch (error) {
            Notification.error('Error', error.message || 'Failed to load record data');
        } finally {
            this.loadRecordsBtn.disabled = false;
            this.loadRecordsBtn.innerHTML = originalBtnHtml;
        }
    }

    async refreshInput() {
        this.refreshInputBtn.disabled = true;
        const originalHtml = this.refreshInputBtn.innerHTML;
        this.refreshInputBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Refreshing...';

        try {
            const formData = new FormData();
            formData.append('uid', this.taskUid);

            const response = await new AjaxRequest(this.refreshInputUrl)
                .post(formData)
                .then(r => r.resolve());

            if (response.success) {
                this.taskInput.value = response.inputData;
                if (response.isEmpty && this.emptyDataWarning) {
                    this.emptyDataWarning.classList.remove('d-none');
                } else if (this.emptyDataWarning) {
                    this.emptyDataWarning.classList.add('d-none');
                }
                Notification.success('Refreshed', 'Input data has been refreshed');
            } else {
                throw new Error(response.error || 'Failed to refresh data');
            }
        } catch (error) {
            Notification.error('Error', error.message || 'Failed to refresh input data');
        } finally {
            this.refreshInputBtn.disabled = false;
            this.refreshInputBtn.innerHTML = originalHtml;
        }
    }

    async executeTask() {
        // Show loading state on button
        this.executeBtn.disabled = true;
        this.btnContent?.classList.add('d-none');
        this.btnLoading?.classList.remove('d-none');

        // Show loading state in output panel
        this.outputStatus.classList.remove('d-none');
        this.outputPlaceholder.classList.add('d-none');
        this.outputLoading?.classList.remove('d-none');
        this.outputError.classList.add('d-none');
        this.outputResult.classList.add('d-none');

        // Start elapsed time counter
        let elapsedSeconds = 0;
        if (this.elapsedTimeEl) {
            this.elapsedTimeEl.textContent = '0';
            this.elapsedTimer = setInterval(() => {
                elapsedSeconds++;
                this.elapsedTimeEl.textContent = elapsedSeconds.toString();
            }, 1000);
        }

        try {
            const formData = new FormData();
            formData.append('uid', this.taskUid);
            formData.append('input', this.taskInput.value);

            const response = await new AjaxRequest(this.executeUrl)
                .post(formData)
                .then(r => r.resolve());

            if (response.success) {
                // Format output based on format
                // SECURITY: Escape HTML to prevent XSS from untrusted LLM responses
                const safeContent = this.escapeHtml(response.content);
                let formattedContent;
                if (response.outputFormat === 'json') {
                    try {
                        // Parse and re-stringify to validate JSON, then escape
                        const formatted = JSON.stringify(JSON.parse(response.content), null, 2);
                        formattedContent = '<pre>' + this.escapeHtml(formatted) + '</pre>';
                    } catch {
                        formattedContent = '<pre>' + safeContent + '</pre>';
                    }
                } else if (response.outputFormat === 'markdown') {
                    // Basic markdown rendering with escaped content
                    // Note: We escape first, then apply safe markdown transformations
                    formattedContent = '<div class="markdown-content">' +
                        safeContent
                            .replace(/```(\w+)?\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
                            .replace(/`([^`]+)`/g, '<code>$1</code>')
                            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                            .replace(/\n/g, '<br>') +
                        '</div>';
                } else {
                    formattedContent = '<pre>' + safeContent + '</pre>';
                }

                this.outputContent.innerHTML = formattedContent;
                this.outputModel.textContent = response.model || '-';
                this.outputTokens.textContent = response.usage?.totalTokens || '-';
                this.outputResult.classList.remove('d-none');

                Notification.success('Task completed', 'The task has been executed successfully.');
            } else {
                this.errorMessage.textContent = response.error || 'Unknown error';
                this.outputError.classList.remove('d-none');
                Notification.error('Task failed', response.error || 'Unknown error');
            }
        } catch (error) {
            this.errorMessage.textContent = error.message || 'Request failed';
            this.outputError.classList.remove('d-none');
            Notification.error('Request failed', error.message || 'Unknown error');
        } finally {
            // Stop elapsed timer
            if (this.elapsedTimer) {
                clearInterval(this.elapsedTimer);
                this.elapsedTimer = null;
            }

            // Reset button state
            this.executeBtn.disabled = false;
            this.btnContent?.classList.remove('d-none');
            this.btnLoading?.classList.add('d-none');

            // Hide loading indicators
            this.outputStatus.classList.add('d-none');
            this.outputLoading?.classList.add('d-none');
        }
    }

    copyOutput() {
        const text = this.outputContent.innerText || this.outputContent.textContent;
        navigator.clipboard.writeText(text).then(() => {
            Notification.success('Copied', 'Output copied to clipboard');
        }).catch(() => {
            Notification.error('Failed', 'Could not copy to clipboard');
        });
    }

    /**
     * Escape HTML entities to prevent XSS attacks.
     * LLM responses are untrusted external content.
     *
     * @param {string} text - The text to escape
     * @returns {string} - HTML-escaped text
     */
    escapeHtml(text) {
        if (typeof text !== 'string') {
            return '';
        }
        this._escapeEl.textContent = text;
        return this._escapeEl.innerHTML;
    }
}

// Initialize when DOM is ready
// Note: For ES6 modules loaded via importmap, DOMContentLoaded may have already fired
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        new TaskExecute();
    });
} else {
    // DOM already loaded, initialize immediately
    new TaskExecute();
}
