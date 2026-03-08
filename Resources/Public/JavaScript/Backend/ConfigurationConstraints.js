/**
 * Configuration parameter constraints based on selected model.
 *
 * Watches model_uid select changes and adjusts parameter fields
 * (temperature, top_p, frequency_penalty, presence_penalty) based
 * on the adapter type and model characteristics.
 */
(function () {
    'use strict';

    const CONSTRAINED_FIELDS = ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'];

    /**
     * Find the AJAX URL from the wizard's data attribute.
     */
    function getConstraintsUrl() {
        const configEl = document.querySelector('.js-model-constraints-config');
        return configEl ? configEl.dataset.constraintsUrl : null;
    }

    /**
     * Find all model_uid select elements on the page.
     */
    function findModelSelects() {
        return document.querySelectorAll(
            'select[name*="[model_uid]"]'
        );
    }

    /**
     * Find a parameter field input by column name within the same form.
     * TYPO3 FormEngine names fields like: data[tx_nrllm_configuration][uid][column]
     */
    function findParameterField(fieldName) {
        // Try input fields (number fields)
        const input = document.querySelector(
            'input[data-formengine-input-name*="[' + fieldName + ']"]'
        );
        if (input) return input;

        // Fallback: direct name match
        return document.querySelector(
            'input[name*="[' + fieldName + ']"]:not([type="hidden"])'
        );
    }

    /**
     * Find the hidden (actual value) field that FormEngine uses.
     */
    function findHiddenField(fieldName) {
        return document.querySelector(
            'input[name*="[' + fieldName + ']"][type="hidden"]'
        );
    }

    /**
     * Find the form-control-wrap container for a field.
     */
    function findFieldContainer(fieldName) {
        const input = findParameterField(fieldName);
        if (!input) return null;
        return input.closest('.form-wizards-wrap') || input.closest('.formengine-field-item') || input.parentElement;
    }

    /**
     * Apply constraints to parameter fields.
     */
    function applyConstraints(constraints) {
        for (const fieldName of CONSTRAINED_FIELDS) {
            const constraint = constraints[fieldName];
            if (!constraint) continue;

            const input = findParameterField(fieldName);
            const hiddenField = findHiddenField(fieldName);
            const container = findFieldContainer(fieldName);
            if (!input) continue;

            // Remove any previous constraint overlay
            removeConstraintOverlay(fieldName);

            if (constraint.supported === false) {
                // Field not supported — disable and show hint
                input.disabled = true;
                input.style.opacity = '0.5';
                input.title = constraint.hint || 'Not supported by this model';
                if (container) {
                    addConstraintHint(container, fieldName, constraint.hint || 'Not supported by this model', 'text-warning');
                }
            } else if (constraint.fixed !== undefined) {
                // Field has a fixed value — set it, disable, show hint
                input.value = String(constraint.fixed);
                if (hiddenField) hiddenField.value = String(constraint.fixed);
                input.disabled = true;
                input.style.opacity = '0.7';
                input.title = constraint.hint || 'Fixed value for this model';
                if (container) {
                    addConstraintHint(container, fieldName, constraint.hint || 'Fixed at ' + constraint.fixed, 'text-info');
                }
            } else {
                // Field is supported — ensure enabled, update range
                input.disabled = false;
                input.style.opacity = '';
                input.title = '';
                if (constraint.min !== undefined) input.min = String(constraint.min);
                if (constraint.max !== undefined) input.max = String(constraint.max);
                if (constraint.hint) {
                    if (container) {
                        addConstraintHint(container, fieldName, constraint.hint, 'text-muted');
                    }
                }

                // Clamp current value to new range
                const current = parseFloat(input.value);
                if (!isNaN(current)) {
                    if (constraint.min !== undefined && current < constraint.min) {
                        input.value = String(constraint.min);
                        if (hiddenField) hiddenField.value = String(constraint.min);
                    }
                    if (constraint.max !== undefined && current > constraint.max) {
                        input.value = String(constraint.max);
                        if (hiddenField) hiddenField.value = String(constraint.max);
                    }
                }
            }
        }
    }

    /**
     * Reset all constraints — re-enable all fields.
     */
    function resetConstraints() {
        for (const fieldName of CONSTRAINED_FIELDS) {
            const input = findParameterField(fieldName);
            if (!input) continue;
            input.disabled = false;
            input.style.opacity = '';
            input.title = '';
            removeConstraintOverlay(fieldName);
        }
    }

    /**
     * Add a small hint text below the field.
     */
    function addConstraintHint(container, fieldName, text, cssClass) {
        const hint = document.createElement('div');
        hint.className = 'js-constraint-hint small mt-1 ' + (cssClass || '');
        hint.dataset.constraintField = fieldName;
        hint.textContent = text;
        container.appendChild(hint);
    }

    /**
     * Remove previous constraint hint for a field.
     */
    function removeConstraintOverlay(fieldName) {
        const hints = document.querySelectorAll('.js-constraint-hint[data-constraint-field="' + fieldName + '"]');
        hints.forEach(function (el) { el.remove(); });
    }

    /**
     * Fetch constraints from backend and apply them.
     */
    async function fetchAndApplyConstraints(modelUid) {
        const url = getConstraintsUrl();
        if (!url) return;

        if (!modelUid || modelUid === '0') {
            resetConstraints();
            return;
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'modelUid=' + encodeURIComponent(modelUid),
            });

            if (!response.ok) {
                resetConstraints();
                return;
            }

            const data = await response.json();
            if (data.success && data.constraints) {
                applyConstraints(data.constraints);
            } else {
                resetConstraints();
            }
        } catch (e) {
            resetConstraints();
        }
    }

    /**
     * Initialize: bind to model_uid change events.
     */
    function init() {
        const selects = findModelSelects();
        if (selects.length === 0) return;

        selects.forEach(function (select) {
            // Apply constraints for the initially selected model
            const initialValue = select.value;
            if (initialValue && initialValue !== '0') {
                fetchAndApplyConstraints(initialValue);
            }

            // Watch for changes
            select.addEventListener('change', function () {
                fetchAndApplyConstraints(select.value);
            });
        });
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
