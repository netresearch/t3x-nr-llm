/**
 * ModelIdField — TCA custom element JS for model_id with fetch + rich dropdown.
 *
 * Attaches click handler to ".js-fetch-models" buttons, fetches available
 * models from the provider's API, renders a rich dropdown showing model details
 * (context length, capabilities, cost), and auto-fills related fields on selection.
 */
import Notification from '@typo3/backend/notification.js';

(function () {
    'use strict';

    /**
     * Find the TYPO3 FormEngine field name for a given column in the same record.
     * TYPO3 FormEngine names follow the pattern: data[table][uid][column]
     */
    function findFormEngineInput(tableName, inputName, column) {
        const match = inputName.match(/^data\[([^\]]+)\]\[([^\]]+)\]/);
        if (!match) {
            return null;
        }
        const fieldName = 'data[' + match[1] + '][' + match[2] + '][' + column + ']';
        return document.querySelector('[data-formengine-input-name="' + fieldName + '"]')
            || document.querySelector('[name="' + fieldName + '"]');
    }

    /**
     * Format token count as human-readable string.
     */
    function formatTokens(count) {
        if (!count || count === 0) return '';
        if (count >= 1000000) return (count / 1000000).toFixed(count % 1000000 === 0 ? 0 : 1) + 'M';
        if (count >= 1000) return (count / 1000).toFixed(count % 1000 === 0 ? 0 : 1) + 'K';
        return String(count);
    }

    /**
     * Format cost (cents per 1M tokens) as dollar string.
     */
    function formatCost(cents) {
        if (!cents || cents === 0) return '';
        return '$' + (cents / 100).toFixed(2);
    }

    /**
     * Render capability badges as text.
     */
    function renderCapabilities(capabilities) {
        if (!capabilities?.length) return '';
        const labels = {
            chat: 'Chat', completion: 'Completion', embeddings: 'Embed',
            vision: 'Vision', streaming: 'Stream', tools: 'Tools',
            json_mode: 'JSON', audio: 'Audio', reasoning: 'Reasoning',
            image: 'Image', text_to_speech: 'TTS', transcription: 'Transcribe'
        };
        return capabilities.map(function (cap) {
            return labels[cap] || cap;
        }).join(' \u00b7 ');
    }

    /**
     * Create a rich dropdown item for a model.
     */
    function createModelItem(model, onSelect) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action p-2';
        item.style.cursor = 'pointer';

        // Top row: model name + recommended badge
        const topRow = document.createElement('div');
        topRow.className = 'd-flex justify-content-between align-items-center';

        const nameEl = document.createElement('strong');
        nameEl.className = 'text-truncate';
        nameEl.style.maxWidth = '280px';
        nameEl.textContent = model.name || model.id;
        topRow.appendChild(nameEl);

        if (model.recommended) {
            const badge = document.createElement('span');
            badge.className = 'badge bg-success ms-1';
            badge.textContent = 'recommended';
            badge.style.fontSize = '0.7em';
            topRow.appendChild(badge);
        }

        item.appendChild(topRow);

        // Model ID (if different from name)
        if (model.name && model.name !== model.id) {
            const idEl = document.createElement('div');
            idEl.className = 'text-muted small font-monospace';
            idEl.textContent = model.id;
            item.appendChild(idEl);
        }

        // Description
        if (model.description) {
            const descEl = document.createElement('div');
            descEl.className = 'small text-body-secondary';
            descEl.style.whiteSpace = 'nowrap';
            descEl.style.overflow = 'hidden';
            descEl.style.textOverflow = 'ellipsis';
            descEl.textContent = model.description;
            item.appendChild(descEl);
        }

        // Metadata row: context, output, cost, capabilities
        const metaParts = [];
        const ctx = formatTokens(model.contextLength);
        if (ctx) metaParts.push('Ctx: ' + ctx);
        const maxOut = formatTokens(model.maxOutputTokens);
        if (maxOut) metaParts.push('Out: ' + maxOut);
        const costIn = formatCost(model.costInput);
        const costOut = formatCost(model.costOutput);
        if (costIn && costOut) metaParts.push(costIn + ' / ' + costOut + ' per 1M');
        const caps = renderCapabilities(model.capabilities);
        if (caps) metaParts.push(caps);

        if (metaParts.length > 0) {
            const metaRow = document.createElement('div');
            metaRow.className = 'small mt-1';
            metaRow.style.color = '#888';
            metaRow.style.fontSize = '0.78em';
            metaRow.textContent = metaParts.join('  \u2502  ');
            item.appendChild(metaRow);
        }

        item.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            onSelect(model);
        });

        return item;
    }

    /**
     * Auto-fill related TCA fields when a model is selected.
     */
    function autoFillFields(model, tableName, inputName) {
        if (!model) return;

        if (model.contextLength) {
            const ctxInput = findFormEngineInput(tableName, inputName, 'context_length');
            if (ctxInput) setFieldValue(ctxInput, String(model.contextLength));
        }

        if (model.maxOutputTokens) {
            const maxInput = findFormEngineInput(tableName, inputName, 'max_output_tokens');
            if (maxInput) setFieldValue(maxInput, String(model.maxOutputTokens));
        }

        if (model.costInput) {
            const costInInput = findFormEngineInput(tableName, inputName, 'cost_input');
            if (costInInput) setFieldValue(costInInput, String(model.costInput));
        }

        if (model.costOutput) {
            const costOutInput = findFormEngineInput(tableName, inputName, 'cost_output');
            if (costOutInput) setFieldValue(costOutInput, String(model.costOutput));
        }

        autoFillCapabilities(model, inputName);
    }

    /**
     * Tick capability checkboxes matching the selected model's capabilities.
     */
    function autoFillCapabilities(model, inputName) {
        if (!model.capabilities || !Array.isArray(model.capabilities)) {
            return;
        }
        const match = inputName.match(/^data\[([^\]]+)\]\[([^\]]+)\]/);
        if (!match) {
            return;
        }
        const selector = '[name^="data[' + match[1] + '][' + match[2] + '][capabilities]"]';
        document.querySelectorAll(selector).forEach(function (el) {
            if (el.type === 'checkbox') {
                el.checked = model.capabilities.includes(el.value);
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    /**
     * Set a FormEngine field value and trigger change events.
     */
    function setFieldValue(input, value) {
        input.value = value;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        const actualName = input.dataset.formengineInputName;
        if (actualName) {
            const hidden = document.querySelector('input[name="' + actualName + '"][type="hidden"]');
            if (hidden) hidden.value = value;
        }
    }

    /**
     * Show a status message below the input group.
     */
    function showStatus(button, message, type) {
        const container = button.closest('.form-control-wrap');
        if (!container) return;
        const statusEl = container.querySelector('.js-model-status');
        if (!statusEl) return;
        statusEl.textContent = message;
        statusEl.className = 'js-model-status mt-1 text-' + (type === 'error' ? 'danger' : 'success');
        statusEl.style.display = 'block';
        if (type !== 'error') {
            setTimeout(function () { statusEl.style.display = 'none'; }, 5000);
        }
    }

    /**
     * Create or get the dropdown container for a button.
     */
    function getOrCreateDropdown(button) {
        const wrap = button.closest('.form-control-wrap');
        const existing = wrap.querySelector('.js-model-dropdown');
        if (existing) return existing;

        const dropdown = document.createElement('div');
        dropdown.className = 'js-model-dropdown list-group shadow-sm border';
        dropdown.style.position = 'absolute';
        dropdown.style.zIndex = '1060';
        dropdown.style.maxHeight = '400px';
        dropdown.style.overflowY = 'auto';
        dropdown.style.width = '100%';
        dropdown.style.display = 'none';
        dropdown.style.backgroundColor = 'var(--bs-body-bg, #fff)';

        const inputGroup = wrap.querySelector('.input-group');
        if (inputGroup) {
            inputGroup.style.position = 'relative';
            inputGroup.appendChild(dropdown);
            dropdown.style.top = inputGroup.offsetHeight + 'px';
            dropdown.style.left = '0';
        } else {
            wrap.appendChild(dropdown);
        }

        return dropdown;
    }

    /**
     * Filter models by a lowercased query string against id, name, description and capabilities.
     */
    function filterModels(models, q) {
        return models.filter(function (m) {
            return m.id?.toLowerCase().includes(q)
                || m.name?.toLowerCase().includes(q)
                || m.description?.toLowerCase().includes(q)
                || m.capabilities?.some(function (c) { return c.includes(q); });
        });
    }

    /**
     * Render the model dropdown with filter.
     */
    function renderDropdown(dropdown, models, input, button) {
        dropdown.replaceChildren();

        const tableName = button.dataset.table || 'tx_nrllm_model';
        const inputName = input.dataset.formengineInputName || input.name;

        // Filter input
        const filterWrap = document.createElement('div');
        filterWrap.className = 'p-2 border-bottom sticky-top';
        filterWrap.style.backgroundColor = 'var(--bs-body-bg, #fff)';

        const filterInput = document.createElement('input');
        filterInput.type = 'text';
        filterInput.className = 'form-control form-control-sm';
        filterInput.placeholder = 'Filter models...';
        filterWrap.appendChild(filterInput);

        const countEl = document.createElement('div');
        countEl.className = 'text-muted small mt-1';
        countEl.textContent = models.length + ' models available';
        filterWrap.appendChild(countEl);

        dropdown.appendChild(filterWrap);

        // Model list container
        const listContainer = document.createElement('div');
        listContainer.className = 'js-model-list';
        dropdown.appendChild(listContainer);

        function onSelect(model) {
            input.value = model.id;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            autoFillFields(model, tableName, inputName);
            dropdown.style.display = 'none';
            showStatus(button, 'Selected: ' + (model.name || model.id) + ' \u2014 fields auto-filled', 'success');
        }

        function renderItems(filteredModels) {
            listContainer.replaceChildren();
            if (filteredModels.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item text-muted small';
                empty.textContent = 'No models match filter';
                listContainer.appendChild(empty);
                return;
            }
            filteredModels.forEach(function (model) {
                listContainer.appendChild(createModelItem(model, onSelect));
            });
            countEl.textContent = filteredModels.length + ' of ' + models.length + ' models';
        }

        renderItems(models);

        // Filter handler with debounce
        let filterTimer;
        filterInput.addEventListener('input', function () {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(function () {
                const q = filterInput.value.toLowerCase().trim();
                if (!q) {
                    renderItems(models);
                    return;
                }
                renderItems(filterModels(models, q));
            }, 150);
        });

        // Keyboard: Escape closes dropdown
        filterInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                dropdown.style.display = 'none';
                input.focus();
            }
        });

        dropdown.style.display = 'block';
        setTimeout(function () { filterInput.focus(); }, 50);
    }

    /**
     * Handle "Fetch Models" button click.
     */
    function handleFetchClick(event) {
        const button = event.currentTarget;
        const fetchUrl = button.dataset.fetchUrl;
        const inputId = button.dataset.inputId;
        const tableName = button.dataset.table;

        const input = document.getElementById(inputId);
        if (!input) return;
        const inputName = input.dataset.formengineInputName || input.name;

        // Toggle dropdown if already open
        const existingDropdown = button.closest('.form-control-wrap').querySelector('.js-model-dropdown');
        if (existingDropdown?.style.display === 'block') {
            existingDropdown.style.display = 'none';
            return;
        }

        // Read current provider_uid
        let providerUid = Number.parseInt(button.dataset.providerUid, 10) || 0;
        const providerInput = findFormEngineInput(tableName, inputName, 'provider_uid');
        if (providerInput) {
            providerUid = Number.parseInt(providerInput.value, 10) || providerUid;
        }

        if (providerUid === 0) {
            showStatus(button, 'Please select a provider first.', 'error');
            return;
        }

        // Reuse cached models if same provider
        if (input._fetchedModels && input._fetchedModels.length > 0 && input._fetchedProviderUid === providerUid) {
            const dropdown = getOrCreateDropdown(button);
            renderDropdown(dropdown, input._fetchedModels, input, button);
            return;
        }

        button.disabled = true;
        const originalHTML = button.innerHTML;
        button.textContent = 'Fetching\u2026';

        const body = new FormData();
        body.append('providerUid', String(providerUid));

        fetch(fetchUrl, { method: 'POST', body: body })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) {
                    showStatus(button, 'Error: ' + (data.error || 'Unknown error'), 'error');
                    return;
                }

                if (data.source === 'fallback') {
                    Notification.warning(
                        'Model discovery',
                        'Live model discovery failed — showing the built-in catalog. See system log.',
                        10
                    );
                }

                const models = data.models || [];
                input._fetchedModels = models;
                input._fetchedProviderUid = providerUid;

                if (models.length === 0) {
                    showStatus(button, 'No models found from ' + (data.providerName || 'provider'), 'error');
                    return;
                }

                const dd = getOrCreateDropdown(button);
                renderDropdown(dd, models, input, button);
                showStatus(button, models.length + ' models from ' + (data.providerName || 'provider'), 'success');
            })
            .catch(function (err) {
                showStatus(button, 'Fetch failed: ' + err.message, 'error');
            })
            .finally(function () {
                button.disabled = false;
                // Restore the original server-rendered button content (icon + text)
                button.innerHTML = originalHTML; // eslint-disable-line no-unsanitized/property
            });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.js-model-dropdown').forEach(function (dropdown) {
            if (!dropdown.contains(e.target) && !e.target.classList.contains('js-fetch-models')) {
                dropdown.style.display = 'none';
            }
        });
    });

    // Invalidate cache when provider changes
    document.addEventListener('change', function (e) {
        const target = e.target;
        if (!target?.name) return;
        if (!target.name.includes('[provider_uid]')) return;
        document.querySelectorAll('.js-fetch-models').forEach(function (button) {
            const inputId = button.dataset.inputId;
            const input = document.getElementById(inputId);
            if (input) {
                input._fetchedModels = null;
                input._fetchedProviderUid = null;
            }
        });
    });

    // Initialize
    function init() {
        document.querySelectorAll('.js-fetch-models').forEach(function (button) {
            button.addEventListener('click', handleFetchClick);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
