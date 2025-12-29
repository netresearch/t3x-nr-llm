/**
 * Setup Wizard JavaScript (ES6 Module)
 *
 * Handles the multi-step wizard flow for LLM provider configuration.
 * Uses TYPO3 Backend Notification API for user feedback.
 */
import Notification from '@typo3/backend/notification.js';

class SetupWizard {
    constructor() {
        this.currentStep = 1;
        this.totalSteps = 5;
        this.data = {
            endpoint: '',
            apiKey: '',
            adapterType: '',
            provider: null,
            models: [],
            configurations: [],
        };

        // Wait for DOM to be ready before initializing
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        // Step 1: Connect
        document.getElementById('wizard-endpoint')?.addEventListener('input', () => this.onEndpointChange());
        document.getElementById('toggle-apikey')?.addEventListener('click', () => this.toggleApiKeyVisibility());
        document.getElementById('show-adapter-override')?.addEventListener('click', () => this.showAdapterOverride());
        document.getElementById('btn-detect')?.addEventListener('click', () => this.detectProvider());

        // Step 2: Verify
        document.getElementById('btn-back-1')?.addEventListener('click', () => this.goToStep(1));
        document.getElementById('btn-discover')?.addEventListener('click', () => this.discoverModels());

        // Step 3: Models
        document.getElementById('btn-back-2')?.addEventListener('click', () => this.goToStep(2));
        document.getElementById('btn-generate')?.addEventListener('click', () => this.generateConfigurations());
        document.getElementById('select-all-models')?.addEventListener('change', (e) => this.toggleAllModels(e.target.checked));

        // Step 4: Configure
        document.getElementById('btn-back-3')?.addEventListener('click', () => this.goToStep(3));
        document.getElementById('btn-review')?.addEventListener('click', () => this.reviewConfiguration());

        // Step 5: Save
        document.getElementById('btn-back-4')?.addEventListener('click', () => this.goToStep(4));
        document.getElementById('btn-save')?.addEventListener('click', () => this.saveConfiguration());
        document.getElementById('btn-restart')?.addEventListener('click', () => this.restart());
    }

    goToStep(step) {
        // Hide all panels
        document.querySelectorAll('.wizard-panel').forEach(panel => {
            panel.style.display = 'none';
        });

        // Show target panel
        const targetPanel = document.querySelector(`[data-panel="${step}"]`);
        if (targetPanel) {
            targetPanel.style.display = 'block';
        }

        // Update progress indicators
        document.querySelectorAll('.wizard-step').forEach(stepEl => {
            const stepNum = parseInt(stepEl.dataset.step, 10);
            stepEl.classList.remove('active', 'completed');
            if (stepNum < step) {
                stepEl.classList.add('completed');
            } else if (stepNum === step) {
                stepEl.classList.add('active');
            }
        });

        // Update progress bar
        const progress = ((step - 1) / (this.totalSteps - 1)) * 100;
        document.querySelector('.wizard-progress-fill').style.width = `${progress}%`;

        this.currentStep = step;
    }

    onEndpointChange() {
        const endpoint = document.getElementById('wizard-endpoint').value.trim();

        // Auto-detect on paste or when URL looks complete
        if (endpoint.length > 10 && (endpoint.includes('.') || endpoint.includes('localhost'))) {
            this.detectProviderSilent(endpoint);
        }
    }

    async detectProviderSilent(endpoint) {
        try {
            const response = await fetch(TYPO3.settings.ajaxUrls['nrllm_wizard_detect'], {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ endpoint }),
            });

            const result = await response.json();

            if (result.success && result.provider) {
                this.showDetectedProvider(result.provider);
            }
        } catch (e) {
            // Silent fail for auto-detection
        }
    }

    showDetectedProvider(provider) {
        const container = document.getElementById('detected-provider');
        document.getElementById('detected-name').textContent = provider.suggestedName;
        document.getElementById('detected-adapter').textContent = provider.adapterType;
        document.getElementById('detected-confidence').textContent =
            `Confidence: ${Math.round(provider.confidence * 100)}%`;

        container.style.display = 'block';

        // Pre-select adapter type
        const adapterSelect = document.getElementById('wizard-adapter');
        if (adapterSelect) {
            adapterSelect.value = provider.adapterType;
        }

        this.data.adapterType = provider.adapterType;
    }

    toggleApiKeyVisibility() {
        const input = document.getElementById('wizard-apikey');
        const btn = document.getElementById('toggle-apikey');

        if (input.type === 'password') {
            input.type = 'text';
            btn.innerHTML = '<span class="icon icon-size-small"><span class="icon-markup"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path d="M8 3C3 3 0 8 0 8s3 5 8 5 8-5 8-5-3-5-8-5zm0 8c-1.7 0-3-1.3-3-3s1.3-3 3-3 3 1.3 3 3-1.3 3-3 3z"/><line x1="2" y1="14" x2="14" y2="2" stroke="currentColor" stroke-width="1.5"/></svg></span></span>';
        } else {
            input.type = 'password';
            btn.innerHTML = '<span class="icon icon-size-small"><span class="icon-markup"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path d="M8 3C3 3 0 8 0 8s3 5 8 5 8-5 8-5-3-5-8-5zm0 8c-1.7 0-3-1.3-3-3s1.3-3 3-3 3 1.3 3 3-1.3 3-3 3z"/></svg></span></span>';
        }
    }

    showAdapterOverride() {
        document.getElementById('adapter-override').style.display = 'block';
        document.getElementById('show-adapter-override').style.display = 'none';
    }

    async detectProvider() {
        const endpoint = document.getElementById('wizard-endpoint').value.trim();
        const apiKey = document.getElementById('wizard-apikey').value.trim();

        // Only use adapter override if the override section is visible
        const adapterOverride = document.getElementById('adapter-override');
        const isAdapterOverrideVisible = adapterOverride && adapterOverride.style.display !== 'none';
        const adapterType = isAdapterOverrideVisible
            ? (document.getElementById('wizard-adapter')?.value || '')
            : '';

        if (!endpoint) {
            Notification.warning('Validation Error', 'Please enter an API endpoint URL', 5);
            return;
        }

        this.data.endpoint = endpoint;
        this.data.apiKey = apiKey;

        // Go to step 2 and test connection
        this.goToStep(2);

        // Reset step 2 UI
        document.getElementById('test-loading').style.display = 'block';
        document.getElementById('test-success').style.display = 'none';
        document.getElementById('test-error').style.display = 'none';
        document.getElementById('btn-discover').disabled = true;

        try {
            // First detect provider
            const detectResponse = await fetch(TYPO3.settings.ajaxUrls['nrllm_wizard_detect'], {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ endpoint }),
            });

            const detectResult = await detectResponse.json();

            if (detectResult.success && detectResult.provider) {
                this.data.provider = detectResult.provider;
                this.data.adapterType = adapterType || detectResult.provider.adapterType;
            }

            // Test connection
            const testResponse = await fetch(TYPO3.settings.ajaxUrls['nrllm_wizard_test'], {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    endpoint: this.data.endpoint,
                    apiKey: this.data.apiKey,
                    adapterType: this.data.adapterType,
                }),
            });

            const testResult = await testResponse.json();

            document.getElementById('test-loading').style.display = 'none';

            if (testResult.success) {
                document.getElementById('test-success').style.display = 'block';
                document.getElementById('test-success-message').textContent = testResult.message;
                document.getElementById('btn-discover').disabled = false;
            } else {
                document.getElementById('test-error').style.display = 'block';
                document.getElementById('test-error-message').textContent = testResult.message || testResult.error;
            }
        } catch (e) {
            document.getElementById('test-loading').style.display = 'none';
            document.getElementById('test-error').style.display = 'block';
            document.getElementById('test-error-message').textContent = 'Network error: ' + e.message;
        }
    }

    async discoverModels() {
        this.goToStep(3);

        // Reset step 3 UI
        document.getElementById('models-loading').style.display = 'block';
        document.getElementById('models-list').style.display = 'none';
        document.getElementById('btn-generate').disabled = true;

        try {
            const response = await fetch(TYPO3.settings.ajaxUrls['nrllm_wizard_discover'], {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    endpoint: this.data.endpoint,
                    apiKey: this.data.apiKey,
                    adapterType: this.data.adapterType,
                }),
            });

            const result = await response.json();

            document.getElementById('models-loading').style.display = 'none';
            document.getElementById('models-list').style.display = 'block';

            if (result.success && result.models) {
                this.data.models = result.models;
                this.renderModelsTable(result.models);
                this.updateGenerateButton();
            }
        } catch (e) {
            document.getElementById('models-loading').style.display = 'none';
            document.getElementById('models-list').innerHTML =
                '<div class="alert alert-danger">Failed to discover models: ' + e.message + '</div>';
        }
    }

    renderModelsTable(models) {
        const tbody = document.querySelector('#models-table tbody');
        tbody.innerHTML = '';

        models.forEach((model, index) => {
            const capabilities = (model.capabilities || ['chat']).map(cap =>
                `<span class="badge bg-secondary">${cap}</span>`
            ).join('');

            const contextStr = model.contextLength ?
                (model.contextLength >= 1000000 ?
                    `${(model.contextLength / 1000000).toFixed(1)}M` :
                    `${(model.contextLength / 1000).toFixed(0)}K`) : '-';

            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="form-check-input model-checkbox"
                           data-index="${index}" ${model.recommended ? 'checked' : ''}
                           aria-label="Select model ${model.name}">
                </td>
                <td>
                    <strong>${model.name}</strong>
                    <div class="small text-muted">${model.modelId}</div>
                    ${model.description ? `<div class="small text-muted">${model.description}</div>` : ''}
                </td>
                <td>${contextStr}</td>
                <td>${capabilities}</td>
                <td>
                    ${model.recommended ?
                        '<span class="badge bg-success">Recommended</span>' :
                        '<span class="text-muted">-</span>'}
                </td>
            `;
            tbody.appendChild(row);

            // Add change listener
            row.querySelector('.model-checkbox').addEventListener('change', () => this.updateGenerateButton());
        });
    }

    toggleAllModels(checked) {
        document.querySelectorAll('.model-checkbox').forEach(cb => {
            cb.checked = checked;
        });
        this.updateGenerateButton();
    }

    updateGenerateButton() {
        const checkedCount = document.querySelectorAll('.model-checkbox:checked').length;
        document.getElementById('btn-generate').disabled = checkedCount === 0;
    }

    getSelectedModels() {
        const selected = [];
        document.querySelectorAll('.model-checkbox:checked').forEach(cb => {
            const index = parseInt(cb.dataset.index, 10);
            if (this.data.models[index]) {
                selected.push({
                    ...this.data.models[index],
                    selected: true,
                });
            }
        });
        return selected;
    }

    async generateConfigurations() {
        const selectedModels = this.getSelectedModels();

        if (selectedModels.length === 0) {
            Notification.warning('Validation Error', 'Please select at least one model', 5);
            return;
        }

        this.goToStep(4);

        // Reset step 4 UI
        document.getElementById('configs-loading').style.display = 'block';
        document.getElementById('configs-list').style.display = 'none';

        try {
            const response = await fetch(TYPO3.settings.ajaxUrls['nrllm_wizard_generate'], {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    endpoint: this.data.endpoint,
                    apiKey: this.data.apiKey,
                    adapterType: this.data.adapterType,
                    models: selectedModels,
                }),
            });

            const result = await response.json();

            document.getElementById('configs-loading').style.display = 'none';
            document.getElementById('configs-list').style.display = 'block';

            if (result.success && result.configurations) {
                this.data.configurations = result.configurations;
                this.renderConfigCards(result.configurations);
            }
        } catch (e) {
            document.getElementById('configs-loading').style.display = 'none';
            document.getElementById('configs-list').innerHTML =
                '<div class="alert alert-danger">Failed to generate configurations: ' + e.message + '</div>';
        }
    }

    renderConfigCards(configurations) {
        const container = document.getElementById('configs-container');
        container.innerHTML = '';

        configurations.forEach((config, index) => {
            const col = document.createElement('div');
            col.className = 'col-md-6 mb-3';
            col.innerHTML = `
                <div class="card config-card selected" data-index="${index}">
                    <div class="card-header">
                        <input type="checkbox" class="form-check-input config-check" checked
                               aria-label="Select configuration ${config.name}">
                        <strong>${config.name}</strong>
                    </div>
                    <div class="card-body">
                        <p class="card-text text-muted small">${config.description}</p>
                        <div class="d-flex gap-3 mt-2">
                            <span class="config-temp" title="Temperature">
                                <span class="badge bg-info">T: ${config.temperature}</span>
                            </span>
                            <span class="config-tokens" title="Max Tokens">
                                <span class="badge bg-secondary">${config.maxTokens} tokens</span>
                            </span>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(col);

            // Add click handlers
            const card = col.querySelector('.config-card');
            const checkbox = col.querySelector('.config-check');

            card.addEventListener('click', (e) => {
                if (e.target !== checkbox) {
                    checkbox.checked = !checkbox.checked;
                }
                card.classList.toggle('selected', checkbox.checked);
            });

            checkbox.addEventListener('change', () => {
                card.classList.toggle('selected', checkbox.checked);
            });
        });
    }

    getSelectedConfigurations() {
        const selected = [];
        document.querySelectorAll('.config-card').forEach(card => {
            const checkbox = card.querySelector('.config-check');
            const index = parseInt(card.dataset.index, 10);
            if (checkbox.checked && this.data.configurations[index]) {
                selected.push({
                    ...this.data.configurations[index],
                    selected: true,
                });
            }
        });
        return selected;
    }

    reviewConfiguration() {
        this.goToStep(5);

        const selectedModels = this.getSelectedModels();
        const selectedConfigs = this.getSelectedConfigurations();

        // Provider info
        const providerInfo = this.data.provider || {
            suggestedName: 'Provider',
            adapterType: this.data.adapterType,
            endpoint: this.data.endpoint,
        };

        document.getElementById('review-provider').innerHTML = `
            <div class="provider-icon">
                <span class="icon icon-size-medium">
                    <span class="icon-markup">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
                            <path d="M8 0l8 4v8l-8 4-8-4V4l8-4zm0 2L2 5v6l6 3 6-3V5L8 2z"/>
                        </svg>
                    </span>
                </span>
            </div>
            <div>
                <strong>${providerInfo.suggestedName}</strong>
                <span class="badge bg-secondary ms-2">${providerInfo.adapterType}</span>
                <div class="small text-muted">${providerInfo.endpoint}</div>
            </div>
        `;

        // Models list
        document.getElementById('review-models-count').textContent = selectedModels.length;
        const modelsUl = document.getElementById('review-models');
        modelsUl.innerHTML = selectedModels.map(m => `
            <li class="list-group-item">
                <span class="badge bg-primary">${m.modelId}</span>
                <span>${m.name}</span>
            </li>
        `).join('');

        // Configurations list
        document.getElementById('review-configs-count').textContent = selectedConfigs.length;
        const configsUl = document.getElementById('review-configs');
        configsUl.innerHTML = selectedConfigs.map(c => `
            <li class="list-group-item">
                <span class="badge bg-info">${c.identifier}</span>
                <span>${c.name}</span>
            </li>
        `).join('');

        // Show review content
        document.getElementById('review-content').style.display = 'block';
        document.getElementById('save-loading').style.display = 'none';
        document.getElementById('save-success').style.display = 'none';
        document.getElementById('save-error').style.display = 'none';
        document.getElementById('save-footer').style.display = 'flex';
    }

    async saveConfiguration() {
        const selectedModels = this.getSelectedModels();
        const selectedConfigs = this.getSelectedConfigurations();
        const pid = parseInt(document.getElementById('wizard-pid').value, 10) || 0;

        const providerData = {
            ...this.data.provider,
            apiKey: this.data.apiKey,
        };

        // Show loading
        document.getElementById('review-content').style.display = 'none';
        document.getElementById('save-loading').style.display = 'block';
        document.getElementById('save-footer').style.display = 'none';

        try {
            const response = await fetch(TYPO3.settings.ajaxUrls['nrllm_wizard_save'], {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    provider: providerData,
                    models: selectedModels,
                    configurations: selectedConfigs,
                    pid: pid,
                }),
            });

            const result = await response.json();

            document.getElementById('save-loading').style.display = 'none';

            if (result.success) {
                document.getElementById('save-success').style.display = 'block';
                document.getElementById('save-success-message').textContent =
                    `Created ${result.modelsCount} models and ${result.configurationsCount} configurations for "${result.provider.name}"`;

                Notification.success('Success', 'LLM configuration saved successfully', 5);

                // Update "View Providers" link
                const providersLink = document.getElementById('btn-go-providers');
                if (providersLink && TYPO3.settings.ajaxUrls['nrllm_providers_list']) {
                    providersLink.href = TYPO3.settings.ajaxUrls['nrllm_providers_list'];
                }
            } else {
                document.getElementById('save-error').style.display = 'block';
                document.getElementById('save-error-message').textContent = result.error;
                document.getElementById('save-footer').style.display = 'flex';
                Notification.error('Error', result.error, 10);
            }
        } catch (e) {
            document.getElementById('save-loading').style.display = 'none';
            document.getElementById('save-error').style.display = 'block';
            document.getElementById('save-error-message').textContent = 'Network error: ' + e.message;
            document.getElementById('save-footer').style.display = 'flex';
            Notification.error('Network Error', e.message, 10);
        }
    }

    restart() {
        // Reset all data
        this.data = {
            endpoint: '',
            apiKey: '',
            adapterType: '',
            provider: null,
            models: [],
            configurations: [],
        };

        // Reset form fields
        document.getElementById('wizard-endpoint').value = '';
        document.getElementById('wizard-apikey').value = '';
        document.getElementById('detected-provider').style.display = 'none';
        document.getElementById('adapter-override').style.display = 'none';
        document.getElementById('show-adapter-override').style.display = 'inline-block';

        // Go back to step 1
        this.goToStep(1);
    }
}

// Initialize when DOM is ready
export default new SetupWizard();
