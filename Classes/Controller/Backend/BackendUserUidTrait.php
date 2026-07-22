<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Resolves the acting backend user's uid for run attribution and the budget
 * pre-flight — 0 when no real BE user is present (CLI/testing), which the budget
 * check treats as anonymous.
 *
 * Shared by the controllers that continue a suspended agent run
 * ({@see ToolPlaygroundController}, {@see AgentRunController}) so the exact
 * null-safe extraction lives in one place.
 */
trait BackendUserUidTrait
{
    private function currentBackendUserUid(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return 0;
        }

        // BackendUserAuthentication::$user is untyped and may be null before a
        // session is fully loaded (CLI/testing), so guard with is_array().
        $uid = is_array($backendUser->user) ? ($backendUser->user['uid'] ?? 0) : 0;

        return is_numeric($uid) ? (int)$uid : 0;
    }
}
