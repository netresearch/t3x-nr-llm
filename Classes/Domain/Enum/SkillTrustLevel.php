<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Publisher-trust classification of a skill source (ADR-061).
 *
 * This is the *identity/provenance* axis and is deliberately distinct from
 * {@see SupportStatus}, which only records whether referenced assets are
 * executed and is explicitly NOT a safety signal (ADR-035). Trust rises from
 * `untrusted` (anonymous public GitHub content) to `first_party` (a source the
 * operator publishes and controls). The ordinal {@see rank()} lets the
 * injection path enforce a configurable *minimum* trust fail-closed: an
 * unknown/invalid stored value resolves to the lowest rank, so it is gated out
 * whenever a minimum above `untrusted` is configured.
 */
enum SkillTrustLevel: string
{
    case UNTRUSTED = 'untrusted';
    case COMMUNITY = 'community';
    case VERIFIED = 'verified';
    case FIRST_PARTY = 'first_party';

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

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }

    /**
     * Resolve a stored string to a level, failing CLOSED to the lowest trust
     * ({@see self::UNTRUSTED}) for an empty or unrecognised value — so a
     * corrupt/legacy row is never treated as more trusted than it is.
     */
    public static function fromStringOrUntrusted(string $value): self
    {
        return self::tryFrom($value) ?? self::UNTRUSTED;
    }

    /**
     * Monotonic ordinal for comparisons (higher = more trusted). Used to gate
     * injection against a configured minimum trust level.
     */
    public function rank(): int
    {
        return match ($this) {
            self::UNTRUSTED   => 0,
            self::COMMUNITY   => 1,
            self::VERIFIED    => 2,
            self::FIRST_PARTY => 3,
        };
    }

    /**
     * Whether a skill at this level satisfies a required minimum level.
     */
    public function satisfies(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }
}
