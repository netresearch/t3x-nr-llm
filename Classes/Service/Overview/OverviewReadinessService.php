<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Overview;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\OverviewCardState;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;

/**
 * Computes the per-module setup state for the LLM backend overview.
 *
 * The overview folds the old "getting started" stepper onto the module cards.
 * The critical path is Provider → Model → Configuration → Try it: exactly one
 * card is {@see OverviewCardState::Next} (the first incomplete step), later
 * steps are {@see OverviewCardState::Locked}, completed steps are Ready. The
 * optional modules (tasks, snippets, skills, tools) are Ready when they have
 * active entries and {@see OverviewCardState::EmptyState} when they do not —
 * they are never locked.
 *
 * Pure and deterministic given the injected repositories/services, so the
 * state matrix is fully unit-testable.
 */
final class OverviewReadinessService
{
    /**
     * How far back "has this instance ever been used?" looks when deciding
     * whether the Try-it card is still the recommended Next step.
     */
    private const USAGE_LOOKBACK_DAYS = 90;

    public function __construct(
        private readonly ProviderRepository $providerRepository,
        private readonly ModelRepository $modelRepository,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly TaskRepository $taskRepository,
        private readonly PromptSnippetRepository $promptSnippetRepository,
        private readonly SkillRepository $skillRepository,
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolAvailabilityServiceInterface $toolAvailability,
        private readonly UsageAnalyticsServiceInterface $analytics,
    ) {}

    /**
     * Build the setup status for every overview card.
     *
     * @return array{
     *     providers: OverviewCardStatus,
     *     models: OverviewCardStatus,
     *     configurations: OverviewCardStatus,
     *     tryit: OverviewCardStatus,
     *     tasks: OverviewCardStatus,
     *     snippets: OverviewCardStatus,
     *     skills: OverviewCardStatus,
     *     tools: OverviewCardStatus,
     * }
     */
    public function buildStatuses(): array
    {
        $providers      = $this->providerRepository->countActive();
        $models         = $this->modelRepository->countActive();
        $configurations = $this->configurationRepository->countActive();
        $hasDefault     = $this->configurationRepository->findDefault() !== null;

        // Critical path: the first incomplete step is Next, later steps Locked.
        $providersState      = $providers > 0 ? OverviewCardState::Ready : OverviewCardState::Next;
        $modelsState         = $this->criticalStep($providers > 0, $models > 0);
        $configurationsState = $this->criticalStep($providers > 0 && $models > 0, $configurations > 0);

        $tryitState = $this->tryitState($providers > 0 && $models > 0 && $configurations > 0);

        // Optional modules — Ready when they hold active entries, else Empty.
        $tasks    = $this->taskRepository->countActive();
        $snippets = $this->promptSnippetRepository->countActive();

        $skillsTotal   = $this->skillRepository->countAll();
        $skillsEnabled = $this->skillRepository->countEnabled();

        $toolsTotal   = count($this->toolRegistry->names());
        $toolsEnabled = count($this->toolAvailability->enabledNames());

        return [
            'providers'      => new OverviewCardStatus($providersState, $providers),
            'models'         => new OverviewCardStatus($modelsState, $models),
            'configurations' => new OverviewCardStatus($configurationsState, $configurations, hasDefault: $hasDefault),
            'tryit'          => new OverviewCardStatus($tryitState),
            'tasks'          => new OverviewCardStatus($this->optionalState($tasks > 0), $tasks),
            'snippets'       => new OverviewCardStatus($this->optionalState($snippets > 0), $snippets),
            'skills'         => new OverviewCardStatus($this->optionalState($skillsTotal > 0), $skillsTotal, $skillsEnabled),
            'tools'          => new OverviewCardStatus($this->optionalState($toolsEnabled > 0), $toolsTotal, $toolsEnabled),
        ];
    }

    /**
     * A critical-path step: Locked until its prerequisite is met, then Ready
     * when it has entries, otherwise Next.
     */
    private function criticalStep(bool $prerequisiteMet, bool $hasEntries): OverviewCardState
    {
        if (!$prerequisiteMet) {
            return OverviewCardState::Locked;
        }

        return $hasEntries ? OverviewCardState::Ready : OverviewCardState::Next;
    }

    /**
     * The Try-it card: Locked until setup is complete, then the recommended
     * Next step until the instance has actually been used, after which it is
     * Neutral (no longer nagging).
     */
    private function tryitState(bool $setupComplete): OverviewCardState
    {
        if (!$setupComplete) {
            return OverviewCardState::Locked;
        }

        return $this->hasUsage() ? OverviewCardState::Neutral : OverviewCardState::Next;
    }

    private function optionalState(bool $hasEntries): OverviewCardState
    {
        return $hasEntries ? OverviewCardState::Ready : OverviewCardState::EmptyState;
    }

    /**
     * Whether any request has been recorded in the recent lookback window.
     */
    private function hasUsage(): bool
    {
        $to   = new DateTimeImmutable();
        $from = $to->modify('-' . self::USAGE_LOOKBACK_DAYS . ' days');

        return $this->analytics->getKpiTotals($from, $to)['requests'] > 0;
    }
}
