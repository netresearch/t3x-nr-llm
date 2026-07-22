/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Agent Runs approvals inbox — progressive enhancement only (ADR-109).
 *
 * The page is fully operable without this module (native <form> POST, native
 * <details>, native form validation). This only makes the 422 error-summary
 * focus reliable across browsers where `autofocus` on a non-control element is
 * inconsistent, so a keyboard / screen-reader user lands on the error after a
 * failed submit. It adds NO network calls and NO new response path.
 */
class AgentRunInbox {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        // On a 422 re-render exactly one error summary is present. Move focus to
        // it so the failure is announced and the operator's caret is at the
        // problem, not scrolled off at the document top.
        const summary = document.querySelector('[id^="input-errors-"]');
        if (summary instanceof HTMLElement) {
            // A frame yields to the browser's own initial focus handling first.
            window.requestAnimationFrame(() => summary.focus());
        }
    }
}

export default new AgentRunInbox();
