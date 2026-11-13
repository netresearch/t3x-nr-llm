<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The one rule behind every self-service A2A token (ADR-294): what the owner's
 * backend groups allow *now*, applied when the record is written and on every
 * read of it.
 *
 * The groups are the ones TYPO3 resolves for the owner (own groups and their
 * subgroups, `fetchGroupData()`), read from the database at the time of the
 * call; nothing about the owner's rights is stored on the record. A record that
 * is not self-service passes through unchanged.
 *
 * - {@see self::limitsFor()} answers what the owner may do with a token pinned
 *   to a workspace, or why nothing: the owner is gone, disabled, an
 *   administrator (ADR-280: never the acting user), cannot work in the
 *   workspace, or no group of the owner holds the permission. The workspace
 *   criterion is that of {@see \Netresearch\NrLlm\Service\Tool\Rule\RequireDraftWorkspaceRule}
 *   (ADR-293), asked of the same resolver the run uses: the owner resolves in
 *   exactly the pinned workspace.
 * - {@see self::constrain()} is what the repository applies to every
 *   self-service record it returns: the record is withdrawn (inactive, with
 *   the reason) when it is outside the limits, and loses the profiles the
 *   groups no longer allow.
 * - {@see self::refusal()} is what the writer asks of a record it is about to
 *   store: a stricter reading, where a profile outside the allowed ones is a
 *   refusal and not a silent cut.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSelfServicePolicy
{
    public function __construct(
        private ActingBackendUserResolverInterface $users,
    ) {}

    public function limitsFor(int $ownerUid, int $workspaceId): A2aSelfServiceLimits
    {
        if ($ownerUid < 1) {
            return A2aSelfServiceLimits::denied('the token has no owner');
        }

        $user = $this->users->resolve(AiActorContext::backendUser($ownerUid, false, [], [], max(0, $workspaceId)));
        if (!$user instanceof BackendUserAuthentication) {
            return A2aSelfServiceLimits::denied('the owner is gone, disabled, or cannot work in the pinned workspace');
        }

        if ($user->isAdmin()) {
            return A2aSelfServiceLimits::denied('the owner is an administrator, who cannot be the acting user of a client');
        }

        if ($workspaceId > 0 && $user->workspace !== $workspaceId) {
            return A2aSelfServiceLimits::denied('the owner cannot work in the pinned workspace');
        }

        return A2aSelfServiceLimits::fromGroups($user->userGroups);
    }

    /**
     * The limits of the signed-in backend user, from the groups the session
     * already resolved. For the module's form and its explanation only; what
     * is stored and what a request may do is decided by {@see self::limitsFor()}
     * against the database.
     */
    public function limitsOfSessionUser(BackendUserAuthentication $user): A2aSelfServiceLimits
    {
        return $user->isAdmin()
            ? A2aSelfServiceLimits::denied('the owner is an administrator, who cannot be the acting user of a client')
            : A2aSelfServiceLimits::fromGroups($user->userGroups);
    }

    public function constrain(A2aInboundClient $client, int $now): A2aInboundClient
    {
        if (!$client->selfService) {
            return $client;
        }

        $limits = $this->limitsFor($client->backendUserUid, $client->workspaceId);
        $reason = $this->withdrawal($client, $limits, $now);
        if ($reason !== null) {
            return $client->withdrawn($reason);
        }

        return $client->withProfiles(array_values(array_intersect($client->profileUids, $limits->profileUids)));
    }

    /**
     * Why a record may not be stored, null when it may. Checked against the
     * same limits {@see self::constrain()} applies, so a record that is stored
     * is not withdrawn by the first request.
     */
    public function refusal(A2aInboundClient $candidate, int $now): ?string
    {
        $limits = $this->limitsFor($candidate->backendUserUid, $candidate->workspaceId);
        $reason = $this->withdrawal($candidate, $limits, $now);
        if ($reason !== null) {
            return $reason;
        }

        if ($candidate->expires <= $now && !$limits->allowNoExpiry) {
            return 'the expiry must lie in the future';
        }

        if ($candidate->profileUids === []) {
            return 'at least one profile is required';
        }

        $beyond = array_values(array_diff($candidate->profileUids, $limits->profileUids));

        return $beyond === [] ? null : 'the profile(s) ' . implode(', ', $beyond) . ' are not allowed for the groups of the owner';
    }

    private function withdrawal(A2aInboundClient $client, A2aSelfServiceLimits $limits, int $now): ?string
    {
        if (!$limits->permitted) {
            return $limits->deniedReason;
        }

        if ($client->workspaceId < 1 && !$limits->allowLive) {
            return 'the groups of the owner do not allow a token in the live workspace';
        }

        // An expired record is inactive for that reason already.
        if ($client->expires > 0 && $client->expires <= $now) {
            return null;
        }

        return $limits->allowsExpiry($client->expires, $now) ? null : 'the expiry is beyond the maximum the groups of the owner allow';
    }
}
