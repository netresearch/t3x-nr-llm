<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\ApprovalDenialReason;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;

/**
 * A decision taken in durably and not yet carried out (ADR-281, spec 051
 * A2A-MT2): the continuation reference of a QUEUED run.
 *
 * It is kept in the run's suspended state under {@see self::STORAGE_KEY}, next
 * to the fields of the pause it decides, so it is sealed with them and
 * dropped with them when the run settles, is cancelled or pauses again — the
 * way {@see \Netresearch\NrLlm\Service\Tool\DependencyCheckpoint} is kept. The
 * input values are content like the transcript beside them, under the same
 * retention.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class StoredDecision
{
    public const STORAGE_KEY = 'durableDecision';

    /**
     * @param array<string, mixed> $data the input values; empty for an approval
     */
    public function __construct(
        public string $id,
        public string $kind,
        public bool $approved,
        public ?ApprovalDenialReason $denialReason,
        public array $data,
        public string $turnDigest,
        public int $generation,
        public int $decidedByBeUser,
        public AiActorContext $decider,
        public int $storedAt,
    ) {}

    public function isInput(): bool
    {
        return $this->kind === PendingPause::KIND_INPUT;
    }

    public function inputSubmission(): InputSubmission
    {
        return new InputSubmission($this->data, $this->decidedByBeUser, $this->turnDigest);
    }

    public function approvalDecision(): ApprovalDecision
    {
        return new ApprovalDecision($this->approved, $this->decidedByBeUser, $this->turnDigest, $this->approved ? null : $this->denialReason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'kind'         => $this->kind,
            'approved'     => $this->approved,
            'denialReason' => $this->denialReason?->value,
            'data'         => $this->data,
            'turnDigest'   => $this->turnDigest,
            'generation'   => $this->generation,
            'decidedBy'    => $this->decidedByBeUser,
            'decider'      => $this->decider->toArray(),
            'storedAt'     => $this->storedAt,
        ];
    }

    /**
     * The decision a stored state carries, or null when it carries none or an
     * unreadable one: the worker never carries out a guess.
     *
     * @param array<array-key, mixed> $stored
     */
    public static function fromStoredState(array $stored): ?self
    {
        $data = $stored[self::STORAGE_KEY] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $kind    = $data['kind'] ?? null;
        $decider = $data['decider'] ?? null;
        $values  = $data['data'] ?? [];
        if (!in_array($kind, [PendingPause::KIND_INPUT, PendingPause::KIND_APPROVAL], true)
            || !is_string($data['id'] ?? null) || $data['id'] === ''
            || !is_bool($data['approved'] ?? null)
            || !is_string($data['turnDigest'] ?? null) || $data['turnDigest'] === ''
            || !is_int($data['generation'] ?? null)
            || !is_int($data['decidedBy'] ?? null)
            || !is_int($data['storedAt'] ?? null)
            || !is_array($decider) || !is_array($values)
        ) {
            return null;
        }

        $reason = is_string($data['denialReason'] ?? null) ? ApprovalDenialReason::tryFrom($data['denialReason']) : null;

        /** @var array<string, mixed> $decider */
        $actor = AiActorContext::fromArray($decider);

        /** @var array<string, mixed> $values */
        return new self($data['id'], $kind, $data['approved'], $reason, $values, $data['turnDigest'], $data['generation'], $data['decidedBy'], $actor, $data['storedAt']);
    }
}
