<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

/**
 * The stored facts the load tool re-checks at the moment of a load (ADR-214
 * item 4), read now rather than from the objects the run started with.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
interface SkillLoadLookupInterface
{
    /**
     * Whether the skill is attached to the configuration with the load mode
     * 'on_demand'.
     */
    public function isAttachedOnDemand(int $configurationUid, int $skillUid): bool;

    /**
     * The stored version digest of a skill that may be loaded: the record
     * exists and is not deleted, hidden, disabled, orphaned or a process
     * skill. Null otherwise.
     */
    public function loadableDigest(int $skillUid): ?string;
}
