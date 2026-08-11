/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Shared state-changing module action: POST a FormData payload and reload
 * the module on success.
 *
 * Every backend list module repeats the same flow for its row actions —
 * disable the triggering button, POST via AjaxRequest (which injects
 * TYPO3's CSRF token), reload on `{ success: true }`, surface the error
 * and re-enable the button otherwise. Centralised here so new actions do
 * not copy the boilerplate again.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { readAjaxError } from '@netresearch/nr-llm/Backend/AjaxError.js';

/**
 * Resolve a registered backend AJAX endpoint URL.
 *
 * The endpoints live in `TYPO3.settings.ajaxUrls`. When one is missing the
 * module cannot proceed, so surface the same error every handler used to
 * raise inline and return a falsy value for the caller to bail on.
 *
 * @param {string} key AJAX route identifier
 * @returns {string|undefined} the endpoint URL, or a falsy value if not registered
 */
export function resolveAjaxUrl(key) {
    const url = globalThis.TYPO3?.settings?.ajaxUrls?.[key];
    if (!url) {
        Notification.error('Error', 'AJAX URL not configured');
    }
    return url;
}

/**
 * Standard list-row action: resolve the AJAX route, POST the row's
 * `data-uid`, reload on success. The toggle-active / set-default
 * handlers of the list modules are all this exact shape.
 *
 * @param {string} urlKey AJAX route identifier
 * @param {HTMLButtonElement} btn Triggering button carrying `data-uid`
 */
export function postUidAndReload(urlKey, btn) {
    const url = resolveAjaxUrl(urlKey);
    if (!url) {
        return;
    }

    const formData = new FormData();
    formData.append('uid', btn.dataset.uid);
    postAndReload(url, formData, btn);
}

/**
 * POST and hand the payload to the caller, without navigating.
 *
 * The half of {@see postAndReload} that does not reload, for the actions
 * whose answer lives in the response rather than in the reloaded page: a
 * reload runs in the same tick as the callback and destroys anything the
 * callback rendered, so an action that reports something must not reload.
 * The button is re-enabled either way — nothing navigates away from it here.
 *
 * @param {string} url Resolved AJAX endpoint URL
 * @param {FormData} formData POST payload
 * @param {HTMLButtonElement} btn Triggering button; disabled while the request is in flight
 * @param {(data: object) => void} [onSuccess] Callback run with the resolved response payload
 */
export function post(url, formData, btn, onSuccess) {
    btn.disabled = true;
    new AjaxRequest(url)
        .post(formData)
        .then(response => response.resolve())
        .then(data => {
            if (data.success) {
                if (onSuccess) {
                    onSuccess(data);
                }
            } else {
                Notification.error('Error', data.error || 'Unknown error');
            }
            btn.disabled = false;
        })
        .catch(async err => {
            Notification.error('Error', await readAjaxError(err));
            btn.disabled = false;
        });
}

/**
 * @param {string} url Resolved AJAX endpoint URL
 * @param {FormData} formData POST payload
 * @param {HTMLButtonElement} btn Triggering button; disabled during flight, re-enabled on failure
 * @param {(data: object) => void} [onSuccess] Optional callback run with the resolved response
 *        payload before the reload — e.g. to show a success notification with result statistics
 */
export function postAndReload(url, formData, btn, onSuccess) {
    post(url, formData, btn, (data) => {
        if (onSuccess) {
            onSuccess(data);
        }
        location.reload();
    });
}
