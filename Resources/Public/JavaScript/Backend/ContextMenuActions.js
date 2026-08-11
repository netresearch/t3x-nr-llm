/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

import Viewport from '@typo3/backend/viewport.js';

/**
 * Callback module for this extension's context-menu items (ADR-158).
 *
 * The core context menu imports the module named by `data-callback-module` and
 * calls `default[callbackAction](table, uid, dataset)`. The dataset carries the
 * remaining `data-*` attributes, camel-cased — `data-catalogue-url` becomes
 * `catalogueUrl`.
 *
 * Navigation only: the URL is built server-side by
 * `EditorActionItemProvider::getAdditionalAttributes()`, complete with the
 * module route token, and nothing is started from here.
 */
class ContextMenuActions {
    /**
     * Open the Editor Action Center for the record the menu was opened on.
     *
     * @param {string} table
     * @param {string|number} uid
     * @param {{catalogueUrl?: string}} data
     */
    static openEditorActionCenter(table, uid, data) {
        const url = data.catalogueUrl;
        if (typeof url === 'string' && url !== '') {
            Viewport.ContentContainer.setUrl(url);
        }
    }
}

export default ContextMenuActions;
