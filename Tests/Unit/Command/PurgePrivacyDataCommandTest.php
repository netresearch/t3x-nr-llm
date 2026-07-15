<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\PurgePrivacyDataCommand;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryEvaluationResultRepository;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemorySkillAuditRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(PurgePrivacyDataCommand::class)]
final class PurgePrivacyDataCommandTest extends TestCase
{
    #[Test]
    public function purgesAllThreeTablesWithConfiguredRetentionDefault(): void
    {
        $eval               = new InMemoryEvaluationResultRepository();
        $eval->purgeReturns = 2;
        $audit               = new InMemorySkillAuditRepository();
        $audit->purgeReturns = 3;
        $telemetry               = new InMemoryTelemetryRepository();
        $telemetry->purgeReturns = 5;

        $tester = new CommandTester(new PurgePrivacyDataCommand($this->policyWithRetention(45), $eval, $audit, $telemetry));

        $before = time();
        $exit   = $tester->execute([]);
        $after  = time();

        self::assertSame(Command::SUCCESS, $exit);

        // The default window is the configured retention (45 days), applied to every table.
        foreach ([$eval->purgeCutoff, $audit->purgeCutoff, $telemetry->purgeCutoff] as $cutoff) {
            self::assertNotNull($cutoff);
            self::assertGreaterThanOrEqual($before - (45 * 86400), $cutoff);
            self::assertLessThanOrEqual($after - (45 * 86400), $cutoff);
        }

        $display = $tester->getDisplay();
        self::assertStringContainsString('Evaluation results: 2', $display);
        self::assertStringContainsString('Skill audit: 3', $display);
        self::assertStringContainsString('Telemetry: 5', $display);
    }

    #[Test]
    public function honoursCustomDaysOption(): void
    {
        $eval      = new InMemoryEvaluationResultRepository();
        $audit     = new InMemorySkillAuditRepository();
        $telemetry = new InMemoryTelemetryRepository();

        $tester = new CommandTester(new PurgePrivacyDataCommand($this->policyWithRetention(30), $eval, $audit, $telemetry));

        $before = time();
        $exit   = $tester->execute(['--days' => '7']);
        $after  = time();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotNull($eval->purgeCutoff);
        self::assertGreaterThanOrEqual($before - (7 * 86400), $eval->purgeCutoff);
        self::assertLessThanOrEqual($after - (7 * 86400), $eval->purgeCutoff);
    }

    #[Test]
    public function rejectsNonPositiveDaysAndPurgesNothing(): void
    {
        $eval      = new InMemoryEvaluationResultRepository();
        $audit     = new InMemorySkillAuditRepository();
        $telemetry = new InMemoryTelemetryRepository();

        $tester = new CommandTester(new PurgePrivacyDataCommand($this->policyWithRetention(30), $eval, $audit, $telemetry));

        $exit = $tester->execute(['--days' => '0']);

        self::assertSame(Command::INVALID, $exit);
        self::assertNull($eval->purgeCutoff, 'No purge runs for an invalid window.');
        self::assertNull($audit->purgeCutoff);
        self::assertNull($telemetry->purgeCutoff);
        self::assertStringContainsString('positive integer', $tester->getDisplay());
    }

    private function policyWithRetention(int $days): PrivacyPolicy
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['privacy' => ['retentionDays' => (string)$days]]);

        return new PrivacyPolicy($extensionConfiguration, new ContentRedactor());
    }
}
