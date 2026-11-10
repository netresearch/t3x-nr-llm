<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

/**
 * Whose AI usage one backend user may see in the group usage view (ADR-277).
 *
 * Three shapes and no other: denied (no grant), everyone (an administrator)
 * and a list of backend users, which may be empty. A list never means
 * "everyone": the view hands it to
 * {@see \Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface::forUsers()},
 * which counts nothing for an empty list.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class UsageAudience
{
    /**
     * @param list<int>                            $beUserUids     sorted, each > 0; empty unless $permitted and not $everyone
     * @param list<array{uid: int, title: string}> $assignedGroups the groups assigned to the user, by title
     */
    private function __construct(
        public bool $permitted,
        public bool $everyone,
        public array $beUserUids,
        public array $assignedGroups,
    ) {}

    public static function denied(): self
    {
        return new self(false, false, [], []);
    }

    public static function everyone(): self
    {
        return new self(true, true, [], []);
    }

    /**
     * @param list<int>                            $beUserUids
     * @param list<array{uid: int, title: string}> $assignedGroups
     */
    public static function members(array $beUserUids, array $assignedGroups): self
    {
        $uids = array_values(array_unique(array_filter($beUserUids, static fn (int $uid): bool => $uid > 0)));
        sort($uids);

        return new self(true, false, $uids, $assignedGroups);
    }
}
