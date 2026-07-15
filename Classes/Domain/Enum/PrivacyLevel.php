<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * How much per-request content the extension may persist (ADR-064).
 *
 * The levels form a strict-to-loose scale: NONE and METADATA drop the graded
 * output / scan payload entirely (metadata columns are always kept), REDACTED
 * stores a bounded, credential-scrubbed copy, FULL stores it verbatim. When two
 * levels apply, the strictest one wins — NONE is strictest, FULL loosest.
 */
enum PrivacyLevel: string
{
    case NONE = 'none';
    case METADATA = 'metadata';
    case REDACTED = 'redacted';
    case FULL = 'full';

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
     * Whether this level stores the per-request content payload at all. Only
     * REDACTED (bounded copy) and FULL (verbatim) persist content; NONE and
     * METADATA drop it and keep metadata only.
     */
    public function persistsContent(): bool
    {
        return match ($this) {
            self::REDACTED, self::FULL => true,
            self::NONE, self::METADATA => false,
        };
    }

    /**
     * Whether stored content must be passed through the ContentRedactor first.
     * True only for REDACTED — FULL keeps content verbatim, and the dropping
     * levels store nothing to redact.
     */
    public function requiresRedaction(): bool
    {
        return $this === self::REDACTED;
    }

    /**
     * Ordering from strictest (NONE = 0) to loosest (FULL = 3). A lower value is
     * stricter, so {@see strictest()} can pick the more restrictive of two.
     */
    public function severity(): int
    {
        return match ($this) {
            self::NONE => 0,
            self::METADATA => 1,
            self::REDACTED => 2,
            self::FULL => 3,
        };
    }

    /**
     * The stricter (more restrictive) of two levels — NONE beats METADATA beats
     * REDACTED beats FULL. Lets a global default combine with a per-scope
     * override so tightening always wins.
     */
    public static function strictest(self $a, self $b): self
    {
        return $a->severity() <= $b->severity() ? $a : $b;
    }
}
