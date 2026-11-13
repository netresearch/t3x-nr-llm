<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * What an editor does with the A2A tokens of their own account (ADR-294), and
 * the only writer of a self-service record.
 *
 * - The owner is the session user, passed in as the backend user object, never
 *   a uid from the request. An administrator is refused: ADR-280 forbids an
 *   administrator as the acting user.
 * - The permission and every limit are those of
 *   {@see A2aSelfServicePolicy}, asked of the record before it is stored; the
 *   same policy applies to the record at every later read.
 * - A live workspace and a writing profile do not go together (A2A-MT5), as
 *   for a record an administrator saves.
 * - An editor holds at most {@see self::MAX_ACTIVE_PER_OWNER} active tokens, so
 *   the task limit of each record cannot be multiplied without bound.
 * - Listing and revoking carry the owner in the query: a record of somebody
 *   else is answered like a missing one.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSelfServiceTokens
{
    /** Active tokens one editor may hold; each has its own task limit. */
    public const MAX_ACTIVE_PER_OWNER = 10;

    public function __construct(
        private A2aInboundClientRepository $clients,
        private A2aSelfServicePolicy $policy,
        private A2aSkillProfileRepository $profiles,
        private A2aTokenAudit $audit,
    ) {}

    /**
     * Issue a new token for the actor's own account.
     *
     * @param list<int> $profileUids
     *
     * @throws A2aTokenRefusedException when the actor may not, or a limit does not allow it
     */
    public function create(BackendUserAuthentication $actor, string $title, int $workspaceId, array $profileUids, int $expires, int $now): A2aIssuedToken
    {
        $owner = $this->ownerUid($actor);
        $title = trim($title);
        if ($title === '') {
            throw new A2aTokenRefusedException('A token needs a title.', 1799300301);
        }

        $profileUids = array_values(array_unique(array_filter($profileUids, static fn (int $uid): bool => $uid > 0)));
        $candidate   = new A2aInboundClient(0, $title, '', '', $owner, max(0, $workspaceId), $profileUids, A2aInboundClientRepository::SELF_SERVICE_TASK_LIMIT, true, $expires, true, $now);

        $refusal = $this->policy->refusal($candidate, $now) ?? $this->liveWithWritingProfile($candidate);
        if ($refusal !== null) {
            throw new A2aTokenRefusedException(ucfirst($refusal) . '.', 1799300302);
        }

        $uid = $this->clients->insertSelfService($owner, $title, $candidate->workspaceId, $profileUids, $expires, $now);

        // Counted after the insert, not before: a count taken first lets
        // parallel requests of one editor each see room. Each request counts
        // its own row and every row committed before it, so the last of any
        // number of parallel requests sees them all; a request over the cap
        // withdraws its own row, and at worst two racing requests both do.
        if ($this->clients->countActiveSelfService($owner, $now) > self::MAX_ACTIVE_PER_OWNER) {
            $this->clients->discardUnissued($uid);

            throw new A2aTokenRefusedException(sprintf('You already hold %d active tokens; revoke one first.', self::MAX_ACTIVE_PER_OWNER), 1799300306);
        }

        try {
            $token = $this->clients->issueToken($uid);
        } catch (Throwable $exception) {
            $this->clients->discardUnissued($uid);

            throw $exception;
        }

        $this->audit->record('issued for the actor own account', $actor, $uid);

        return new A2aIssuedToken($uid, $token, $expires);
    }

    /**
     * The actor's own self-service records, as stored.
     *
     * @return list<A2aInboundClient>
     */
    public function listOwn(BackendUserAuthentication $actor): array
    {
        return $this->clients->findForManagement($this->ownerUid($actor));
    }

    /**
     * Revoke one of the actor's own tokens.
     *
     * @throws A2aTokenRefusedException when there is no such record of the actor
     */
    public function revokeOwn(BackendUserAuthentication $actor, int $clientUid): void
    {
        if (!$this->clients->revoke($clientUid, $this->ownerUid($actor))) {
            throw new A2aTokenRefusedException('There is no such token.', 1799300303);
        }

        $this->audit->record('revoked by its owner', $actor, $clientUid);
    }

    private function ownerUid(BackendUserAuthentication $actor): int
    {
        if ($actor->isAdmin()) {
            throw new A2aTokenRefusedException('Administrators issue tokens for technical users; a token that acts as an account is for editors.', 1799300304);
        }

        $uid = is_array($actor->user) ? ($actor->user['uid'] ?? 0) : 0;
        if (!is_numeric($uid) || (int) $uid < 1) {
            throw new A2aTokenRefusedException('There is no backend user.', 1799300305);
        }

        return (int) $uid;
    }

    private function liveWithWritingProfile(A2aInboundClient $candidate): ?string
    {
        foreach ($candidate->profileUids as $uid) {
            $profile = $this->profiles->findByUid($uid);
            if (!$profile instanceof A2aSkillProfile || !$profile->public) {
                return 'the profile ' . $uid . ' does not exist or is not public';
            }

            if ($candidate->workspaceId < 1 && $profile->mayWrite) {
                return 'a token in the live workspace cannot call a writing profile; pin a draft workspace';
            }
        }

        return null;
    }
}
