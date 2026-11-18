<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The test evidence that applied to one skill version when a model call
 * first carried it (ADR-322): the evaluations that had measured that digest
 * at that moment, newest first, each judged against the model that answered
 * the call. An empty list states that no evaluation had measured the version.
 *
 * Kept apart from the approval in the usage entry: an approval is a person's
 * decision about a text, evidence is a measurement of how a model did with it.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class SkillEvidenceEntry
{
    /**
     * @param list<SkillEvidence> $evidence
     */
    public function __construct(
        public int $skillUid,
        public string $versionDigest,
        public array $evidence,
    ) {}

    /**
     * @return array{skill: int, digest: string, evidence: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'skill'    => $this->skillUid,
            'digest'   => $this->versionDigest,
            'evidence' => array_map(static fn (SkillEvidence $e): array => $e->toArray(), $this->evidence),
        ];
    }

    /**
     * A stored entry, or null for a payload that is not one. An entry whose
     * evidence list holds an item that does not read is refused whole, so a
     * reader never shows "no evidence" for evidence it could not read.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !is_int($data['skill'] ?? null) || !is_string($data['digest'] ?? null) || !is_array($data['evidence'] ?? null)) {
            return null;
        }

        $evidence = [];
        foreach ($data['evidence'] as $item) {
            $read = SkillEvidence::fromArray($item);
            if (!$read instanceof SkillEvidence) {
                return null;
            }

            $evidence[] = $read;
        }

        return new self($data['skill'], $data['digest'], $evidence);
    }

    /**
     * @return list<self>
     */
    public static function listFrom(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $entries = [];
        foreach ($data as $item) {
            $entry = self::fromArray($item);
            if ($entry instanceof self) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
