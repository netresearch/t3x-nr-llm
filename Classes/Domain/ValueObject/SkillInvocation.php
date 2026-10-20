<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * An explicit start of a process skill on a run (ADR-214 item 10, ADR-228):
 * the skill, by uid, and the record the process is about.
 *
 * The caller names the skill only. Which version runs, its text and its
 * tools come from the approval the runtime finds for the skill's current
 * digest, and a continuation derives them from its predecessor run: no
 * caller hands in a pin.
 *
 * @api
 */
final readonly class SkillInvocation
{
    public function __construct(
        public int $skillUid,
        public ?SubjectRecord $subject = null,
    ) {
        if ($skillUid < 1) {
            throw new InvalidArgumentException(
                sprintf('A skill invocation needs a positive skill uid, got %d.', $skillUid),
                1793500002,
            );
        }
    }

    /**
     * @return array{skill: int, subject: array{table: string, uid: int, language: int}|null}
     */
    public function toArray(): array
    {
        return ['skill' => $this->skillUid, 'subject' => $this->subject?->toArray()];
    }

    /**
     * A stored invocation, or null for a value that is not one. A subject that
     * is present but malformed makes the whole invocation unreadable: dropping
     * it would start the process on no record instead of the one asked for.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $skill = $data['skill'] ?? null;
        if (!is_int($skill) || $skill < 1) {
            return null;
        }

        $subject = null;
        if (($data['subject'] ?? null) !== null) {
            $subject = SubjectRecord::fromArray($data['subject']);
            if (!$subject instanceof SubjectRecord) {
                return null;
            }
        }

        return new self($skill, $subject);
    }
}
