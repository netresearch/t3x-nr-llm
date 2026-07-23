<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

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
 */
final readonly class AiActorContext
{
    /**
     * @param list<int> $backendGroupIds The actor's backend group uids, used to evaluate configuration access restrictions (ADR-070).
     */
    private function __construct(
        public int $backendUserUid,
        public bool $isAdmin,
        public array $backendGroupIds,
        public ?string $serviceAccount,
    ) {}

    /**
     * An authenticated backend user.
     *
     * @param list<int> $backendGroupIds
     */
    public static function backendUser(int $uid, bool $isAdmin = false, array $backendGroupIds = []): self
    {
        return new self($uid, $isAdmin, $backendGroupIds, null);
    }

    /**
     * A non-interactive caller — CLI, scheduler task or queue worker. It owns
     * nothing, so it may act on any session; the name is recorded so an
     * operator can tell which automation ran.
     */
    public static function serviceAccount(string $name): self
    {
        return new self(0, false, [], $name);
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

        return new self(
            is_int($uid) ? $uid : 0,
            ($data['isAdmin'] ?? false) === true,
            is_array($groupIds) ? array_values(array_filter($groupIds, is_int(...))) : [],
            is_string($serviceAccount) && $serviceAccount !== '' ? $serviceAccount : null,
        );
    }

    public function isServiceAccount(): bool
    {
        return $this->serviceAccount !== null;
    }

    public function isAuthenticated(): bool
    {
        return $this->backendUserUid > 0 || $this->isServiceAccount();
    }

    /**
     * Whether this actor may read and continue the given session: its owner, an
     * administrator, or a service account acting on the system's behalf.
     */
    public function mayAccessSession(AiSession $session): bool
    {
        if ($this->isServiceAccount() || $this->isAdmin) {
            return true;
        }

        return $this->backendUserUid > 0 && $this->backendUserUid === $session->beUser;
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
     * @return array{backendUserUid: int, isAdmin: bool, backendGroupIds: list<int>, serviceAccount: string|null}
     */
    public function toArray(): array
    {
        return [
            'backendUserUid'  => $this->backendUserUid,
            'isAdmin'         => $this->isAdmin,
            'backendGroupIds' => $this->backendGroupIds,
            'serviceAccount'  => $this->serviceAccount,
        ];
    }
}
