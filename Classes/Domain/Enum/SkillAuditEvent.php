<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * The lifecycle events recorded in the append-only skill audit trail (ADR-061).
 *
 * Every ingest outcome, every enable/disable, and every fail-closed rejection
 * (manifest-fingerprint mismatch, high-confidence injection finding) is written
 * as one immutable row so the provenance of any skill that reaches a prompt is
 * reconstructable after the fact.
 */
enum SkillAuditEvent: string
{
    /**
     * A new skill materialized from a source.
     */
    case INGEST_CREATED = 'ingest_created';

    /**
     * An existing skill re-synced with unchanged body.
     */
    case INGEST_UPDATED = 'ingest_updated';

    /**
     * An enabled skill auto-disabled because its body checksum changed.
     */
    case INGEST_DISABLED_ON_CHANGE = 'ingest_disabled_on_change';

    /**
     * A skill absent upstream marked orphaned + disabled.
     */
    case ORPHANED = 'orphaned';

    /**
     * An admin enabled a skill.
     */
    case ENABLED = 'enabled';

    /**
     * An admin disabled a skill.
     */
    case DISABLED = 'disabled';

    /**
     * Ingest rejected: the source manifest fingerprint did not verify.
     */
    case FINGERPRINT_REJECTED = 'fingerprint_rejected';

    /**
     * Ingest force-disabled a skill on a high-confidence injection finding.
     */
    case INJECTION_BLOCKED = 'injection_blocked';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
