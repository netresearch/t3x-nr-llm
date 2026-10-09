<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

/**
 * Reads whether a skill record still exists and is not orphaned, as stored
 * now (ADR-214 item 6, the pin rules).
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
interface SkillRecordLookupInterface
{
    public function existsAndNotOrphaned(int $skillUid): bool;
}
