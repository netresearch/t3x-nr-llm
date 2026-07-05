<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Overview;

use Netresearch\NrLlm\Domain\Enum\OverviewCardState;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\Overview\OverviewReadinessService;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OverviewReadinessService::class)]
#[CoversClass(\Netresearch\NrLlm\Service\Overview\OverviewCardStatus::class)]
final class OverviewReadinessServiceTest extends TestCase
{
    #[Test]
    public function freshInstallMakesProvidersTheNextStepAndLocksTheRest(): void
    {
        $statuses = $this->buildStatuses();

        self::assertSame(OverviewCardState::Next, $statuses['providers']->state);
        self::assertSame(OverviewCardState::Locked, $statuses['models']->state);
        self::assertSame(OverviewCardState::Locked, $statuses['configurations']->state);
        self::assertSame(OverviewCardState::Locked, $statuses['tryit']->state);
        self::assertSame(OverviewCardState::EmptyState, $statuses['tasks']->state);
        self::assertSame(OverviewCardState::EmptyState, $statuses['snippets']->state);
        self::assertSame(OverviewCardState::EmptyState, $statuses['skills']->state);
        self::assertSame(OverviewCardState::EmptyState, $statuses['tools']->state);
    }

    #[Test]
    public function withProvidersOnlyModelsIsNextAndConfigurationsLocked(): void
    {
        $statuses = $this->buildStatuses(providers: 2);

        self::assertSame(OverviewCardState::Ready, $statuses['providers']->state);
        self::assertSame(OverviewCardState::Next, $statuses['models']->state);
        self::assertSame(OverviewCardState::Locked, $statuses['configurations']->state);
        self::assertSame(OverviewCardState::Locked, $statuses['tryit']->state);
    }

    #[Test]
    public function withProvidersAndModelsConfigurationsIsNext(): void
    {
        $statuses = $this->buildStatuses(providers: 2, models: 3);

        self::assertSame(OverviewCardState::Ready, $statuses['providers']->state);
        self::assertSame(OverviewCardState::Ready, $statuses['models']->state);
        self::assertSame(OverviewCardState::Next, $statuses['configurations']->state);
        self::assertSame(OverviewCardState::Locked, $statuses['tryit']->state);
    }

    #[Test]
    public function fullyConfiguredWithoutUsageMakesTryItTheNextStep(): void
    {
        $statuses = $this->buildStatuses(providers: 2, models: 3, configurations: 1, hasDefault: true, modelHasDefault: true);

        self::assertSame(OverviewCardState::Ready, $statuses['providers']->state);
        self::assertSame(OverviewCardState::Ready, $statuses['models']->state);
        self::assertSame(OverviewCardState::Ready, $statuses['configurations']->state);
        self::assertTrue($statuses['configurations']->hasDefault);
        self::assertTrue($statuses['models']->hasDefault);
        self::assertSame(OverviewCardState::Next, $statuses['tryit']->state);
    }

    #[Test]
    public function fullyConfiguredWithUsageMakesTryItNeutral(): void
    {
        $statuses = $this->buildStatuses(providers: 2, models: 3, configurations: 1, requests: 42);

        self::assertSame(OverviewCardState::Neutral, $statuses['tryit']->state);
    }

    #[Test]
    public function optionalModulesAreReadyWhenTheyHaveEntries(): void
    {
        $statuses = $this->buildStatuses(
            tasks: 4,
            snippets: 2,
            skillsTotal: 60,
            skillsEnabled: 12,
            toolsEnabled: 8,
        );

        self::assertSame(OverviewCardState::Ready, $statuses['tasks']->state);
        self::assertSame(4, $statuses['tasks']->count);
        self::assertSame(OverviewCardState::Ready, $statuses['snippets']->state);
        self::assertSame(OverviewCardState::Ready, $statuses['skills']->state);
        self::assertSame(60, $statuses['skills']->count);
        self::assertSame(12, $statuses['skills']->enabledCount);
        self::assertSame(OverviewCardState::Ready, $statuses['tools']->state);
        self::assertSame(8, $statuses['tools']->enabledCount);
    }

    /**
     * @return array<string, \Netresearch\NrLlm\Service\Overview\OverviewCardStatus>
     */
    private function buildStatuses(
        int $providers = 0,
        int $models = 0,
        int $configurations = 0,
        bool $hasDefault = false,
        bool $modelHasDefault = false,
        int $tasks = 0,
        int $snippets = 0,
        int $skillsTotal = 0,
        int $skillsEnabled = 0,
        int $toolsEnabled = 0,
        int $requests = 0,
    ): array {
        $providerRepo = $this->createMock(ProviderRepository::class);
        $providerRepo->method('countActive')->willReturn($providers);

        $modelRepo = $this->createMock(ModelRepository::class);
        $modelRepo->method('countActive')->willReturn($models);
        $modelRepo->method('findDefault')->willReturn(
            $modelHasDefault ? $this->createMock(Model::class) : null,
        );

        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $configRepo->method('countActive')->willReturn($configurations);
        $configRepo->method('findDefault')->willReturn(
            $hasDefault ? $this->createMock(LlmConfiguration::class) : null,
        );

        $taskRepo = $this->createMock(TaskRepository::class);
        $taskRepo->method('countActive')->willReturn($tasks);

        $snippetRepo = $this->createMock(PromptSnippetRepository::class);
        $snippetRepo->method('countActive')->willReturn($snippets);

        $skillRepo = $this->createMock(SkillRepository::class);
        $skillRepo->method('countAll')->willReturn($skillsTotal);
        $skillRepo->method('countEnabled')->willReturn($skillsEnabled);

        $availability = $this->createMock(ToolAvailabilityServiceInterface::class);
        $availability->method('enabledNames')->willReturn(
            array_fill(0, $toolsEnabled, 'tool'),
        );

        $analytics = $this->createMock(UsageAnalyticsServiceInterface::class);
        $analytics->method('getKpiTotals')->willReturn([
            'cost'      => 0.0,
            'requests'  => $requests,
            'tokens'    => 0,
            'providers' => 0,
            'models'    => 0,
        ]);

        $service = new OverviewReadinessService(
            $providerRepo,
            $modelRepo,
            $configRepo,
            $taskRepo,
            $snippetRepo,
            $skillRepo,
            new ToolRegistry([]),
            $availability,
            $analytics,
        );

        return $service->buildStatuses();
    }
}
