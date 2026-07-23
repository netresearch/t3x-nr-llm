<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
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

    /**
     * The full actor for the current backend request (ADR-083): the backend
     * user's uid, admin flag and group uids captured HERE, at the HTTP boundary,
     * where the authenticated BE user genuinely is the caller. This is the one
     * sanctioned reading of the ambient BE user — everything downstream (a run
     * request, a queue worker) carries the resulting {@see AiActorContext}
     * explicitly instead of re-reading `$GLOBALS['BE_USER']`.
     */
    private function currentActor(): AiActorContext
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $uid         = $this->currentBackendUserUid();
        if (!$backendUser instanceof BackendUserAuthentication || $uid === 0) {
            return AiActorContext::anonymous();
        }

        $groupIds = array_values(array_filter(
            array_map(static fn(mixed $g): int => is_numeric($g) ? (int)$g : 0, $backendUser->userGroupsUID),
            static fn(int $g): bool => $g > 0,
        ));

        return AiActorContext::backendUser($uid, $backendUser->isAdmin(), $groupIds);
    }
}
