<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

use Netresearch\NrLlm\Domain\ValueObject\RunStep;

/**
 * Kind of a persisted agent-run event (ADR-081).
 *
 * These map one-to-one onto the four {@see RunStep} kinds the tool loop emits
 * through {@see \Netresearch\NrLlm\Service\Tool\RunTrace}. The enum is
 * intentionally limited to what the loop actually produces today; richer kinds
 * (approval requests, artifacts, streamed text deltas) are added by the epics
 * that emit them, not speculatively here.
 */
enum AgentEventKind: string
{
    case REQUEST = 'request';
    case LLM = 'llm';
    case TOOL = 'tool';
    case ASSEMBLED = 'assembled';

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

    /**
     * Map a {@see RunStep} kind constant onto its event kind. Returns null for an
     * unknown value so a future RunStep kind cannot silently masquerade as a
     * known one.
     */
    public static function fromRunStepKind(string $kind): ?self
    {
        return self::tryFrom($kind);
    }
}
