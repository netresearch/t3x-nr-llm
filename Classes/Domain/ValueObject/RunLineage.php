<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * What a continuation of a run derives from it (ADR-214 items 6 and 10,
 * ADR-228): the process pin it holds, the record the process is about, and
 * the configuration they belong to. Uids, a digest and the process skill's
 * identifier, which the run status shows (ADR-228); no prompt text.
 *
 * Stored as a `lineage` event of the run, so it outlives the suspended state
 * and the queued request, both of which the terminal settle clears, and is
 * purged with the run's other events. The latest such event of a run is the
 * one that counts: ending a process appends one with `released` set.
 *
 * A released lineage still names its pin and subject, so the record says what
 * was ended; a continuation derives nothing from it.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class RunLineage
{
    public function __construct(
        public int $configurationUid,
        public ?SkillPin $processPin = null,
        public ?SubjectRecord $subject = null,
        public bool $released = false,
        // The process skill's identifier when the run started or continued it,
        // for the run status; '' without a process.
        public string $processSkill = '',
        // The run that started the process; every turn that continues it
        // carries the same origin. Ending the process releases the origin
        // too, and a turn whose origin no longer holds the process holds
        // nothing, so no earlier or parallel turn can hand it on again.
        public string $originRunUuid = '',
    ) {}

    /**
     * Whether a continuation of this run carries its process on.
     */
    public function holdsProcess(): bool
    {
        return $this->processPin instanceof SkillPin && !$this->released;
    }

    /**
     * The same lineage with its process ended (ADR-228).
     */
    public function withReleasedProcess(): self
    {
        return new self($this->configurationUid, $this->processPin, $this->subject, true, $this->processSkill, $this->originRunUuid);
    }

    /**
     * @return array{configuration: int, processPin: array{skill: int, source: int, digest: string}|null, subject: array{table: string, uid: int, language: int}|null, released: bool, processSkill: string, origin: string}
     */
    public function toArray(): array
    {
        return [
            'configuration' => $this->configurationUid,
            'processPin'    => $this->processPin?->toArray(),
            'subject'       => $this->subject?->toArray(),
            'released'      => $this->released,
            'processSkill'  => $this->processSkill,
            'origin'        => $this->originRunUuid,
        ];
    }

    /**
     * A stored lineage, or null for a payload that is not one. Strict on every
     * member: a pin or subject that is present but malformed makes the whole
     * lineage unreadable, which a continuation refuses rather than reading as
     * "no process".
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $configuration = $data['configuration'] ?? null;
        $released      = $data['released'] ?? null;
        $processSkill  = $data['processSkill'] ?? null;
        $origin        = $data['origin'] ?? null;
        if (!is_int($configuration) || $configuration < 0 || !is_bool($released) || !is_string($processSkill) || !is_string($origin)) {
            return null;
        }

        $pin = null;
        if (($data['processPin'] ?? null) !== null) {
            $pin = SkillPin::fromArray($data['processPin']);
            if (!$pin instanceof SkillPin) {
                return null;
            }
        }

        $subject = null;
        if (($data['subject'] ?? null) !== null) {
            $subject = SubjectRecord::fromArray($data['subject']);
            if (!$subject instanceof SubjectRecord) {
                return null;
            }
        }

        return new self($configuration, $pin, $subject, $released, $processSkill, $origin);
    }
}
