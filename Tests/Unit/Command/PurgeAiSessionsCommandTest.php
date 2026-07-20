<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\PurgeAiSessionsCommand;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryAiSessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(PurgeAiSessionsCommand::class)]
final class PurgeAiSessionsCommandTest extends TestCase
{
    #[Test]
    public function defaultWindowComesFromTheConversationRetentionSetting(): void
    {
        $repository               = new InMemoryAiSessionRepository();
        $repository->purgeReturns = 4;

        $tester = new CommandTester(new PurgeAiSessionsCommand($repository, $this->policy(['retention' => ['conversation' => '7']])));

        $before = time();
        $exit   = $tester->execute([]);
        $after  = time();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotNull($repository->purgeCutoff);
        self::assertGreaterThanOrEqual($before - (7 * 86400), $repository->purgeCutoff);
        self::assertLessThanOrEqual($after - (7 * 86400), $repository->purgeCutoff);
        self::assertStringContainsString('Deleted 4 conversation session(s)', $tester->getDisplay());
    }

    #[Test]
    public function fallsBackToTheGlobalRetentionWindow(): void
    {
        $repository = new InMemoryAiSessionRepository();

        $tester = new CommandTester(new PurgeAiSessionsCommand($repository, $this->policy(['retentionDays' => '90'])));

        $before = time();
        $tester->execute([]);
        $after = time();

        self::assertNotNull($repository->purgeCutoff);
        self::assertGreaterThanOrEqual($before - (90 * 86400), $repository->purgeCutoff);
        self::assertLessThanOrEqual($after - (90 * 86400), $repository->purgeCutoff);
    }

    #[Test]
    public function honoursCustomDaysOption(): void
    {
        $repository = new InMemoryAiSessionRepository();

        $tester = new CommandTester(new PurgeAiSessionsCommand($repository, $this->policy(['retentionDays' => '90'])));

        $before = time();
        $tester->execute(['--days' => '3']);
        $after = time();

        self::assertNotNull($repository->purgeCutoff);
        self::assertGreaterThanOrEqual($before - (3 * 86400), $repository->purgeCutoff);
        self::assertLessThanOrEqual($after - (3 * 86400), $repository->purgeCutoff);
    }

    #[Test]
    public function rejectsNonPositiveDaysAndPurgesNothing(): void
    {
        $repository = new InMemoryAiSessionRepository();

        $tester = new CommandTester(new PurgeAiSessionsCommand($repository, $this->policy(['retentionDays' => '30'])));

        $exit = $tester->execute(['--days' => '0']);

        self::assertSame(Command::INVALID, $exit);
        self::assertNull($repository->purgeCutoff, 'No purge must run for an invalid window.');
        self::assertStringContainsString('positive integer', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $privacyConfig
     */
    private function policy(array $privacyConfig): PrivacyPolicy
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['privacy' => $privacyConfig]);

        return new PrivacyPolicy($extensionConfiguration, new ContentRedactor());
    }
}
