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
     * A stored pin, or null for a value that is not one.
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
     * The stored pins of a run. Fails closed: a malformed entry becomes a pin
     * that can never hold (skill 0, no digest), so a resume of a state whose
     * pins were damaged stops instead of continuing with fewer checks. A
     * stored value that is not a list at all is treated the same way; only an
     * absent value (a state written before pins existed) yields no pins.
     *
     * @return list<self>
     */
    public static function listFrom(mixed $data): array
    {
        if ($data === null) {
            return [];
        }

        if (!is_array($data)) {
            return [self::unholdable()];
        }

        $pins = [];
        foreach ($data as $entry) {
            $pins[] = self::fromArray($entry) ?? self::unholdable();
        }

        return $pins;
    }

    /**
     * A pin no approval can match, standing in for a stored entry nobody can
     * read.
     */
    private static function unholdable(): self
    {
        return new self(0, 0, '');
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
