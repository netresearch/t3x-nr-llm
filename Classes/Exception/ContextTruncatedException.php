<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use Netresearch\NrLlm\Domain\ValueObject\ContextFitResult;
use RuntimeException;

/**
 * Thrown inside the agent loop when even the pruned floor of a transcript still
 * exceeds the model's context window (ADR-107).
 *
 * Control flow, not a provider failure: {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}
 * catches it, issues NO provider call, and settles the run on
 * {@see \Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason::CONTEXT_TRUNCATED}
 * — a legible terminal state instead of a misclassified provider 4xx. Carries
 * the {@see ContextFitResult} for observability.
 */
final class ContextTruncatedException extends RuntimeException implements NrLlmExceptionInterface
{
    public function __construct(
        public readonly ContextFitResult $fit,
    ) {
        parent::__construct(
            sprintf(
                'The transcript exceeds the model context window even after pruning to its floor '
                . '(estimated %d tokens > budget %d).',
                $fit->estimatedTokens,
                $fit->budget,
            ),
            1753100001,
        );
    }

    /**
     * Named constructor — thrown via this factory (not `throw new`) so the fixed
     * code lives in the constructor once rather than at each throw site.
     */
    public static function fromFit(ContextFitResult $fit): self
    {
        return new self($fit);
    }
}
