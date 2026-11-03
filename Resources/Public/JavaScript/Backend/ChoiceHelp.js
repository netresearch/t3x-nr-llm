/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * The text under a record select follows the selection (ADR-255).
 *
 * The wizard element carries a map uid -> {description, facts}. This module
 * ties the element to the field's selects with aria-describedby (read when
 * the select gets focus) and rewrites it when an option is chosen; the
 * element is a polite live region, so the new text is read after the
 * option name rather than cutting it off.
 *
 * selectSingle: the chosen option. selectMultipleSideBySide: the option last
 * activated in either list - the available list as the user moves through
 * it, the chosen list when they pick an item there.
 *
 * Values are written with textContent only.
 */
import DocumentService from '@typo3/core/document-service.js';

/**
 * @param {Record<string, {description: string, facts: string[]}>} map
 * @param {string} value
 * @returns {{description: string, facts: string[]}|null}
 */
export function summaryFor(map, value) {
    if (typeof value !== 'string' || value === '' || !Object.prototype.hasOwnProperty.call(map, value)) {
        return null;
    }
    const entry = map[value];
    return {
        description: typeof entry.description === 'string' ? entry.description : '',
        facts: Array.isArray(entry.facts) ? entry.facts.filter((fact) => typeof fact === 'string' && fact !== '') : [],
    };
}

/**
 * The option a change of this select is about: the chosen one of a single
 * select, the last activated one of a multiple select.
 *
 * @param {HTMLSelectElement} select
 * @returns {string}
 */
export function activeValue(select) {
    const selected = Array.from(select.selectedOptions);
    if (selected.length === 0) {
        return '';
    }
    return selected[selected.length - 1].value;
}

/**
 * @param {HTMLElement} element
 * @param {{description: string, facts: string[]}|null} summary
 */
export function render(element, summary) {
    const parts = [];
    if (summary !== null && summary.description !== '') {
        const description = document.createElement('span');
        description.className = 'nrllm-choice-help-description d-block';
        description.textContent = summary.description;
        parts.push(description);
    }
    if (summary !== null && summary.facts.length > 0) {
        const facts = document.createElement('span');
        facts.className = 'nrllm-choice-help-facts d-block form-description';
        facts.textContent = summary.facts.join(' · ');
        parts.push(facts);
    }
    element.replaceChildren(...parts);
}

/**
 * @param {Element} select
 * @param {string} id
 */
function describe(select, id) {
    const ids = (select.getAttribute('aria-describedby') ?? '').split(/\s+/).filter((token) => token !== '');
    if (!ids.includes(id)) {
        ids.push(id);
        select.setAttribute('aria-describedby', ids.join(' '));
    }
}

class ChoiceHelp {
    /**
     * @param {string} selector
     */
    constructor(selector) {
        DocumentService.ready().then(() => {
            const element = document.querySelector(selector);
            if (!(element instanceof HTMLElement)) {
                return;
            }
            let map = {};
            try {
                map = JSON.parse(element.dataset.nrllmChoiceHelp ?? '{}') ?? {};
            } catch {
                return;
            }
            const field = element.closest('.formengine-field-item');
            if (field === null) {
                return;
            }
            for (const select of field.querySelectorAll('select')) {
                describe(select, element.id);
                select.addEventListener('change', () => render(element, summaryFor(map, activeValue(select))));
            }
        });
    }
}

export default ChoiceHelp;
