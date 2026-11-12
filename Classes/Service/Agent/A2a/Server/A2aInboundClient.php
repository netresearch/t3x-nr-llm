<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

/**
 * A remote client of the server role, as its record says (spec 051
 * A2A-SEC1, A2A-SEC3, ADR-280). Its uid owns every task it creates.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aInboundClient
{
    /**
     * @param list<int> $profileUids
     */
    public function __construct(
        public int $uid,
        public string $title,
        public string $tokenPrefix,
        public string $tokenHash,
        public int $backendUserUid,
        public int $workspaceId,
        public array $profileUids,
        public int $taskLimit,
        public bool $enabled,
        public int $expires,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $int     = static fn (string $key): int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
        $string  = static fn (string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';
        $profile = [];
        foreach (explode(',', $string('profiles')) as $uid) {
            if (ctype_digit(trim($uid)) && (int) $uid > 0) {
                $profile[] = (int) $uid;
            }
        }

        return new self(
            $int('uid'),
            $string('title'),
            $string('token_prefix'),
            $string('token_hash'),
            $int('be_user'),
            $int('workspace'),
            array_values(array_unique($profile)),
            $int('task_limit'),
            $int('enabled') === 1,
            $int('expires'),
        );
    }

    /**
     * Whether the record may act now: enabled, not expired, with a token.
     */
    public function isActive(int $now): bool
    {
        return $this->enabled && $this->tokenHash !== '' && ($this->expires === 0 || $this->expires > $now);
    }

    public function mayUse(int $profileUid): bool
    {
        return in_array($profileUid, $this->profileUids, true);
    }
}
