<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\FieldControl;

use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Whether the current backend user may use a field control next to a record
 * select (ADR-255).
 *
 * Core's addRecord and listModule controls render for everyone
 * and leave the refusal to the page they open. A control that only leads to
 * an access error is noise, so the nr-llm controls ask here first and are
 * not rendered when the answer is no.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class RecordControlAccess
{
    public function __construct(private ModuleProvider $moduleProvider) {}

    /**
     * A new record of $table on page $pid: the table must be modifiable and
     * the page must allow editing content. The root level (pid 0) is open to
     * administrators only, unless the table ignores the root level restriction.
     */
    public function mayCreate(string $table, int $pid): bool
    {
        $user = $this->user();
        $ctrl = $this->ctrl($table);
        if (!$user instanceof BackendUserAuthentication || $ctrl === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (!$user->check('tables_modify', $table)) {
            return false;
        }

        if ($pid <= 0) {
            $security = is_array($ctrl['security'] ?? null) ? $ctrl['security'] : [];

            return (bool) ($security['ignoreRootLevelRestriction'] ?? false);
        }

        $page = BackendUtility::getRecord('pages', $pid);

        return is_array($page) && $user->doesUserHaveAccess($page, Permission::CONTENT_EDIT);
    }

    /**
     * Opening the backend module that lists and manages the records.
     */
    public function mayManage(string $moduleIdentifier): bool
    {
        $user = $this->user();
        if (!$user instanceof BackendUserAuthentication || !$this->moduleProvider->isModuleRegistered($moduleIdentifier)) {
            return false;
        }

        return $this->moduleProvider->accessGranted($moduleIdentifier, $user);
    }

    /**
     * The table's ctrl section, or null for a table TCA does not know.
     *
     * @return array<array-key, mixed>|null
     */
    private function ctrl(string $table): ?array
    {
        $tca   = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $entry = is_array($tca[$table] ?? null) ? $tca[$table] : null;
        if ($entry === null) {
            return null;
        }

        return is_array($entry['ctrl'] ?? null) ? $entry['ctrl'] : [];
    }

    private function user(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication && is_array($user->user) ? $user : null;
    }
}
