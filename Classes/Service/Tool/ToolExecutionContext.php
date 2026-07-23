<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The identity a tool run executes under (ADR-083).
 *
 * The agent tool loop and every tool used to read the acting backend user from
 * the ambient `$GLOBALS['BE_USER']`. That superglobal is absent in the async
 * queue worker, so the same run authorised differently on a worker than it did
 * synchronously. This context is built ONCE per run from the run's explicit
 * {@see AiActorContext} — identically on the synchronous and worker paths — and
 * threaded into `ToolInterface::execute()` and the loop's RBAC gate, so no tool
 * reads the ambient user any more.
 *
 * The user-scoped tools need a LIVE {@see BackendUserAuthentication} (they call
 * `getPagePermsClause()`, `checkLanguageAccess()`, `check('tables_select', …)`,
 * which the plain actor VO cannot reproduce). {@see ActingBackendUserResolver}
 * reconstructs it from the actor's uid; a service account, an anonymous actor or
 * a deleted/disabled user yields `null`, which every user-scoped tool treats as
 * "no permission" — fail-closed, exactly as an absent `$GLOBALS['BE_USER']` did.
 */
final readonly class ToolExecutionContext
{
    public function __construct(
        public AiActorContext $actor,
        public ?BackendUserAuthentication $backendUser = null,
    ) {}

    /**
     * A tool run with a resolved live backend user (production + tests that need
     * a real permission surface).
     */
    public static function forBackendUser(AiActorContext $actor, ?BackendUserAuthentication $backendUser): self
    {
        return new self($actor, $backendUser);
    }

    /**
     * A context carrying a live backend user, with its {@see AiActorContext}
     * derived from that same user (uid, admin flag, group uids). The one place
     * a live {@see BackendUserAuthentication} is turned into a context — used at
     * the HTTP boundary and in tests, so the fragile uid extraction lives once.
     */
    public static function fromBackendUser(BackendUserAuthentication $user): self
    {
        $record = is_array($user->user) ? $user->user : [];
        $rawUid = $record['uid'] ?? null;
        $uid    = is_numeric($rawUid) ? (int)$rawUid : 0;

        $groupIds = array_values(array_filter(
            array_map(static fn(mixed $g): int => is_numeric($g) ? (int)$g : 0, $user->userGroupsUID),
            static fn(int $g): bool => $g > 0,
        ));

        return new self(AiActorContext::backendUser($uid, $user->isAdmin(), $groupIds), $user);
    }

    /**
     * A run with no acting backend user — a service account, an anonymous caller
     * or a uid that no longer resolves. User-scoped tools fail closed against it.
     */
    public static function none(): self
    {
        return new self(AiActorContext::anonymous(), null);
    }

    /**
     * The live acting backend user, or null when there is none (service account,
     * anonymous, or an unresolvable uid). A user-scoped tool MUST treat null as
     * "no permission" rather than falling back to an ambient user.
     */
    public function actingBackendUser(): ?BackendUserAuthentication
    {
        return $this->backendUser;
    }

    /**
     * Whether the acting user is a TYPO3 administrator — the loop's admin-only
     * tool filter. False when there is no resolved user (fail-closed).
     */
    public function isAdmin(): bool
    {
        return $this->backendUser?->isAdmin() ?? false;
    }
}
