<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\PurgeGovernanceCommand;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryGovernanceEventRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(PurgeGovernanceCommand::class)]
final class PurgeGovernanceCommandTest extends TestCase
{
    #[Test]
    public function purgesWithTheConfiguredGovernanceWindow(): void
    {
        $repository = $this->spyRepository(deleted: 4);
        $tester     = new CommandTester(new PurgeGovernanceCommand($repository, $this->policyWithGovernanceRetention(30)));

        $before = time();
        $exit   = $tester->execute([]);
        $after  = time();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotNull($repository->purgeCutoff);
        self::assertGreaterThanOrEqual($before - (30 * 86400), $repository->purgeCutoff);
        self::assertLessThanOrEqual($after - (30 * 86400), $repository->purgeCutoff);
        self::assertStringContainsString('4 governance-event row(s)', $tester->getDisplay());
    }

    #[Test]
    public function honoursCustomDaysOption(): void
    {
        $repository = $this->spyRepository(deleted: 0);
        $tester     = new CommandTester(new PurgeGovernanceCommand($repository, $this->policyWithGovernanceRetention(30)));

        $before = time();
        $exit   = $tester->execute(['--days' => '7']);
        $after  = time();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotNull($repository->purgeCutoff);
        self::assertGreaterThanOrEqual($before - (7 * 86400), $repository->purgeCutoff);
        self::assertLessThanOrEqual($after - (7 * 86400), $repository->purgeCutoff);
    }

    #[Test]
    public function rejectsNonPositiveDays(): void
    {
        $repository = $this->spyRepository(deleted: 0);
        $tester     = new CommandTester(new PurgeGovernanceCommand($repository, $this->policyWithGovernanceRetention(30)));

        $exit = $tester->execute(['--days' => '0']);

        self::assertSame(Command::INVALID, $exit);
        self::assertNull($repository->purgeCutoff, 'No purge must run for an invalid window.');
        self::assertStringContainsString('positive integer', $tester->getDisplay());
    }

    private function policyWithGovernanceRetention(int $days): PrivacyPolicy
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'privacy' => ['retention' => ['governance' => (string)$days]],
        ]);

        return new PrivacyPolicy($extensionConfiguration, new ContentRedactor());
    }

    private function spyRepository(int $deleted): InMemoryGovernanceEventRepository
    {
        $repository               = new InMemoryGovernanceEventRepository();
        $repository->purgeReturns = $deleted;

        return $repository;
    }
}
