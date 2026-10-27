<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One budget window (today or this month) on the three axes the budget record
 * limits: requests, tokens and cost.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class BudgetWindowStatus
{
    public function __construct(
        public BudgetMeter $requests,
        public BudgetMeter $tokens,
        public BudgetMeter $cost,
    ) {}

    /**
     * @param array{requests: int, tokens: int, cost: float} $usage the window's usage as {@see \Netresearch\NrLlm\Service\Budget\BudgetUsageWindowsInterface::aggregate()} returns it
     */
    public static function fromUsage(array $usage, int $requestLimit, int $tokenLimit, float $costLimit): self
    {
        return new self(
            new BudgetMeter((float) $usage['requests'], (float) $requestLimit, false),
            new BudgetMeter((float) $usage['tokens'], (float) $tokenLimit, false),
            new BudgetMeter($usage['cost'], $costLimit, true),
        );
    }

    /**
     * @return array{
     *   requests: array{used: string, limit: string, limited: bool, percent: int},
     *   tokens: array{used: string, limit: string, limited: bool, percent: int},
     *   cost: array{used: string, limit: string, limited: bool, percent: int}
     * }
     */
    public function toArray(): array
    {
        return [
            'requests' => $this->requests->toArray(),
            'tokens'   => $this->tokens->toArray(),
            'cost'     => $this->cost->toArray(),
        ];
    }
}
