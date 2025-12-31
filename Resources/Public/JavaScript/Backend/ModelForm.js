/**
 * Model form functionality for nr_llm backend module
 *
 * Handles fetching available models from provider API
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

class ModelForm {
    constructor() {
        this.providerSelect = document.getElementById('providerUid');
        this.modelIdInput = document.getElementById('modelId');
        this.modelIdDatalist = document.getElementById('modelIdSuggestions');
        this.fetchButton = document.getElementById('fetchModelsButton');
        this.autoDetectButton = document.getElementById('autoDetectButton');
        this.contextLengthInput = document.getElementById('contextLength');
        this.maxOutputTokensInput = document.getElementById('maxOutputTokens');
        this.costInputInput = document.getElementById('costInput');
        this.costOutputInput = document.getElementById('costOutput');

        if (this.providerSelect && this.modelIdInput && this.fetchButton) {
            this.initializeEventListeners();
        }
    }

    initializeEventListeners() {
        // Fetch models when button is clicked
        this.fetchButton.addEventListener('click', (e) => {
            e.preventDefault();
            this.fetchAvailableModels();
        });

        // Auto-detect limits and capabilities when button is clicked
        if (this.autoDetectButton) {
            this.autoDetectButton.addEventListener('click', (e) => {
                e.preventDefault();
                this.autoDetect();
            });
        }

        // Update datalist on model selection
        if (this.modelIdDatalist) {
            this.modelIdInput.addEventListener('input', () => {
                this.onModelSelected();
            });
        }

        // Enable/disable fetch button based on provider selection
        this.providerSelect.addEventListener('change', () => {
            this.updateFetchButtonState();
            this.updateAutoDetectButtonState();
            this.clearModelSuggestions();
        });

        // Update auto-detect button when model ID changes
        this.modelIdInput.addEventListener('input', () => {
            this.updateAutoDetectButtonState();
        });

        // Initial button states
        this.updateFetchButtonState();
        this.updateAutoDetectButtonState();
    }

    updateFetchButtonState() {
        const hasProvider = this.providerSelect.value !== '';
        this.fetchButton.disabled = !hasProvider;
        if (!hasProvider) {
            this.fetchButton.title = 'Select a provider first';
        } else {
            this.fetchButton.title = 'Fetch available models from provider';
        }
    }

    updateAutoDetectButtonState() {
        if (!this.autoDetectButton) {
            return;
        }
        const hasProvider = this.providerSelect.value !== '';
        const hasModelId = this.modelIdInput.value.trim() !== '';
        this.autoDetectButton.disabled = !hasProvider || !hasModelId;
        if (!hasProvider) {
            this.autoDetectButton.title = 'Select a provider first';
        } else if (!hasModelId) {
            this.autoDetectButton.title = 'Enter a model ID first';
        } else {
            this.autoDetectButton.title = 'Auto-detect model limits and capabilities from provider API';
        }
    }

    clearModelSuggestions() {
        if (this.modelIdDatalist) {
            this.modelIdDatalist.innerHTML = '';
        }
        // Clear stored model data
        this.availableModels = [];
    }

    async fetchAvailableModels() {
        const providerUid = this.providerSelect.value;
        if (!providerUid) {
            Notification.warning('No Provider Selected', 'Please select a provider first.');
            return;
        }

        // Show loading state
        this.fetchButton.disabled = true;
        const originalText = this.fetchButton.innerHTML;
        this.fetchButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';

        try {
            const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.nrllm_model_fetch_available)
                .post({
                    providerUid: providerUid
                });

            const data = await response.resolve();

            if (data.success && data.models) {
                this.availableModels = data.models;
                this.populateModelSuggestions(data.models);
                Notification.success(
                    'Models Loaded',
                    `Found ${data.models.length} model(s) from ${data.providerName}`
                );
            } else {
                throw new Error(data.error || 'Unknown error');
            }
        } catch (error) {
            console.error('Failed to fetch models:', error);
            Notification.error('Failed to Fetch Models', error.message || 'An error occurred while fetching available models.');
            this.clearModelSuggestions();
        } finally {
            // Restore button state
            this.fetchButton.innerHTML = originalText;
            this.updateFetchButtonState();
        }
    }

    populateModelSuggestions(models) {
        if (!this.modelIdDatalist) {
            return;
        }

        this.modelIdDatalist.innerHTML = '';

        models.forEach(model => {
            const option = document.createElement('option');
            option.value = model.id;
            option.textContent = model.name || model.id;
            if (model.contextLength) {
                option.setAttribute('data-context-length', model.contextLength);
            }
            if (model.maxOutputTokens) {
                option.setAttribute('data-max-output-tokens', model.maxOutputTokens);
            }
            if (model.capabilities) {
                option.setAttribute('data-capabilities', model.capabilities.join(','));
            }
            this.modelIdDatalist.appendChild(option);
        });
    }

    onModelSelected() {
        const selectedModelId = this.modelIdInput.value;
        if (!this.availableModels || !selectedModelId) {
            return;
        }

        const selectedModel = this.availableModels.find(m => m.id === selectedModelId);
        if (selectedModel) {
            this.applyModelData(selectedModel, false);
        }
    }

    /**
     * Apply model data to form fields.
     * @param {Object} modelData - The model data to apply
     * @param {boolean} overwrite - Whether to overwrite existing values
     */
    applyModelData(modelData, overwrite = false) {
        // Auto-fill model limits if available
        if (modelData.contextLength && this.contextLengthInput) {
            const currentValue = parseInt(this.contextLengthInput.value, 10) || 0;
            if (overwrite || currentValue === 0) {
                this.contextLengthInput.value = modelData.contextLength;
            }
        }
        if (modelData.maxOutputTokens && this.maxOutputTokensInput) {
            const currentValue = parseInt(this.maxOutputTokensInput.value, 10) || 0;
            if (overwrite || currentValue === 0) {
                this.maxOutputTokensInput.value = modelData.maxOutputTokens;
            }
        }

        // Auto-fill pricing if available
        if (modelData.costInput && this.costInputInput) {
            const currentValue = parseInt(this.costInputInput.value, 10) || 0;
            if (overwrite || currentValue === 0) {
                this.costInputInput.value = modelData.costInput;
            }
        }
        if (modelData.costOutput && this.costOutputInput) {
            const currentValue = parseInt(this.costOutputInput.value, 10) || 0;
            if (overwrite || currentValue === 0) {
                this.costOutputInput.value = modelData.costOutput;
            }
        }

        // Auto-check capabilities
        if (modelData.capabilities && Array.isArray(modelData.capabilities)) {
            modelData.capabilities.forEach(cap => {
                const checkbox = document.getElementById(`cap_${cap}`);
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
        }
    }

    /**
     * Auto-detect model limits and capabilities from provider API.
     */
    async autoDetect() {
        const providerUid = this.providerSelect.value;
        const modelId = this.modelIdInput.value.trim();

        if (!providerUid) {
            Notification.warning('No Provider Selected', 'Please select a provider first.');
            return;
        }

        if (!modelId) {
            Notification.warning('No Model ID', 'Please enter a model ID first.');
            return;
        }

        // Show loading state
        this.autoDetectButton.disabled = true;
        const originalText = this.autoDetectButton.innerHTML;
        this.autoDetectButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Detecting...';

        try {
            const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.nrllm_model_detect_limits)
                .post({
                    providerUid: providerUid,
                    modelId: modelId
                });

            const data = await response.resolve();

            if (data.success) {
                // Apply detected data, overwriting existing values
                this.applyModelData(data, true);

                // Build success message with details
                const details = [];
                if (data.contextLength > 0) {
                    details.push(`Context: ${data.contextLength.toLocaleString()} tokens`);
                }
                if (data.maxOutputTokens > 0) {
                    details.push(`Max output: ${data.maxOutputTokens.toLocaleString()} tokens`);
                }
                if (data.capabilities && data.capabilities.length > 0) {
                    details.push(`Capabilities: ${data.capabilities.join(', ')}`);
                }

                const detailsText = details.length > 0 ? ` (${details.join(', ')})` : '';
                Notification.success(
                    'Auto-detect Complete',
                    `Applied limits and capabilities for "${data.name || modelId}"${detailsText}`
                );
            } else {
                throw new Error(data.error || 'Unknown error');
            }
        } catch (error) {
            console.error('Failed to auto-detect model info:', error);
            Notification.error('Auto-detect Failed', error.message || 'An error occurred while detecting model information.');
        } finally {
            // Restore button state
            this.autoDetectButton.innerHTML = originalText;
            this.updateAutoDetectButtonState();
        }
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new ModelForm());
} else {
    new ModelForm();
}

export default ModelForm;
