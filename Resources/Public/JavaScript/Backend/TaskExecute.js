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

        // Raw response content for format switching
        this._rawContent = '';
        this._activeFormat = 'plain';

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
        this.formatToggle = document.getElementById('outputFormatToggle');

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

        // Format toggle
        this.formatToggle?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-format]');
            if (!btn) return;
            this.switchFormat(btn.dataset.format);
        });
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
                try {
                    const bsCollapse = bootstrap.Collapse.getInstance(this.tablePickerCollapse);
                    if (bsCollapse) {
                        bsCollapse.hide();
                    }
                } catch {
                    // Bootstrap not available, hide manually
                    if (this.tablePickerCollapse) {
                        this.tablePickerCollapse.classList.remove('show');
                    }
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
        this.outputStatus?.classList.remove('d-none');
        this.outputPlaceholder?.classList.add('d-none');
        this.outputLoading?.classList.remove('d-none');
        this.outputError?.classList.add('d-none');
        this.outputResult?.classList.add('d-none');

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
                // Store raw content for format switching
                this._rawContent = response.content || '';

                // Set initial format from task's output_format, default to plain
                const serverFormat = response.outputFormat || 'plain';
                this._activeFormat = serverFormat;
                this.renderOutput();
                this.updateFormatToggle();

                this.outputModel.textContent = response.model || '-';
                this.outputTokens.textContent = response.usage?.totalTokens || '-';
                this.outputResult.classList.remove('d-none');

                Notification.success('Task completed', 'The task has been executed successfully.');
            } else {
                if (this.errorMessage) this.errorMessage.textContent = response.error || 'Unknown error';
                this.outputError?.classList.remove('d-none');
                Notification.error('Task failed', response.error || 'Unknown error');
            }
        } catch (error) {
            if (this.errorMessage) this.errorMessage.textContent = error.message || 'Request failed';
            this.outputError?.classList.remove('d-none');
            Notification.error('Request failed', error.message || 'Unknown error');
        } finally {
            // Stop elapsed timer
            if (this.elapsedTimer) {
                clearInterval(this.elapsedTimer);
                this.elapsedTimer = null;
            }

            // Reset button state
            if (this.executeBtn) this.executeBtn.disabled = false;
            this.btnContent?.classList.remove('d-none');
            this.btnLoading?.classList.add('d-none');

            // Hide loading indicators
            this.outputStatus?.classList.add('d-none');
            this.outputLoading?.classList.add('d-none');
        }
    }

    /**
     * Switch output rendering format and re-render.
     */
    switchFormat(format) {
        if (!this._rawContent) return;
        this._activeFormat = format;
        this.renderOutput();
        this.updateFormatToggle();
    }

    /**
     * Update the active state of format toggle buttons.
     */
    updateFormatToggle() {
        if (!this.formatToggle) return;
        this.formatToggle.querySelectorAll('[data-format]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.format === this._activeFormat);
        });
    }

    /**
     * Render the stored raw content in the active format.
     *
     * Security approach per format:
     * - plain/markdown/json: Content is HTML-escaped via escapeHtml() before insertion.
     *   Markdown transformations operate on already-escaped content (safe).
     * - html: Rendered in a sandboxed iframe (sandbox="allow-same-origin") which
     *   prevents script execution and isolates untrusted content from the parent page.
     */
    renderOutput() {
        const content = this._rawContent;
        const escaped = this.escapeHtml(content);

        switch (this._activeFormat) {
            case 'html':
                this.renderHtmlOutput(content);
                break;

            case 'markdown':
                this.renderMarkdownOutput(escaped);
                break;

            case 'json':
                this.renderJsonOutput(content, escaped);
                break;

            default: // plain
                this.renderPlainOutput(escaped);
                break;
        }
    }

    /**
     * Render HTML in a sandboxed iframe for safe preview.
     */
    renderHtmlOutput(content) {
        this.outputContent.textContent = '';
        const iframe = document.createElement('iframe');
        iframe.sandbox = 'allow-same-origin';
        iframe.style.cssText = 'width:100%;border:none;min-height:200px;background:#fff;';
        iframe.srcdoc = [
            '<!DOCTYPE html><html><head><meta charset="utf-8"><style>',
            'body{font-family:system-ui,sans-serif;font-size:14px;padding:12px;margin:0;color:#333;line-height:1.5}',
            'pre{background:#f5f5f5;padding:8px;border-radius:4px;overflow-x:auto}',
            'code{background:#f0f0f0;padding:2px 4px;border-radius:3px;font-size:0.9em}',
            'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:6px 8px}',
            'img{max-width:100%}',
            '</style></head><body>',
            content,
            '</body></html>'
        ].join('');
        iframe.addEventListener('load', () => {
            try {
                const body = iframe.contentDocument?.body;
                if (body) iframe.style.height = (body.scrollHeight + 20) + 'px';
            } catch { /* cross-origin safety */ }
        });
        this.outputContent.appendChild(iframe);
    }

    /**
     * Render escaped content with basic markdown transformations.
     */
    renderMarkdownOutput(escaped) {
        const rendered = escaped
            // Headers
            .replace(/^(#{1,6})\s+(.+)$/gm, (_, hashes, text) => {
                const level = hashes.length;
                return '<h' + level + ' style="margin:0.8em 0 0.4em;font-size:' + (1.4 - level * 0.1) + 'em">' + text + '</h' + level + '>';
            })
            // Code blocks
            .replace(/```(\w+)?\n([\s\S]*?)```/g, '<pre style="background:#f5f5f5;padding:8px;border-radius:4px;overflow-x:auto"><code>$2</code></pre>')
            // Inline code
            .replace(/`([^`]+)`/g, '<code style="background:#f0f0f0;padding:2px 4px;border-radius:3px">$1</code>')
            // Bold
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            // Italic
            .replace(/\*([^*]+)\*/g, '<em>$1</em>')
            // Unordered lists
            .replace(/^[-*]\s+(.+)$/gm, '<li>$1</li>')
            // Ordered lists
            .replace(/^\d+\.\s+(.+)$/gm, '<li>$1</li>')
            // Horizontal rule
            .replace(/^---+$/gm, '<hr style="margin:1em 0">')
            // Line breaks
            .replace(/\n/g, '<br>');
        const wrapper = document.createElement('div');
        wrapper.className = 'markdown-content';
        wrapper.innerHTML = rendered; // eslint-disable-line no-unsanitized/property -- content is pre-escaped via escapeHtml()
        this.outputContent.textContent = '';
        this.outputContent.appendChild(wrapper);
    }

    /**
     * Render JSON with pretty-printing.
     */
    renderJsonOutput(content, escaped) {
        const pre = document.createElement('pre');
        pre.style.margin = '0';
        try {
            const formatted = JSON.stringify(JSON.parse(content), null, 2);
            pre.textContent = formatted;
        } catch {
            pre.textContent = content;
        }
        this.outputContent.textContent = '';
        this.outputContent.appendChild(pre);
    }

    /**
     * Render plain text in a pre block.
     */
    renderPlainOutput(escaped) {
        const pre = document.createElement('pre');
        pre.style.cssText = 'margin:0;white-space:pre-wrap;word-break:break-word';
        pre.textContent = this._rawContent;
        this.outputContent.textContent = '';
        this.outputContent.appendChild(pre);
    }

    copyOutput() {
        // Always copy raw content, not rendered HTML
        navigator.clipboard.writeText(this._rawContent || this.outputContent.innerText).then(() => {
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
