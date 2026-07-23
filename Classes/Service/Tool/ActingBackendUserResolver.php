<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The one place a backend-user uid becomes a live {@see BackendUserAuthentication}
 * for a tool run (ADR-083). This is the CLI/scheduler impersonation idiom:
 * `setBeUserByUid()` loads the record honouring enable-fields (a deleted or
 * disabled user yields an empty record), and `fetchGroupData()` rebuilds the
 * permission surface (group membership, DB mounts, `getPagePermsClause()`,
 * `check('tables_select', …)`) the user-scoped tools rely on.
 *
 * Privilege is derived from the FRESH database record, never from the serialised
 * actor: a truncated or tampered actor row can lower privilege (uid unresolved →
 * null) but never mint it — the same least-privilege posture as
 * {@see AiActorContext::fromArray()}.
 */
final class ActingBackendUserResolver implements ActingBackendUserResolverInterface
{
    public function resolve(AiActorContext $actor): ?BackendUserAuthentication
    {
        // A service account or an anonymous caller owns no backend user; the
        // uid channel is empty. Fail closed, exactly as an absent
        // $GLOBALS['BE_USER'] did before this seam existed.
        if ($actor->backendUserUid <= 0) {
            return null;
        }

        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $user->setBeUserByUid($actor->backendUserUid);

        // setBeUserByUid() respects enable-fields: a deleted/disabled/missing
        // user leaves ->user null/empty. A successfully loaded user has a fully
        // populated record; anything else must not be handed to the tools, which
        // would authorise against an incomplete permission surface.
        if (!is_array($user->user) || $user->user === []) {
            return null;
        }

        $user->fetchGroupData();

        return $user;
    }
}
