/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Shared AJAX error reader for the nr_llm backend ES modules.
 *
 * TYPO3's AjaxRequest rejects on a non-2xx response with an AjaxResponse object
 * (which exposes resolve()), and on a genuine network failure with a plain
 * Error. This normalises both into a human-readable message, preferring the
 * JSON body's `error`/`message` field when one is present. Extracted so every
 * backend module shares one implementation instead of duplicating it.
 *
 * The body may be any JSON value, `null` included, so it is read only when it
 * is an object; otherwise reading `.error` would throw inside the caller's
 * catch block and leave its loading state on screen.
 *
 * @param {unknown} err the value an AjaxRequest promise rejected with
 * @returns {Promise<string>} a message suitable for Notification.error()
 */
export async function readAjaxError(err) {
    if (err && typeof err.resolve === 'function') {
        const data = await err.resolve().catch(() => null);
        if (data !== null && typeof data === 'object') {
            return data.error || data.message || 'Unknown error';
        }
        return 'Unknown error';
    }
    return err?.message || 'Unknown error';
}
