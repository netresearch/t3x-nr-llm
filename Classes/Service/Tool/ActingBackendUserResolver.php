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
 * `check('tables_select', …)`) the user-scoped tools rely on, and the workspace
 * the actor was working in when the run started (ADR-260).
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

        $user = $this->load($actor->backendUserUid, null);
        if (!$user instanceof BackendUserAuthentication) {
            return null;
        }

        // The workspace the run was started in, not the one the user's record
        // names when the run happens to be resolved (ADR-260): an editor who
        // switched workspaces since, or a worker that picks the run up later,
        // must not move the run's writes. Not known: as before.
        $pinned = $actor->workspaceId;
        if ($pinned === null || $user->workspace === $pinned) {
            return $user;
        }

        // The user may no longer work there — the workspace is gone, or their
        // access to it or to live was withdrawn. The run then has no user:
        // writing into another workspace than the one it was started in is the
        // one outcome that must not happen.
        if ($user->checkWorkspace($pinned) === false) {
            return null;
        }

        // Loaded again with the workspace where core's own setup reads it, so
        // the workspace's page tree entry points narrow the user's mounts as
        // they do in the backend. Asked first, so core never falls back to
        // another workspace here and stores that on the user's record.
        $user = $this->load($actor->backendUserUid, $pinned);

        return $user?->workspace === $pinned ? $user : null;
    }

    /**
     * The user with their group data, set up in the given workspace or in the
     * one their record names; null for a deleted, disabled or missing user.
     */
    private function load(int $uid, ?int $workspaceId): ?BackendUserAuthentication
    {
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $user->setBeUserByUid($uid);

        // setBeUserByUid() respects enable-fields: a deleted/disabled/missing
        // user leaves ->user null/empty. A successfully loaded user has a fully
        // populated record; anything else must not be handed to the tools, which
        // would authorise against an incomplete permission surface.
        if (!is_array($user->user) || $user->user === []) {
            return null;
        }

        if ($workspaceId !== null) {
            $user->user['workspace_id'] = $workspaceId;
        }

        $user->fetchGroupData();

        return $user;
    }
}
