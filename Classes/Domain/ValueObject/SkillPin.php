<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One approved skill version a run holds as an instruction (ADR-214 item 6):
 * the skill, the source that vouched for it and the version digest.
 *
 * A pin carries no text. The text is the approval snapshot of exactly this
 * triple, and the pin holds only while that approval is unrevoked, its
 * snapshot still hashes to the digest, the skill record exists and is not
 * orphaned, and the source's provenance meets the instruction threshold.
 *
 * @api
 */
final readonly class SkillPin
{
    public function __construct(
        public int $skillUid,
        public int $sourceUid,
        public string $versionDigest,
    ) {}

    /**
     * @return array{skill: int, source: int, digest: string}
     */
    public function toArray(): array
    {
        return ['skill' => $this->skillUid, 'source' => $this->sourceUid, 'digest' => $this->versionDigest];
    }

    /**
     * A stored pin, or null for a value that is not one. A malformed entry is
     * dropped rather than repaired: a pin nobody can read vouches for nothing.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $skill  = $data['skill'] ?? null;
        $source = $data['source'] ?? null;
        $digest = $data['digest'] ?? null;
        if (!is_int($skill) || $skill <= 0 || !is_int($source) || $source < 0 || !is_string($digest) || $digest === '') {
            return null;
        }

        return new self($skill, $source, $digest);
    }

    /**
     * @return list<self>
     */
    public static function listFrom(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $pins = [];
        foreach ($data as $entry) {
            $pin = self::fromArray($entry);
            if ($pin instanceof self) {
                $pins[] = $pin;
            }
        }

        return $pins;
    }

    /**
     * @param list<self> $pins
     *
     * @return list<array{skill: int, source: int, digest: string}>
     */
    public static function listToArray(array $pins): array
    {
        return array_map(static fn(self $pin): array => $pin->toArray(), $pins);
    }
}
