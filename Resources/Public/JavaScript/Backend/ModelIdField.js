/**
 * ModelIdField — TCA custom element JS for model_id with fetch + rich dropdown.
 *
 * Attaches click handler to ".js-fetch-models" buttons, fetches available
 * models from the provider's API, renders a rich dropdown showing model details
 * (context length, capabilities, cost), and auto-fills related fields on selection.
 */
(function () {
    'use strict';

    /**
     * Find the TYPO3 FormEngine field name for a given column in the same record.
     * TYPO3 FormEngine names follow the pattern: data[table][uid][column]
     */
    function findFormEngineInput(tableName, inputName, column) {
        var match = inputName.match(/^data\[([^\]]+)\]\[([^\]]+)\]/);
        if (!match) {
            return null;
        }
        var fieldName = 'data[' + match[1] + '][' + match[2] + '][' + column + ']';
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
        if (!capabilities || !capabilities.length) return '';
        var labels = {
            chat: 'Chat', completion: 'Completion', embeddings: 'Embed',
            vision: 'Vision', streaming: 'Stream', tools: 'Tools',
            json_mode: 'JSON', audio: 'Audio', reasoning: 'Reasoning'
        };
        return capabilities.map(function (cap) {
            return labels[cap] || cap;
        }).join(' \u00b7 ');
    }

    /**
     * Create a rich dropdown item for a model.
     */
    function createModelItem(model, onSelect) {
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action p-2';
        item.style.cursor = 'pointer';

        // Top row: model name + recommended badge
        var topRow = document.createElement('div');
        topRow.className = 'd-flex justify-content-between align-items-center';

        var nameEl = document.createElement('strong');
        nameEl.className = 'text-truncate';
        nameEl.style.maxWidth = '280px';
        nameEl.textContent = model.name || model.id;
        topRow.appendChild(nameEl);

        if (model.recommended) {
            var badge = document.createElement('span');
            badge.className = 'badge bg-success ms-1';
            badge.textContent = 'recommended';
            badge.style.fontSize = '0.7em';
            topRow.appendChild(badge);
        }

        item.appendChild(topRow);

        // Model ID (if different from name)
        if (model.name && model.name !== model.id) {
            var idEl = document.createElement('div');
            idEl.className = 'text-muted small font-monospace';
            idEl.textContent = model.id;
            item.appendChild(idEl);
        }

        // Description
        if (model.description) {
            var descEl = document.createElement('div');
            descEl.className = 'small text-body-secondary';
            descEl.style.whiteSpace = 'nowrap';
            descEl.style.overflow = 'hidden';
            descEl.style.textOverflow = 'ellipsis';
            descEl.textContent = model.description;
            item.appendChild(descEl);
        }

        // Metadata row: context, output, cost, capabilities
        var metaParts = [];
        var ctx = formatTokens(model.contextLength);
        if (ctx) metaParts.push('Ctx: ' + ctx);
        var maxOut = formatTokens(model.maxOutputTokens);
        if (maxOut) metaParts.push('Out: ' + maxOut);
        var costIn = formatCost(model.costInput);
        var costOut = formatCost(model.costOutput);
        if (costIn && costOut) metaParts.push(costIn + ' / ' + costOut + ' per 1M');
        var caps = renderCapabilities(model.capabilities);
        if (caps) metaParts.push(caps);

        if (metaParts.length > 0) {
            var metaRow = document.createElement('div');
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
            var ctxInput = findFormEngineInput(tableName, inputName, 'context_length');
            if (ctxInput) setFieldValue(ctxInput, String(model.contextLength));
        }

        if (model.maxOutputTokens) {
            var maxInput = findFormEngineInput(tableName, inputName, 'max_output_tokens');
            if (maxInput) setFieldValue(maxInput, String(model.maxOutputTokens));
        }

        if (model.costInput) {
            var costInInput = findFormEngineInput(tableName, inputName, 'cost_input');
            if (costInInput) setFieldValue(costInInput, String(model.costInput));
        }

        if (model.costOutput) {
            var costOutInput = findFormEngineInput(tableName, inputName, 'cost_output');
            if (costOutInput) setFieldValue(costOutInput, String(model.costOutput));
        }

        if (model.capabilities && Array.isArray(model.capabilities)) {
            var match = inputName.match(/^data\[([^\]]+)\]\[([^\]]+)\]/);
            if (match) {
                var selector = '[name^="data[' + match[1] + '][' + match[2] + '][capabilities]"]';
                document.querySelectorAll(selector).forEach(function (el) {
                    if (el.type === 'checkbox') {
                        el.checked = model.capabilities.includes(el.value);
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            }
        }
    }

    /**
     * Set a FormEngine field value and trigger change events.
     */
    function setFieldValue(input, value) {
        input.value = value;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        var actualName = input.getAttribute('data-formengine-input-name');
        if (actualName) {
            var hidden = document.querySelector('input[name="' + actualName + '"][type="hidden"]');
            if (hidden) hidden.value = value;
        }
    }

    /**
     * Show a status message below the input group.
     */
    function showStatus(button, message, type) {
        var container = button.closest('.form-control-wrap');
        if (!container) return;
        var statusEl = container.querySelector('.js-model-status');
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
        var wrap = button.closest('.form-control-wrap');
        var existing = wrap.querySelector('.js-model-dropdown');
        if (existing) return existing;

        var dropdown = document.createElement('div');
        dropdown.className = 'js-model-dropdown list-group shadow-sm border';
        dropdown.style.position = 'absolute';
        dropdown.style.zIndex = '1060';
        dropdown.style.maxHeight = '400px';
        dropdown.style.overflowY = 'auto';
        dropdown.style.width = '100%';
        dropdown.style.display = 'none';
        dropdown.style.backgroundColor = 'var(--bs-body-bg, #fff)';

        var inputGroup = wrap.querySelector('.input-group');
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
     * Render the model dropdown with filter.
     */
    function renderDropdown(dropdown, models, input, button) {
        dropdown.replaceChildren();

        var tableName = button.getAttribute('data-table') || 'tx_nrllm_model';
        var inputName = input.getAttribute('data-formengine-input-name') || input.name;

        // Filter input
        var filterWrap = document.createElement('div');
        filterWrap.className = 'p-2 border-bottom sticky-top';
        filterWrap.style.backgroundColor = 'var(--bs-body-bg, #fff)';

        var filterInput = document.createElement('input');
        filterInput.type = 'text';
        filterInput.className = 'form-control form-control-sm';
        filterInput.placeholder = 'Filter models...';
        filterWrap.appendChild(filterInput);

        var countEl = document.createElement('div');
        countEl.className = 'text-muted small mt-1';
        countEl.textContent = models.length + ' models available';
        filterWrap.appendChild(countEl);

        dropdown.appendChild(filterWrap);

        // Model list container
        var listContainer = document.createElement('div');
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
                var empty = document.createElement('div');
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
        var filterTimer;
        filterInput.addEventListener('input', function () {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(function () {
                var q = filterInput.value.toLowerCase().trim();
                if (!q) {
                    renderItems(models);
                    return;
                }
                var filtered = models.filter(function (m) {
                    return (m.id && m.id.toLowerCase().includes(q))
                        || (m.name && m.name.toLowerCase().includes(q))
                        || (m.description && m.description.toLowerCase().includes(q))
                        || (m.capabilities && m.capabilities.some(function (c) { return c.includes(q); }));
                });
                renderItems(filtered);
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
        var button = event.currentTarget;
        var fetchUrl = button.getAttribute('data-fetch-url');
        var inputId = button.getAttribute('data-input-id');
        var tableName = button.getAttribute('data-table');

        var input = document.getElementById(inputId);
        if (!input) return;
        var inputName = input.getAttribute('data-formengine-input-name') || input.name;

        // Toggle dropdown if already open
        var existingDropdown = button.closest('.form-control-wrap').querySelector('.js-model-dropdown');
        if (existingDropdown && existingDropdown.style.display === 'block') {
            existingDropdown.style.display = 'none';
            return;
        }

        // Read current provider_uid
        var providerUid = parseInt(button.getAttribute('data-provider-uid'), 10) || 0;
        var providerInput = findFormEngineInput(tableName, inputName, 'provider_uid');
        if (providerInput) {
            providerUid = parseInt(providerInput.value, 10) || providerUid;
        }

        if (providerUid === 0) {
            showStatus(button, 'Please select a provider first.', 'error');
            return;
        }

        // Reuse cached models if same provider
        if (input._fetchedModels && input._fetchedModels.length > 0 && input._fetchedProviderUid === providerUid) {
            var dropdown = getOrCreateDropdown(button);
            renderDropdown(dropdown, input._fetchedModels, input, button);
            return;
        }

        button.disabled = true;
        var originalHTML = button.innerHTML;
        button.textContent = 'Fetching\u2026';

        var body = new FormData();
        body.append('providerUid', String(providerUid));

        fetch(fetchUrl, { method: 'POST', body: body })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) {
                    showStatus(button, 'Error: ' + (data.error || 'Unknown error'), 'error');
                    return;
                }

                var models = data.models || [];
                input._fetchedModels = models;
                input._fetchedProviderUid = providerUid;

                if (models.length === 0) {
                    showStatus(button, 'No models found from ' + (data.providerName || 'provider'), 'error');
                    return;
                }

                var dd = getOrCreateDropdown(button);
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
        var target = e.target;
        if (!target || !target.name) return;
        if (!target.name.includes('[provider_uid]')) return;
        document.querySelectorAll('.js-fetch-models').forEach(function (button) {
            var inputId = button.getAttribute('data-input-id');
            var input = document.getElementById(inputId);
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
