<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\Queue;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(WorkerVaultScope::class)]
final class WorkerVaultScopeTest extends TestCase
{
    #[Test]
    public function zeroLeavesTheProcessAsItIs(): void
    {
        $actor = $this->createMock(TechnicalActorContextInterface::class);
        $actor->expects(self::never())->method('runAs');

        (new WorkerVaultScope($actor, $this->configured(['worker' => ['technicalBeUserUid' => '0']])))->run($this->work(...));
    }

    #[Test]
    public function anUnreadableConfigurationCountsAsZero(): void
    {
        $actor = $this->createMock(TechnicalActorContextInterface::class);
        $actor->expects(self::never())->method('runAs');

        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new RuntimeException('no configuration'));

        (new WorkerVaultScope($actor, $configuration))->run($this->work(...));
    }

    #[Test]
    public function theCommandLineRunsAsTheConfiguredUser(): void
    {
        $result = $this->work();

        $actor = $this->createMock(TechnicalActorContextInterface::class);
        $actor->expects(self::once())->method('runAs')->with(7, self::anything())->willReturn($result);

        self::assertSame($result, (new WorkerVaultScope($actor, $this->configured(['worker' => ['technicalBeUserUid' => '7']]), true))->run($this->work(...)));
    }

    #[Test]
    public function aWebRequestKeepsItsLoggedInUser(): void
    {
        $actor = $this->createMock(TechnicalActorContextInterface::class);
        $actor->expects(self::never())->method('runAs');

        (new WorkerVaultScope($actor, $this->configured(['worker' => ['technicalBeUserUid' => '7']]), false))->run($this->work(...));
    }

    private function work(): AgentRunResult
    {
        return new AgentRunResult(outcome: AgentRunOutcome::COMPLETED, runUuid: 'run', steps: []);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function configured(array $values): ExtensionConfiguration
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn($values);

        return $configuration;
    }
}
