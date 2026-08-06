<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;

/**
 * Who is driving an AI call (ADR-083).
 *
 * The extension's stateful entry points need to know the caller, not merely
 * guess it from `$GLOBALS['BE_USER']`: a conversation session belongs to a
 * backend user, and knowing a session uuid must never be enough to continue
 * somebody else's conversation. A CLI worker or scheduler task has no backend
 * user at all yet must still be able to act, so it identifies itself as a
 * named service account instead.
 *
 * Passing the context explicitly keeps the decision auditable and testable, and
 * lets a queue consumer act on behalf of the user who queued the work rather
 * than inheriting whatever ambient user happens to be logged in.
 *
 * @api
 */
final readonly class AiActorContext
{
    /**
     * @param list<int>                 $backendGroupIds The actor's backend group uids, used to evaluate configuration access restrictions (ADR-070).
     * @param list<ServiceAccountScope> $scopes          The capabilities a service account is permitted (ADR-110); empty (and irrelevant) for a backend user, who is authorised by ownership and admin rights instead.
     * @param list<BackendUserGrant>    $grants          The capability grants a human backend user holds (ADR-130), frozen from the live user's group permissions at the HTTP boundary; empty (and irrelevant) for a service account, whose mechanism is $scopes.
     */
    private function __construct(
        public int $backendUserUid,
        public bool $isAdmin,
        public array $backendGroupIds,
        public ?string $serviceAccount,
        public array $scopes = [],
        public array $grants = [],
    ) {}

    /**
     * An authenticated backend user.
     *
     * @param list<int>              $backendGroupIds
     * @param list<BackendUserGrant> $grants
     */
    public static function backendUser(int $uid, bool $isAdmin = false, array $backendGroupIds = [], array $grants = []): self
    {
        return new self($uid, $isAdmin, $backendGroupIds, null, [], array_values($grants));
    }

    /**
     * A non-interactive caller — CLI, scheduler task or queue worker. It owns
     * nothing and has no backend privileges, so it is authorised solely by the
     * scopes it declares (ADR-110): with none it may do nothing. The name is
     * recorded so an operator can tell which automation ran.
     *
     * @param list<ServiceAccountScope> $scopes
     */
    public static function serviceAccount(string $name, array $scopes = []): self
    {
        return new self(0, false, [], $name, array_values($scopes));
    }

    /**
     * An unauthenticated caller. Owns nothing and may access nothing; kept
     * explicit so a missing backend user fails closed instead of resolving to
     * "user 0", which the session table also uses for unattributed rows.
     */
    public static function anonymous(): self
    {
        return new self(0, false, [], null);
    }

    /**
     * Rebuild an actor from its {@see toArray()} form when a queued run is
     * rehydrated in a worker (ADR-102/083), so the worker acts with the same
     * identity that enqueued the work rather than the ambient (absent) BE user.
     * Every field degrades to its least-privileged default, so a malformed or
     * truncated row can never yield a MORE privileged actor than was stored.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $uid            = $data['backendUserUid'] ?? 0;
        $groupIds       = $data['backendGroupIds'] ?? [];
        $serviceAccount = $data['serviceAccount'] ?? null;
        $scopes         = $data['scopes'] ?? [];
        $grants         = $data['grants'] ?? [];

        return new self(
            is_int($uid) ? $uid : 0,
            ($data['isAdmin'] ?? false) === true,
            is_array($groupIds) ? array_values(array_filter($groupIds, is_int(...))) : [],
            is_string($serviceAccount) && $serviceAccount !== '' ? $serviceAccount : null,
            is_array($scopes) ? self::decodeScopes($scopes) : [],
            is_array($grants) ? self::decodeGrants($grants) : [],
        );
    }

    /**
     * Restore the scope set from its serialised string form, dropping anything
     * that is not a known scope value — so a truncated or tampered row can never
     * yield a scope the process does not define (fail-closed).
     *
     * @param array<array-key, mixed> $values
     *
     * @return list<ServiceAccountScope>
     */
    private static function decodeScopes(array $values): array
    {
        $scopes = [];
        foreach ($values as $value) {
            $scope = is_string($value) ? ServiceAccountScope::tryFrom($value) : null;
            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * Restore the grant set from its serialised string form, dropping anything
     * that is not a known grant value — same fail-closed rule as the scopes.
     *
     * @param array<array-key, mixed> $values
     *
     * @return list<BackendUserGrant>
     */
    private static function decodeGrants(array $values): array
    {
        $grants = [];
        foreach ($values as $value) {
            $grant = is_string($value) ? BackendUserGrant::tryFrom($value) : null;
            if ($grant !== null) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    public function isServiceAccount(): bool
    {
        return $this->serviceAccount !== null;
    }

    /**
     * Whether a service account declares the given scope (ADR-110). Always false
     * for a backend user or an anonymous caller: scopes govern service accounts
     * only, so an entry point must combine this with its own ownership/admin
     * check rather than relying on scopes for interactive callers.
     */
    public function hasScope(ServiceAccountScope $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Whether a human backend user holds the given capability grant (ADR-130).
     * Administrators hold every grant implicitly — mirroring both the core's
     * `check()` and the admin-first branches of {@see mayAccessSession()} /
     * {@see mayActOnRun()}. Always false for a service account (its mechanism
     * is {@see hasScope()}) and for an anonymous caller.
     */
    public function hasGrant(BackendUserGrant $grant): bool
    {
        if ($this->isServiceAccount()) {
            return false;
        }

        return $this->isAdmin || in_array($grant, $this->grants, true);
    }

    public function isAuthenticated(): bool
    {
        return $this->backendUserUid > 0 || $this->isServiceAccount();
    }

    /**
     * Whether this actor may read and continue the given session: its owner, an
     * administrator, or a service account that declares the
     * {@see ServiceAccountScope::CONVERSATION_ACCESS} scope (ADR-110). A scopeless
     * service account is denied — knowing a session uuid is never enough.
     */
    public function mayAccessSession(AiSession $session): bool
    {
        if ($this->isAdmin) {
            return true;
        }

        if ($this->isServiceAccount()) {
            return $this->hasScope(ServiceAccountScope::CONVERSATION_ACCESS);
        }

        return $this->backendUserUid > 0 && $this->backendUserUid === $session->beUser;
    }

    /**
     * Whether this actor may perform the operation identified by $scope on the
     * given agent run (ADR-083/110): the run's initiator, an administrator, or a
     * service account that declares exactly that scope. A run UUID alone is never
     * enough — a stranger who guesses a uuid must not drive somebody else's run,
     * and a service account is limited to the operations it was granted (a cancel
     * sweep cannot also approve or read).
     */
    public function mayActOnRun(AgentRun $run, ServiceAccountScope $scope): bool
    {
        if ($this->isAdmin) {
            return true;
        }

        if ($this->isServiceAccount()) {
            return $this->hasScope($scope);
        }

        // The human sibling of the AGENT_APPROVE scope (ADR-130): a backend
        // user granted approval may decide OTHER users' suspended runs, not
        // only their own. Deliberately the only scope with a grant
        // equivalent — every other case stays owner-or-admin.
        if ($scope === ServiceAccountScope::AGENT_APPROVE && $this->hasGrant(BackendUserGrant::AGENT_APPROVE)) {
            return true;
        }

        return $this->backendUserUid > 0 && $this->backendUserUid === $run->beUser;
    }

    /**
     * A short, log-safe description of the actor for exception messages and
     * audit entries. Never contains a session uuid or any content.
     */
    public function describe(): string
    {
        if ($this->isServiceAccount()) {
            return sprintf('service account "%s"', (string)$this->serviceAccount);
        }

        if ($this->backendUserUid > 0) {
            return sprintf('backend user %d%s', $this->backendUserUid, $this->isAdmin ? ' (admin)' : '');
        }

        return 'an unauthenticated caller';
    }

    /**
     * The serialised form persisted with a queued run so a worker can restore
     * the full actor (not just a backend-user id) via {@see fromArray()}.
     *
     * @return array{backendUserUid: int, isAdmin: bool, backendGroupIds: list<int>, serviceAccount: string|null, scopes: list<string>, grants: list<string>}
     */
    public function toArray(): array
    {
        return [
            'backendUserUid'  => $this->backendUserUid,
            'isAdmin'         => $this->isAdmin,
            'backendGroupIds' => $this->backendGroupIds,
            'serviceAccount'  => $this->serviceAccount,
            'scopes'          => array_map(static fn(ServiceAccountScope $s): string => $s->value, $this->scopes),
            'grants'          => array_map(static fn(BackendUserGrant $g): string => $g->value, $this->grants),
        ];
    }
}
