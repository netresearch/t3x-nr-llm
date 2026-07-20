<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Where a provider's inference actually happens, and therefore how much the
 * extension may send it (ADR-094).
 *
 * The zone is an **operator declaration, not a technical control**. Nothing
 * stops an administrator labelling an OpenAI provider LOCAL; the extension
 * cannot verify where an endpoint runs. What the declaration buys is that the
 * decision is made once, in one place, by the person who knows the answer —
 * instead of being implied by whichever tools happen to be enabled.
 *
 * Each zone implies a ceiling on the {@see ToolDataClass} a tool may return
 * into a run that reaches that provider. One monotone comparison rather than a
 * per-installation matrix: a 6x4 grid is configuration nobody gets right, and
 * an operator who disagrees with a ceiling can move the provider to another
 * zone.
 */
enum TrustZone: string
{
    /**
     * Runs on this machine or this network segment; nothing leaves the house.
     */
    case LOCAL = 'local';

    /**
     * Dedicated hosting under the operator's own contract and control.
     */
    case PRIVATE_HOSTED = 'privateHosted';

    /**
     * A shared external service under EU jurisdiction.
     */
    case EXTERNAL_EU = 'externalEu';

    /**
     * A shared external service anywhere. The default for anything unstated.
     */
    case EXTERNAL_GLOBAL = 'externalGlobal';

    /**
     * The most sensitive data class a run reaching this zone may collect from
     * a tool.
     */
    public function maxDataClass(): ToolDataClass
    {
        return match ($this) {
            self::LOCAL => ToolDataClass::SECRET_ADJACENT,
            self::PRIVATE_HOSTED => ToolDataClass::SYSTEM_DIAGNOSTICS,
            self::EXTERNAL_EU => ToolDataClass::INTERNAL_CONFIGURATION,
            self::EXTERNAL_GLOBAL => ToolDataClass::EDITOR_CONTENT,
        };
    }

    public function permits(ToolDataClass $class): bool
    {
        return $class->isAtMost($this->maxDataClass());
    }

    /**
     * Trust ordering, 0 = most trusted. Used to pick the worst zone a run can
     * reach across a fallback chain.
     */
    public function rank(): int
    {
        return match ($this) {
            self::LOCAL => 0,
            self::PRIVATE_HOSTED => 1,
            self::EXTERNAL_EU => 2,
            self::EXTERNAL_GLOBAL => 3,
        };
    }

    /**
     * The least trusted of two zones — a run that can reach either must be
     * judged by the worse one.
     */
    public static function leastTrusted(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }

    /**
     * Resolve a stored value, falling back to the strictest zone. An empty
     * column on an un-migrated row, or a value from a future version, must
     * never widen the gate.
     */
    public static function fromStringOrStrictest(string $value): self
    {
        return self::tryFrom($value) ?? self::EXTERNAL_GLOBAL;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
