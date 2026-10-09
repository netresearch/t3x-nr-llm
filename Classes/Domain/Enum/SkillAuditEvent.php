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
     * An existing skill re-synced with an unchanged version, or a changed
     * version of a skill that was not enabled.
     */
    case INGEST_UPDATED = 'ingest_updated';

    /**
     * An enabled skill auto-disabled because its version changed: the body or
     * a frontmatter field the version digest covers (ADR-214 item 1).
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
     * An administrator approved one version (digest) of a skill as an
     * instruction (ADR-214 item 2).
     */
    case VERSION_APPROVED = 'version_approved';

    /**
     * An administrator revoked the approvals of one version (digest).
     */
    case VERSION_REVOKED = 'version_revoked';

    /**
     * An approval was refused: the version the approver saw is no longer the
     * current one, or the record failed its integrity check.
     */
    case VERSION_APPROVAL_REFUSED = 'version_approval_refused';

    /**
     * The version-digest upgrade wizard found stored fields that do not match
     * what the sync wrote, disabled the skill and left it without a digest.
     */
    case VERSION_DIGEST_UNVERIFIED = 'version_digest_unverified';

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
