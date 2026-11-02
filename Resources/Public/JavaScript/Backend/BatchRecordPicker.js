/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * The pickers of the batch form (EditorAction/Batch.html): the records and,
 * for pages and files, the page or folder a background batch starts from.
 *
 * Each picker opens TYPO3's element browser in the backend modal and writes
 * what was picked into its hidden field (`recordUids` as a comma list,
 * `scope` as a page uid or a combined folder identifier). The form posts the
 * same arguments it posted when the editor typed them, and the server checks
 * every one of them again.
 *
 * TYPO3 14 reports a pick as an event on the browser's modal (`useEvents` in
 * the browser URL, see EditorActionController::elementBrowserUrl()); TYPO3
 * 13.4 posts it as a message to this module frame. Both carry the same action
 * name, field reference, value and label.
 *
 * Loaded by EditorActionController::batchAction() as an ES module, not inline:
 * the backend Content Security Policy blocks inline scripts without a nonce.
 */
import Modal from '@typo3/backend/modal.js';

const ELEMENT_ADDED = 'typo3:elementBrowser:elementAdded';

/** A label with one %s placeholder, filled. */
function format(template, value) {
    return String(template ?? '').replace('%s', value);
}

/**
 * What the hidden field stores for a pick, or '' for a pick that does not
 * belong here. The three browsers report three shapes:
 * - the folder browser the combined identifier ("1:/products/"), kept as is;
 * - the file browser the bare file uid ("12");
 * - the record browser "<table>_<uid>" ("tt_content_5"). Table names contain
 *   underscores, so the expected table is stripped as a prefix.
 */
function valueOf(kind, recordTable, value) {
    const raw = String(value ?? '');
    if (kind === 'folder') {
        return /^\d+:\//.test(raw) ? raw : '';
    }
    if (kind === 'file') {
        return /^[1-9]\d*$/.test(raw) ? raw : '';
    }
    const prefix = (kind === 'page' ? 'pages' : recordTable) + '_';
    if (recordTable === '' && kind !== 'page') {
        return '';
    }
    const uid = raw.startsWith(prefix) ? raw.slice(prefix.length) : '';
    return /^[1-9]\d*$/.test(uid) ? uid : '';
}

/**
 * Open the element browser and hand each pick of this picker to `take`.
 *
 * @param {object} options
 * @param {HTMLButtonElement} options.browse The button that opened it; it gets the focus back
 * @param {string} options.url
 * @param {string} options.fieldReference Only picks carrying it are taken
 * @param {boolean} options.multiple Several picks keep the browser open
 * @param {function(string, string): void} options.take
 */
function openBrowser({ browse, url, fieldReference, multiple, take }) {
    const modal = Modal.advanced({
        type: Modal.types.iframe,
        content: url,
        size: Modal.sizes.large,
    });
    let hidden = false;
    let picked = false;
    // TYPO3 13.4's Bootstrap modal ignores a hide while it is still fading
    // in, so a pick made that early closes the browser once it is shown.
    let shown = false;
    let closeWhenShown = false;
    modal.addEventListener('typo3-modal-shown', () => {
        shown = true;
        if (closeWhenShown && !hidden) {
            modal.hideModal();
        }
    });
    const close = () => {
        if (shown) {
            modal.hideModal();
        } else {
            closeWhenShown = true;
        }
    };
    const accept = (value, label) => {
        if (!multiple && picked) {
            return;
        }
        picked = true;
        take(value, label ?? '');
    };

    // TYPO3 14: the browser stays open after a pick and says in `close`
    // whether the editor asked to close it. One page or folder ends the
    // browsing on any pick.
    modal.addEventListener('typo3:element-browser:message', (event) => {
        const { actionName, fieldName, value, label, close: closeAfterPick } = event.detail ?? {};
        if (actionName !== ELEMENT_ADDED || fieldName !== fieldReference) {
            return;
        }
        accept(value, label);
        if (!multiple || closeAfterPick) {
            close();
        }
    });

    // TYPO3 13.4: the browser closes itself after "add and close" (it
    // dismisses the topmost modal). Hiding it here as well would then hide
    // something else, so a single picker closes it only if it is still open
    // a moment later, and the records picker leaves closing to the browser.
    const onMessage = (event) => {
        // Same origin only, the check MessageUtility.verifyOrigin() makes.
        if (event.origin !== window.origin) {
            return;
        }
        const data = event.data ?? {};
        if (data.actionName !== ELEMENT_ADDED || data.fieldName !== fieldReference) {
            return;
        }
        accept(data.value, data.label);
        if (!multiple) {
            setTimeout(() => {
                if (!hidden) {
                    close();
                }
            }, 150);
        }
    };
    window.addEventListener('message', onMessage);

    modal.addEventListener('typo3-modal-hidden', () => {
        hidden = true;
        window.removeEventListener('message', onMessage);
        // TYPO3 14's <dialog> gives the focus back on its own; TYPO3 13.4's
        // browser focuses the module frame instead.
        if (browse.isConnected) {
            browse.focus();
        }
    });
}

/** The parts every picker has, or null when the markup is incomplete. */
function partsOf(picker) {
    const parts = {
        input: picker.querySelector('input[type="hidden"]'),
        browse: picker.querySelector('[data-picker-browse]'),
        error: picker.querySelector('[data-picker-error]'),
    };
    return Object.values(parts).every(Boolean) ? parts : null;
}

/** Show a message below the picker, or remove it (''). */
function showError(error, message) {
    // textContent paths (not innerHTML): titles and XLF strings are no markup.
    error.textContent = message;
    error.classList.toggle('d-block', message !== '');
}

/**
 * Several records: a list of picks, each with a remove button, kept in the
 * hidden field as a comma list in the order they were picked.
 */
function initMultiple(picker) {
    const parts = partsOf(picker);
    const list = picker.querySelector('[data-picker-list]');
    const summary = picker.querySelector('[data-picker-summary]');
    if (!parts || !list || !summary) {
        return;
    }
    const { input, browse, error } = parts;
    const labels = picker.dataset;
    const kind = labels.pickKind ?? 'record';
    const unnamed = kind === 'file' ? labels.unnamedFileLabel : labels.unnamedLabel;

    // A form re-rendered after "Recalculate" carries the numbers but no
    // titles: those came from the browser, and this page asks the server for
    // none, so a record shows by its number until it is picked again.
    const items = [];
    for (const token of input.value.split(/[\s,]+/)) {
        if (/^[1-9]\d*$/.test(token) && !items.some((item) => item.value === token)) {
            items.push({ value: token, label: format(unnamed, token) });
        }
    }

    const render = () => {
        input.value = items.map((item) => item.value).join(',');
        summary.textContent = items.length === 0 ? labels.noneLabel : format(labels.countLabel, String(items.length));
        list.replaceChildren(...items.map((item) => {
            const row = document.createElement('li');
            row.className = 'd-flex align-items-center gap-2 mb-1';
            const text = document.createElement('span');
            text.className = 'text-break';
            text.textContent = item.label;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-default btn-sm';
            remove.textContent = labels.removeText;
            // The name contains the visible text and says which record goes.
            remove.setAttribute('aria-label', format(labels.removeLabel, item.label));
            remove.addEventListener('click', () => {
                items.splice(items.indexOf(item), 1);
                showError(error, '');
                render();
                browse.focus();
            });
            row.append(text, remove);
            return row;
        }));
        list.hidden = items.length === 0;
    };
    render();

    browse.addEventListener('click', () => {
        openBrowser({
            browse,
            url: browse.dataset.browserUrl,
            fieldReference: labels.fieldReference,
            multiple: true,
            take: (value, label) => {
                const picked = valueOf(kind, labels.recordTable ?? '', value);
                if (picked === '') {
                    showError(error, labels.wrongPick);
                    return;
                }
                showError(error, '');
                const title = label.trim();
                const shown = title !== '' ? `${title} [${picked}]` : format(unnamed, picked);
                const known = items.find((item) => item.value === picked);
                if (known) {
                    known.label = shown;
                } else {
                    items.push({ value: picked, label: shown });
                }
                render();
            },
        });
    });
}

/** One page or one folder: the pick shown in a line, with a remove button. */
function initSingle(picker) {
    const parts = partsOf(picker);
    const item = picker.querySelector('[data-picker-item]');
    const remove = picker.querySelector('[data-picker-remove]');
    if (!parts || !item || !remove) {
        return;
    }
    const { input, browse, error } = parts;
    const labels = picker.dataset;
    const kind = labels.pickKind ?? 'page';

    const show = (value, label) => {
        input.value = value;
        item.textContent = value === '' ? labels.noneLabel : label;
        item.classList.toggle('text-variant', value === '');
        remove.hidden = value === '';
    };
    // After "Recalculate": a folder is its identifier, a page shows by number.
    const current = input.value.trim();
    show(current, kind === 'folder' ? current : format(labels.unnamedLabel, current));

    browse.addEventListener('click', () => {
        const url = browse.dataset.browserUrl;
        openBrowser({
            browse,
            // Open the page tree at the page already chosen.
            url: kind === 'page' && /^[1-9]\d*$/.test(input.value) ? url + '&expandPage=' + input.value : url,
            fieldReference: labels.fieldReference,
            multiple: false,
            take: (value, label) => {
                const picked = valueOf(kind, '', value);
                if (picked === '') {
                    showError(error, labels.wrongPick);
                    return;
                }
                showError(error, '');
                const title = label.trim();
                if (kind === 'folder') {
                    show(picked, picked);
                } else {
                    show(picked, title !== '' ? `${title} [${picked}]` : format(labels.unnamedLabel, picked));
                }
            },
        });
    });
    remove.addEventListener('click', () => {
        show('', '');
        showError(error, '');
        browse.focus();
    });
}

function init() {
    document.querySelectorAll('[data-nrllm-batch-picker="multiple"]').forEach(initMultiple);
    document.querySelectorAll('[data-nrllm-batch-picker="single"]').forEach(initSingle);
}

// The module may be evaluated before the form is parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
