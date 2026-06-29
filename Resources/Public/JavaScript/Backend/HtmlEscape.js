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
 * @param {string} text - The text to escape
 * @returns {string} - HTML-escaped text
 */
export function escapeHtml(text) {
    if (typeof text !== 'string') {
        return '';
    }
    escapeElement.textContent = text;
    return escapeElement.innerHTML;
}
