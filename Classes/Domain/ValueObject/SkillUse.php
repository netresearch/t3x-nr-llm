<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\SkillRole;

/**
 * One skill version a composition put into a prompt (ADR-321): its identity,
 * the digest it was composed at and the channel it went into.
 *
 * Identity only. The name, description and body are not here, so nothing a
 * consumer derives from it can carry the skill's text.
 *
 * `versionDigest` is `''` for a context skill stored before version digests
 * existed: the composer admits it on its body checksum, and no digest
 * identifies its version.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class SkillUse
{
    public function __construct(
        public int $skillUid,
        public int $sourceUid,
        public string $versionDigest,
        public string $identifier,
        public SkillRole $role,
    ) {}

    /**
     * The key a run holds one version of a skill under: the skill and its
     * digest, as a pin is compared.
     */
    public function key(): string
    {
        return self::keyOf($this->skillUid, $this->versionDigest);
    }

    public static function keyOf(int $skillUid, string $versionDigest): string
    {
        return $skillUid . "\x00" . $versionDigest;
    }
}
