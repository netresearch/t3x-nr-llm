<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocation;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ToolLoopResultTest extends TestCase
{
    #[Test]
    public function toolInvocationExposesPublicProperties(): void
    {
        $invocation = new ToolInvocation(
            name: 'fetch_logs',
            arguments: ['limit' => 5],
            result: 'LOGS',
            isError: false,
        );

        self::assertSame('fetch_logs', $invocation->name);
        self::assertSame(['limit' => 5], $invocation->arguments);
        self::assertSame('LOGS', $invocation->result);
        self::assertFalse($invocation->isError);
    }

    #[Test]
    public function toolLoopResultExposesPublicProperties(): void
    {
        $trace = [
            new ToolInvocation('fetch_logs', ['limit' => 5], 'LOGS', false),
            new ToolInvocation('nope', [], 'Error: unknown tool "nope"', true),
        ];
        $usage = UsageStatistics::fromTokens(10, 5);

        $result = new ToolLoopResult(
            finalContent: 'done',
            trace: $trace,
            iterations: 2,
            truncated: true,
            usage: $usage,
        );

        self::assertSame('done', $result->finalContent);
        self::assertSame($trace, $result->trace);
        self::assertSame(2, $result->iterations);
        self::assertTrue($result->truncated);
        self::assertSame($usage, $result->usage);
        self::assertSame(15, $result->usage->totalTokens);
    }

    #[Test]
    public function traceHoldsToolInvocationInstances(): void
    {
        $result = new ToolLoopResult(
            finalContent: '',
            trace: [new ToolInvocation('fetch_logs', [], 'LOGS', false)],
            iterations: 1,
            truncated: false,
            usage: UsageStatistics::fromTokens(0, 0),
        );

        self::assertContainsOnlyInstancesOf(ToolInvocation::class, $result->trace);
        self::assertCount(1, $result->trace);
        self::assertSame('fetch_logs', $result->trace[0]->name);
    }
}
