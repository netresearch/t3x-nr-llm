<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrVault\Crypto\EnvelopeCodecInterface;

/**
 * Tells an agent-state column value apart from cleartext, and brings a
 * legacy-marked value into the current form (ADR-114).
 *
 * nr-llm 0.24.0 through 0.25.x wrote its own ``v2:`` marker before the envelope
 * moved to nr-vault's codec. The body after the marker is byte-identical to what
 * the vault writes, so a legacy row is read by swapping the marker rather than by
 * migrating data.
 *
 * This lives in one class because BOTH the read path
 * ({@see AgentStateCodec::decode()}) and the rotation path
 * ({@see AgentStateEnvelopeRotator::rewrapAll()}) need it, and they must agree.
 * They did not, at first: the rotator found legacy rows with its SQL predicate
 * and then skipped every one of them, because the vault's ``isSealed()`` only
 * recognises its own marker. The rows would have been left wrapped under the
 * retired key — the precise failure the rotator exists to prevent.
 */
final readonly class AgentStateEnvelopeMarker
{
    /** Marker written by nr-llm 0.24.0-0.25.x. */
    public const LEGACY_MARKER = 'v2:';

    /**
     * Return the value in the current marker form, or null when it is not an
     * envelope at all (pre-encryption cleartext, or empty).
     */
    public static function normalise(string $stored): ?string
    {
        // No empty-string guard: '' starts with neither marker, so it already
        // falls through to null.
        if (str_starts_with($stored, EnvelopeCodecInterface::MARKER)) {
            return $stored;
        }

        if (str_starts_with($stored, self::LEGACY_MARKER)) {
            return EnvelopeCodecInterface::MARKER
                . substr($stored, \strlen(self::LEGACY_MARKER));
        }

        return null;
    }

    /**
     * Every marker a stored envelope may start with, for a SQL prefix match.
     *
     * @return list<string>
     */
    public static function markers(): array
    {
        return [EnvelopeCodecInterface::MARKER, self::LEGACY_MARKER];
    }
}
