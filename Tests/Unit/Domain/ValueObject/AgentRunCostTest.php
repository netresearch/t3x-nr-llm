<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A run that spent tokens and stored no cost ran on a model without a price;
 * its cost is unknown, not 0 (NEXT-188).
 */
#[CoversClass(AgentRun::class)]
final class AgentRunCostTest extends TestCase
{
    private function agentRun(int $totalTokens, float $estimatedCost): AgentRun
    {
        return new AgentRun(
            uid: 580,
            uuid: 'db90ea23-06a1-4d30-a6d9-ae6bf151b2ea',
            status: 'completed',
            configurationUid: 1,
            configurationIdentifier: 'content-assistant',
            beUser: 2,
            iterations: 2,
            truncated: false,
            totalPromptTokens: $totalTokens,
            totalCompletionTokens: 0,
            totalTokens: $totalTokens,
            estimatedCost: $estimatedCost,
            errorClass: '',
            terminationReason: 'completed',
            startedAt: 0,
            finishedAt: 0,
            crdate: 0,
        );
    }

    /**
     * @return iterable<string, array{int, float, bool}>
     */
    public static function runs(): iterable
    {
        yield 'tokens and no cost: the run db90ea23 stored' => [2797, 0.0, false];
        yield 'tokens and a cost' => [2797, 0.006066, true];
        yield 'no tokens cost nothing' => [0, 0.0, true];
    }

    #[Test]
    #[DataProvider('runs')]
    public function theCostIsKnownOnlyWhenItCanBeTheRunsCost(int $totalTokens, float $estimatedCost, bool $known): void
    {
        $run = $this->agentRun($totalTokens, $estimatedCost);

        self::assertSame($known, $run->isCostKnown());
        self::assertSame($known, $run->withoutSuspendedState()->isCostKnown());
    }
}
