<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\SkillApprovalState;
use Netresearch\NrLlm\Domain\Enum\SkillInclusion;
use Netresearch\NrLlm\Domain\Enum\SkillRole;
use Netresearch\NrLlm\Domain\Enum\SkillUsageState;

/**
 * One skill version in a run's skill usage record (ADR-321).
 *
 * Uids, a digest, the skill's identifier and codes: nothing the skill says.
 * The record is stored as it is at every privacy level, so this class is the
 * whole of what reaches the event stream, and it holds no field that could
 * carry a name, a description or a body.
 *
 * `approvalUid` is the `tx_nrllm_skill_approval` row that held when the usage
 * was recorded, 0 where {@see self::$approvalState} is not APPROVED.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class SkillUsageEntry
{
    public function __construct(
        public int $skillUid,
        public int $sourceUid,
        public string $versionDigest,
        public string $identifier,
        public SkillRole $role,
        public SkillInclusion $inclusion,
        public SkillUsageState $state,
        public SkillApprovalState $approvalState,
        public int $approvalUid = 0,
    ) {}

    /**
     * @return array{skill: int, source: int, digest: string, identifier: string, role: string, inclusion: string, state: string, approvalState: string, approval: int}
     */
    public function toArray(): array
    {
        return [
            'skill'         => $this->skillUid,
            'source'        => $this->sourceUid,
            'digest'        => $this->versionDigest,
            'identifier'    => $this->identifier,
            'role'          => $this->role->value,
            'inclusion'     => $this->inclusion->value,
            'state'         => $this->state->value,
            'approvalState' => $this->approvalState->value,
            'approval'      => $this->approvalUid,
        ];
    }

    /**
     * A stored entry, or null for a payload that is not one. Strict on every
     * member, so a reader never shows a half-read entry as a used version.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $skill         = $data['skill'] ?? null;
        $source        = $data['source'] ?? null;
        $digest        = $data['digest'] ?? null;
        $identifier    = $data['identifier'] ?? null;
        $role          = is_string($data['role'] ?? null) ? SkillRole::tryFrom($data['role']) : null;
        $inclusion     = is_string($data['inclusion'] ?? null) ? SkillInclusion::tryFrom($data['inclusion']) : null;
        $state         = is_string($data['state'] ?? null) ? SkillUsageState::tryFrom($data['state']) : null;
        $approvalState = is_string($data['approvalState'] ?? null) ? SkillApprovalState::tryFrom($data['approvalState']) : null;
        $approval      = $data['approval'] ?? null;
        if (!is_int($skill) || $skill <= 0 || !is_int($source) || $source < 0 || !is_string($digest) || !is_string($identifier)
            || !$role instanceof SkillRole || !$inclusion instanceof SkillInclusion || !$state instanceof SkillUsageState
            || !$approvalState instanceof SkillApprovalState || !is_int($approval) || $approval < 0) {
            return null;
        }

        return new self($skill, $source, $digest, $identifier, $role, $inclusion, $state, $approvalState, $approval);
    }

    /**
     * The entries of a stored record; entries that do not read are left out.
     *
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
