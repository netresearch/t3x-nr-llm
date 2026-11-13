<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;

/**
 * What a backend user may do with an A2A token that acts as themselves
 * (ADR-294): the merge of the limits on the groups that hold the grant.
 *
 * Zero is the strict value of every field. A group that holds the grant and
 * sets nothing allows a token in a draft workspace, for at most
 * {@see self::DEFAULT_MAX_EXPIRY_DAYS} days, calling no profile.
 *
 * Merge over the groups that hold the grant (a group that sets a relaxation
 * and does not hold the grant contributes nothing): live is allowed when any
 * of them allows it; the maximum expiry is the largest value any of them
 * *sets* (a group that sets none does not widen what another sets), the
 * default when none sets one, never above {@see self::CEILING_DAYS}; no
 * expiry is allowed when any of them allows it; the profiles are the union.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSelfServiceLimits
{
    public const DEFAULT_MAX_EXPIRY_DAYS = 90;

    public const CEILING_DAYS = 730;

    public const FIELD_ALLOW_LIVE = 'tx_nrllm_a2a_allow_live';

    public const FIELD_MAX_EXPIRY_DAYS = 'tx_nrllm_a2a_max_expiry_days';

    public const FIELD_ALLOW_NO_EXPIRY = 'tx_nrllm_a2a_allow_no_expiry';

    public const FIELD_PROFILES = 'tx_nrllm_a2a_profiles';

    /**
     * @param list<int> $profileUids
     */
    public function __construct(
        public bool $permitted,
        public bool $allowLive,
        public int $maxExpiryDays,
        public bool $allowNoExpiry,
        public array $profileUids,
        public string $deniedReason = '',
    ) {}

    public static function denied(string $reason): self
    {
        return new self(false, false, self::DEFAULT_MAX_EXPIRY_DAYS, false, [], $reason);
    }

    /**
     * @param iterable<mixed> $groups the be_groups rows TYPO3 resolved for the user, subgroups included
     */
    public static function fromGroups(iterable $groups): self
    {
        $permission = BackendUserGrant::A2A_TOKEN_SELF_SERVICE->permissionValue();
        $held       = false;
        $live       = false;
        $noExpiry   = false;
        $days       = 0;
        $profiles   = [];

        foreach ($groups as $group) {
            if (!is_array($group) || !self::holds($group['custom_options'] ?? '', $permission)) {
                continue;
            }

            $held     = true;
            $live     = $live || self::flag($group[self::FIELD_ALLOW_LIVE] ?? 0);
            $noExpiry = $noExpiry || self::flag($group[self::FIELD_ALLOW_NO_EXPIRY] ?? 0);
            $days     = max($days, self::days($group[self::FIELD_MAX_EXPIRY_DAYS] ?? 0));
            foreach (self::uids($group[self::FIELD_PROFILES] ?? '') as $uid) {
                $profiles[$uid] = $uid;
            }
        }

        if (!$held) {
            return self::denied('none of the backend groups of the owner holds the permission to issue A2A tokens');
        }

        return new self(true, $live, $days > 0 ? $days : self::DEFAULT_MAX_EXPIRY_DAYS, $noExpiry, array_values($profiles));
    }

    /**
     * Whether an expiry timestamp is within the limit: 0 (never) only when a
     * group allows it, otherwise at most the maximum ahead of now.
     */
    public function allowsExpiry(int $expires, int $now): bool
    {
        if ($this->allowNoExpiry) {
            return true;
        }

        return $expires > $now && $expires <= $now + $this->maxExpiryDays * 86400;
    }

    /**
     * The positive uids of a comma list; anything else in it is dropped.
     *
     * @return list<int>
     */
    private static function uids(mixed $list): array
    {
        $uids = [];
        foreach (explode(',', is_scalar($list) ? (string) $list : '') as $uid) {
            if (ctype_digit(trim($uid)) && (int) $uid > 0) {
                $uids[] = (int) $uid;
            }
        }

        return $uids;
    }

    private static function holds(mixed $customOptions, string $permission): bool
    {
        if (!is_string($customOptions)) {
            return false;
        }

        return in_array($permission, array_map(trim(...), explode(',', $customOptions)), true);
    }

    private static function flag(mixed $value): bool
    {
        return is_numeric($value) && (int) $value === 1;
    }

    /**
     * The days a group sets: 0 for unset or unusable, never above the ceiling.
     */
    private static function days(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? min((int) $value, self::CEILING_DAYS) : 0;
    }
}
