<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The page-access check of `BackendUtility::readPageAccess()`, answered for an
 * EXPLICIT user (ADR-083, #1022).
 *
 * `readPageAccess()` takes the caller's perms clause, but asks
 * `$GLOBALS['BE_USER']` for `isAdmin()` and `isInWebMount()` (13.4 and 14.3
 * alike). In a queued or resumed run the ambient user is whoever runs the
 * request — an admin, another editor, or nobody — so the web-mount half of the
 * answer did not belong to the run's acting user.
 *
 * This keeps the helper's semantics and swaps the user: the page row must pass
 * `$permsClause`, lie in the user's web mounts under that clause, and survive
 * the overlay into the user's workspace. Page 0 is an admin's only.
 *
 * `doesUserHaveAccess()`, which the writing tools use, is not a drop-in:
 * `calcPerms()` grants nothing to a user without groups, and checks the mount
 * rootline under PAGE_SHOW whatever permission is asked for.
 */
trait ChecksPageAccessAsActingUserTrait
{
    private function actingUserMayAccessPage(BackendUserAuthentication $user, int $pageUid, string $permsClause): bool
    {
        if ($pageUid < 1) {
            return $pageUid === 0 && $user->isAdmin();
        }

        $page = BackendUtility::getRecord('pages', $pageUid, '*', $permsClause);
        if (!is_array($page) || !$user->isInWebMount($page, $permsClause)) {
            return false;
        }

        BackendUtility::workspaceOL('pages', $page, $user->workspace);

        return is_array($page);
    }
}
