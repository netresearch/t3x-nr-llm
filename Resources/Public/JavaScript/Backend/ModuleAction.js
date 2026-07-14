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
 * not copy the boilerplate again (the older sibling modules still carry
 * their own copies — migrating them is mechanical follow-up work).
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { readAjaxError } from '@netresearch/nr-llm/Backend/AjaxError.js';

/**
 * @param {string} url Resolved AJAX endpoint URL
 * @param {FormData} formData POST payload
 * @param {HTMLButtonElement} btn Triggering button; disabled during flight, re-enabled on failure
 */
export function postAndReload(url, formData, btn) {
    btn.disabled = true;
    new AjaxRequest(url)
        .post(formData)
        .then(response => response.resolve())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                Notification.error('Error', data.error || 'Unknown error');
                btn.disabled = false;
            }
        })
        .catch(async err => {
            Notification.error('Error', await readAjaxError(err));
            btn.disabled = false;
        });
}
