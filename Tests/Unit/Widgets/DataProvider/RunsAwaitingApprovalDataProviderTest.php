<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryAgentRunRepository;
use Netresearch\NrLlm\Widgets\DataProvider\RunsAwaitingApprovalDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RunsAwaitingApprovalDataProvider::class)]
final class RunsAwaitingApprovalDataProviderTest extends AbstractUnitTestCase
{
    #[Test]
    public function returnsTheWaitingForApprovalCount(): void
    {
        $repository = new InMemoryAgentRunRepository();
        $repository->countInStatusReturns = [AgentRunStatus::WAITING_FOR_APPROVAL->value => 3];

        $provider = new RunsAwaitingApprovalDataProvider($repository);

        self::assertSame(3, $provider->getNumber());
    }

    #[Test]
    public function returnsZeroWhenNoneAreWaiting(): void
    {
        $provider = new RunsAwaitingApprovalDataProvider(new InMemoryAgentRunRepository());

        self::assertSame(0, $provider->getNumber());
    }
}
