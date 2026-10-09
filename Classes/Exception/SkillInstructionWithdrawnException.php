<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use RuntimeException;

/**
 * A run holds an approved skill version as an instruction, and that version
 * no longer holds (ADR-214 item 6): its approval was revoked, its snapshot no
 * longer hashes to its digest, the skill record is gone or orphaned, or its
 * source's provenance fell below the instruction threshold.
 *
 * The run stops with this message instead of continuing without the section
 * or under a revoked instruction. The message names the skill and the reason.
 *
 * @api
 */
final class SkillInstructionWithdrawnException extends RuntimeException implements NrLlmExceptionInterface
{
    public function __construct(
        public readonly SkillPin $pin,
        public readonly string $skillName,
        public readonly string $reason,
    ) {
        parent::__construct(
            sprintf(
                'The run was stopped: the approved instruction of skill "%s" (uid %d) no longer holds — %s. Start a new run.',
                $skillName,
                $pin->skillUid,
                $reason,
            ),
            1791500101,
        );
    }
}
