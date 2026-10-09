<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One approval of one skill version (ADR-214 item 2), as stored in
 * ``tx_nrllm_skill_approval``.
 *
 * The row is the snapshot of the version it approves: the fields that went
 * into the digest, the provenance level of the source at the time, who
 * approved and when. A pinned run composes a section from this snapshot, and
 * the approval form diffs the current version against it.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillApproval
{
    /**
     * @param list<string>|null $allowedTools null when the version declares no allow-list
     */
    public function __construct(
        public int $uid,
        public int $skillUid,
        public int $sourceUid,
        public string $versionDigest,
        public string $name,
        public string $description,
        public string $body,
        public string $supportStatus,
        public ?array $allowedTools,
        public bool $process,
        public string $trustLevel,
        public int $approvedBy,
        public int $approvedAt,
        public bool $revoked,
        public int $revokedBy,
        public int $revokedAt,
    ) {}

    /**
     * The snapshot's fields in the shape {@see \Netresearch\NrLlm\Service\Skill\SkillVersionDigest::ofFields()}
     * reads, so a snapshot can be re-hashed against its own digest.
     *
     * @return array{name: string, description: string, body: string, support_status: string, allowed_tools: list<string>|null, process: bool}
     */
    public function versionFields(): array
    {
        return [
            'name'           => $this->name,
            'description'    => $this->description,
            'body'           => $this->body,
            'support_status' => $this->supportStatus,
            'allowed_tools'  => $this->allowedTools,
            'process'        => $this->process,
        ];
    }
}
