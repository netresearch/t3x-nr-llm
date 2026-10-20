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
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface SkillLoadLookupInterface
{
    /**
     * Whether the skill is attached to the configuration with the load mode
     * 'on_demand'.
     */
    public function isAttachedOnDemand(int $configurationUid, int $skillUid): bool;

    /**
     * The current version digest of a skill that may be loaded — computed
     * from the fields of a skill written in the backend, the stored one
     * otherwise — when the record exists and is not deleted, hidden,
     * disabled, orphaned or a process skill. Null otherwise.
     */
    public function loadableDigest(int $skillUid): ?string;

    /**
     * The source uid stored on the skill record now, or null when the record
     * is gone. An approval names a digest AND a source, so a load refuses a
     * skill whose stored source is not the one the run's copy and the
     * catalogue measured.
     */
    public function storedSource(int $skillUid): ?int;

    /**
     * The trust level stored on the skill record now, or null when the record
     * is gone. A load measures the trust floor against this value, not
     * against the object the run started with.
     */
    public function trustLevel(int $skillUid): ?string;
}
