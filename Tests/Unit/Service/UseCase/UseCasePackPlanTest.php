<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\UseCase;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Service\Governance\GovernanceProfile;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\Preset\PresetPreflightResult;
use Netresearch\NrLlm\Service\UseCase\UseCase;
use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use Netresearch\NrLlm\Service\UseCase\UseCasePackInstallResult;
use Netresearch\NrLlm\Service\UseCase\UseCasePackPlan;
use Netresearch\NrLlm\Service\UseCase\UseCasePackPlanItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UseCasePackPlan::class)]
#[CoversClass(UseCasePackPlanItem::class)]
#[CoversClass(UseCasePackInstallResult::class)]
final class UseCasePackPlanTest extends TestCase
{
    private function pack(): UseCasePack
    {
        return new UseCasePack(
            identifier: 'fixture-pack',
            useCase: UseCase::EDITORIAL,
            name: 'Fixture Pack',
            description: '',
            configurationPreset: new ConfigurationPreset(
                identifier: 'ext.fixture',
                name: 'Fixture',
                description: '',
                criteria: new ModelSelectionCriteria(capabilities: ['chat']),
            ),
            recommendedGovernanceProfile: GovernanceProfile::LOCAL_ONLY,
        );
    }

    /**
     * @param list<UseCasePackPlanItem> $tasks
     * @param list<string>              $missingSnippetTags
     */
    private function plan(
        bool $configurationInstalled,
        array $tasks,
        bool $satisfiable = true,
        array $missingSnippetTags = [],
    ): UseCasePackPlan {
        return new UseCasePackPlan(
            pack: $this->pack(),
            configuration: new UseCasePackPlanItem('ext.fixture', 'Fixture', $configurationInstalled),
            tasks: $tasks,
            snippets: [],
            preflight: $satisfiable
                ? PresetPreflightResult::satisfiable('GPT-5')
                : PresetPreflightResult::unsatisfiable('capabilities: chat'),
            missingSnippetTags: $missingSnippetTags,
        );
    }

    #[Test]
    public function countsSplitPendingFromInstalled(): void
    {
        $plan = $this->plan(true, [
            new UseCasePackPlanItem('a', 'A', true),
            new UseCasePackPlanItem('b', 'B', false),
        ]);

        self::assertSame(1, $plan->getPendingCount());
        self::assertSame(2, $plan->getInstalledCount());
        self::assertTrue($plan->hasPendingItems());
        self::assertFalse($plan->isFullyInstalled());
    }

    #[Test]
    public function aFullyInstalledPackAnswersWithBooleansNotAZero(): void
    {
        // A numeric 0 is falsy in Fluid, so the template asks the boolean. If
        // this pair ever disagreed with getPendingCount(), the "nothing left to
        // create" branch would be the one that silently disappeared.
        $plan = $this->plan(true, [new UseCasePackPlanItem('a', 'A', true)]);

        self::assertSame(0, $plan->getPendingCount());
        self::assertFalse($plan->hasPendingItems());
        self::assertTrue($plan->isFullyInstalled());
        self::assertFalse($plan->isInstallable());
    }

    #[Test]
    public function anUnsatisfiablePackWithNoConfigurationYetIsNotInstallable(): void
    {
        $plan = $this->plan(false, [new UseCasePackPlanItem('a', 'A', false)], satisfiable: false);

        self::assertTrue($plan->hasPendingItems());
        self::assertFalse($plan->isInstallable());
    }

    #[Test]
    public function anUnsatisfiablePackWhoseConfigurationExistsStaysInstallable(): void
    {
        // The remaining work is tasks and snippets, and neither needs a
        // resolvable model to be created.
        $plan = $this->plan(true, [new UseCasePackPlanItem('a', 'A', false)], satisfiable: false);

        self::assertTrue($plan->isInstallable());
    }

    #[Test]
    public function aMissingTagLinkKeepsThePackUnfinishedEvenWhenEveryRecordExists(): void
    {
        // The state the preset-first install order produces: every record is
        // there, and the snippets are composed into nothing. If this counted as
        // finished, the confirm button that repairs it would be hidden.
        $plan = $this->plan(
            true,
            [new UseCasePackPlanItem('a', 'A', true)],
            missingSnippetTags: ['tone_of_voice'],
        );

        self::assertSame(0, $plan->getPendingCount());
        self::assertSame(['tone_of_voice'], $plan->missingSnippetTags);
        self::assertFalse($plan->isFullyInstalled());
        self::assertTrue($plan->isInstallable());
    }

    #[Test]
    public function theInstallResultReportsCreatedAndSkippedSeparately(): void
    {
        $result = new UseCasePackInstallResult(
            createdConfiguration: false,
            createdTasks: ['a'],
            skippedTasks: ['b', 'c'],
            createdSnippets: [],
            skippedSnippets: ['d'],
        );

        self::assertSame(1, $result->getCreatedCount());
        // The configuration counts as skipped when it was not created.
        self::assertSame(4, $result->getSkippedCount());
    }
}
