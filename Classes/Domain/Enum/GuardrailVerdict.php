<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * A guardrail's decision about a provider response (ADR-085).
 *
 * Richer than a boolean so a guardrail can express the full range of policy
 * outcomes: pass it, rewrite it, block it, ask for it again, or route it to a
 * human.
 */
enum GuardrailVerdict: string
{
    /**
     * The response is fine as-is.
     */
    case ALLOW = 'allow';

    /**
     * Rewrite the response (the guardrail supplies the replacement content).
     */
    case REDACT = 'redact';

    /**
     * Ask the provider again once (e.g. the response failed a quality check).
     */
    case RETRY = 'retry';

    /**
     * Block automatic use; the response needs human approval (the Epic-D / review-queue seam).
     */
    case REQUIRE_APPROVAL = 'require_approval';

    /**
     * Block the response outright.
     */
    case DENY = 'deny';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
