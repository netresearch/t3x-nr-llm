<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Budget;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Default resolver — reads the active BE user from `$GLOBALS['BE_USER']`.
 *
 * Mirrors the pattern used by `CapabilityPermissionService` (the only
 * other production consumer of `$GLOBALS['BE_USER']` in this extension):
 * direct superglobal access, defended by an `instanceof` check so the
 * resolver returns `null` rather than blowing up when called outside a
 * backend request (CLI, scheduler, FE-only contexts).
 *
 * The TYPO3 v13/v14 user-array layout is documented to expose the uid
 * at `$BE_USER->user['uid']` as `int|null`; we narrow defensively in
 * case a third-party layer hands us a non-int (string-from-CSV, etc.)
 * and translate any non-positive value to `null` so the BudgetMiddleware
 * does not run a check against `uid === 0` (which is always
 * unauthenticated and would needlessly hit the budget service).
 */
final readonly class BackendUserContextResolver implements BackendUserContextResolverInterface
{
    public function resolveBeUserUid(): ?int
    {
        $candidate = $GLOBALS['BE_USER'] ?? null;
        if (!$candidate instanceof BackendUserAuthentication) {
            return null;
        }

        $uid = $candidate->user['uid'] ?? null;
        if (!is_int($uid) || $uid <= 0) {
            return null;
        }

        return $uid;
    }
}
