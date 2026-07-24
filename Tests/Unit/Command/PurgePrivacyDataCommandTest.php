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
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryAgentRunRepository;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryAiSessionRepository;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryEvaluationResultRepository;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryGovernanceEventRepository;
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
    private InMemoryEvaluationResultRepository $eval;

    private InMemorySkillAuditRepository $audit;

    private InMemoryTelemetryRepository $telemetry;

    private InMemoryAiSessionRepository $sessions;

    private InMemoryAgentRunRepository $agentRuns;

    private InMemoryGovernanceEventRepository $governance;

    protected function setUp(): void
    {
        $this->eval       = new InMemoryEvaluationResultRepository();
        $this->audit      = new InMemorySkillAuditRepository();
        $this->telemetry  = new InMemoryTelemetryRepository();
        $this->sessions   = new InMemoryAiSessionRepository();
        $this->agentRuns  = new InMemoryAgentRunRepository();
        $this->governance = new InMemoryGovernanceEventRepository();
    }

    #[Test]
    public function purgesEveryContentBearingTableWithTheConfiguredRetentionDefault(): void
    {
        $this->eval->purgeReturns                = 2;
        $this->audit->purgeReturns               = 3;
        $this->telemetry->purgeReturns           = 5;
        $this->sessions->purgeReturns            = 7;
        $this->agentRuns->purgeReturns           = 11;
        $this->agentRuns->purgeUnfinishedReturns = 1;
        $this->governance->purgeReturns          = 4;

        $tester = new CommandTester($this->command(['retentionDays' => '45']));

        $before = time();
        $exit   = $tester->execute([]);
        $after  = time();

        self::assertSame(Command::SUCCESS, $exit);

        // The default window is the configured retention (45 days), applied to
        // every category that has no override of its own.
        $cutoffs = [
            $this->eval->purgeCutoff,
            $this->audit->purgeCutoff,
            $this->telemetry->purgeCutoff,
            $this->sessions->purgeCutoff,
            $this->agentRuns->purgeCutoff,
            $this->agentRuns->purgeUnfinishedCutoff,
            $this->governance->purgeCutoff,
        ];

        foreach ($cutoffs as $cutoff) {
            self::assertNotNull($cutoff);
            self::assertGreaterThanOrEqual($before - (45 * 86400), $cutoff);
            self::assertLessThanOrEqual($after - (45 * 86400), $cutoff);
        }

        $display = $tester->getDisplay();
        self::assertStringContainsString('Evaluation results (45 d): 2', $display);
        self::assertStringContainsString('Skill audit (45 d): 3', $display);
        self::assertStringContainsString('Telemetry (45 d): 5', $display);
        self::assertStringContainsString('Conversation sessions (45 d): 7', $display);
        self::assertStringContainsString('Finished agent runs (45 d): 11', $display);
        self::assertStringContainsString('Unfinished agent runs (45 d): 1', $display);
        self::assertStringContainsString('Governance events (45 d): 4', $display);
    }

    #[Test]
    public function appliesPerCategoryRetentionOverrides(): void
    {
        $tester = new CommandTester($this->command([
            'retentionDays' => '90',
            'retention'     => [
                'conversation' => '7',
                'approval'     => '180',
            ],
        ]));

        $before = time();
        $exit   = $tester->execute([]);
        $after  = time();

        self::assertSame(Command::SUCCESS, $exit);

        self::assertNotNull($this->sessions->purgeCutoff);
        self::assertGreaterThanOrEqual($before - (7 * 86400), $this->sessions->purgeCutoff);
        self::assertLessThanOrEqual($after - (7 * 86400), $this->sessions->purgeCutoff);

        // Runs awaiting a decision live longer than finished ones, so a pending
        // approval is not deleted out from under its approver.
        self::assertNotNull($this->agentRuns->purgeUnfinishedCutoff);
        self::assertLessThanOrEqual($after - (180 * 86400), $this->agentRuns->purgeUnfinishedCutoff);

        // Categories without an override keep the global default.
        self::assertNotNull($this->agentRuns->purgeCutoff);
        self::assertGreaterThanOrEqual($before - (90 * 86400), $this->agentRuns->purgeCutoff);

        self::assertStringContainsString('Conversation sessions (7 d)', $tester->getDisplay());
        self::assertStringContainsString('Unfinished agent runs (180 d)', $tester->getDisplay());
    }

    #[Test]
    public function daysOptionOverridesEveryCategory(): void
    {
        $tester = new CommandTester($this->command([
            'retentionDays' => '30',
            'retention'     => ['conversation' => '7'],
        ]));

        $before = time();
        $exit   = $tester->execute(['--days' => '3']);
        $after  = time();

        self::assertSame(Command::SUCCESS, $exit);

        foreach ([$this->eval->purgeCutoff, $this->sessions->purgeCutoff, $this->agentRuns->purgeUnfinishedCutoff] as $cutoff) {
            self::assertNotNull($cutoff);
            self::assertGreaterThanOrEqual($before - (3 * 86400), $cutoff);
            self::assertLessThanOrEqual($after - (3 * 86400), $cutoff);
        }
    }

    #[Test]
    public function rejectsNonPositiveDaysAndPurgesNothing(): void
    {
        $tester = new CommandTester($this->command(['retentionDays' => '30']));

        $exit = $tester->execute(['--days' => '0']);

        self::assertSame(Command::INVALID, $exit);
        self::assertNull($this->eval->purgeCutoff, 'No purge runs for an invalid window.');
        self::assertNull($this->audit->purgeCutoff);
        self::assertNull($this->telemetry->purgeCutoff);
        self::assertNull($this->sessions->purgeCutoff);
        self::assertNull($this->agentRuns->purgeCutoff);
        self::assertNull($this->agentRuns->purgeUnfinishedCutoff);
        self::assertNull($this->governance->purgeCutoff);
        self::assertStringContainsString('positive integer', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $privacyConfig
     */
    private function command(array $privacyConfig): PurgePrivacyDataCommand
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['privacy' => $privacyConfig]);

        return new PurgePrivacyDataCommand(
            new PrivacyPolicy($extensionConfiguration, new ContentRedactor()),
            $this->eval,
            $this->audit,
            $this->telemetry,
            $this->sessions,
            $this->agentRuns,
            $this->governance,
        );
    }
}
