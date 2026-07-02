/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Shared HTML-escaping helper for the nr_llm backend ES modules.
 *
 * LLM and tool responses are untrusted external content. Escape them before
 * building any HTML string (e.g. a sandboxed iframe srcdoc) so markup in the
 * payload cannot execute as script. Extracted from TaskExecute.js so the tool
 * playground and the task runner share one implementation.
 */

// Reusable element for HTML escaping (textContent -> innerHTML round-trip).
const escapeElement = document.createElement('div');

/**
 * Escape HTML entities to prevent XSS attacks.
 * LLM responses are untrusted external content.
 *
 * The textContent -> innerHTML round-trip escapes `&`, `<` and `>` but NOT
 * quotes, so the result is unsafe when interpolated into a quoted HTML
 * attribute value. Escape `"` and `'` as well so the output is safe in both
 * text and attribute contexts.
 *
 * @param {string} text - The text to escape
 * @returns {string} - HTML-escaped text, safe for text and attribute contexts
 */
export function escapeHtml(text) {
    if (typeof text !== 'string') {
        return '';
    }
    escapeElement.textContent = text;
    return escapeElement.innerHTML
        .replaceAll('"', '&quot;')
        .replaceAll('\'', '&#39;');
}
