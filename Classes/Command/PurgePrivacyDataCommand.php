<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\Enum\PrivacyDataCategory;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Service\Session\AiSessionRepositoryInterface;
use Netresearch\NrLlm\Service\Skill\SkillAuditRepositoryInterface;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enforces data retention across every content-bearing table (ADR-064).
 *
 * This is the single retention entry point: each {@see PrivacyDataCategory}
 * resolves its own window through the central privacy policy, so conversation
 * transcripts can expire in a week while telemetry is kept for a quarter. A
 * `--days` override applies uniformly to all categories.
 *
 * Runs awaiting a human decision are deliberately reaped on the separate,
 * longer APPROVAL window rather than together with finished runs — purging a
 * suspended run by age alone would destroy work in flight.
 *
 * The per-table commands (`nrllm:telemetry:purge`, `nrllm:session:purge`) stay
 * for operators who want to schedule a single table; they read the same policy.
 * TYPO3 exposes all of them in the scheduler's console-command task.
 */
#[AsCommand(
    name: 'nrllm:privacy:purge',
    description: 'Delete content-bearing rows (eval results, skill audit, telemetry, conversations, agent runs, governance events) past their retention window.',
)]
final class PurgePrivacyDataCommand extends Command
{
    public function __construct(
        private readonly PrivacyPolicyInterface $privacyPolicy,
        private readonly EvaluationResultRepositoryInterface $evaluationResults,
        private readonly SkillAuditRepositoryInterface $skillAudit,
        private readonly TelemetryRepositoryInterface $telemetry,
        private readonly AiSessionRepositoryInterface $sessions,
        private readonly AgentRunRepositoryInterface $agentRuns,
        private readonly GovernanceEventRepositoryInterface $governanceEvents,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_REQUIRED,
            'Delete rows older than this many days, overriding every per-category window. '
            . 'Defaults to each category\'s own configured retention window.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $override = $this->resolveOverride($input);
        if ($override !== null && $override < 1) {
            $io->error('The --days option must be a positive integer.');

            return Command::INVALID;
        }

        $now    = time();
        $window = fn(PrivacyDataCategory $category): array => $this->window($category, $override, $now);

        $purged = [];

        [$days, $cutoff] = $window(PrivacyDataCategory::EVALUATION);
        $purged[]        = sprintf('Evaluation results (%d d): %d', $days, $this->evaluationResults->purgeOlderThan($cutoff));

        [$days, $cutoff] = $window(PrivacyDataCategory::SKILL_AUDIT);
        $purged[]        = sprintf('Skill audit (%d d): %d', $days, $this->skillAudit->purgeOlderThan($cutoff));

        [$days, $cutoff] = $window(PrivacyDataCategory::TELEMETRY);
        $purged[]        = sprintf('Telemetry (%d d): %d', $days, $this->telemetry->purgeOlderThan($cutoff));

        [$days, $cutoff] = $window(PrivacyDataCategory::CONVERSATION);
        $purged[]        = sprintf('Conversation sessions (%d d): %d', $days, $this->sessions->purgeInactiveSince($cutoff));

        [$days, $cutoff] = $window(PrivacyDataCategory::AGENT_RUN);
        $purged[]        = sprintf('Finished agent runs (%d d): %d', $days, $this->agentRuns->purgeOlderThan($cutoff));

        [$days, $cutoff] = $window(PrivacyDataCategory::APPROVAL);
        $purged[]        = sprintf('Unfinished agent runs (%d d): %d', $days, $this->agentRuns->purgeUnfinishedOlderThan($cutoff));

        [$days, $cutoff] = $window(PrivacyDataCategory::GOVERNANCE);
        $purged[]        = sprintf('Governance events (%d d): %d', $days, $this->governanceEvents->purgeOlderThan($cutoff));

        $io->success('Retention enforced across all content-bearing tables.');
        $io->listing($purged);

        return Command::SUCCESS;
    }

    /**
     * The retention window for one category: the number of days applied and the
     * resulting cutoff timestamp. A `--days` override wins over the configured
     * per-category window.
     *
     * @return array{int, int} [days, cutoff timestamp]
     */
    private function window(PrivacyDataCategory $category, ?int $override, int $now): array
    {
        $days = $override ?? $this->privacyPolicy->retentionDaysFor($category);

        return [$days, $now - ($days * 86400)];
    }

    /**
     * The `--days` override, or null when the per-category windows apply. A
     * supplied but non-numeric value yields 0 so {@see execute()} rejects it.
     */
    private function resolveOverride(InputInterface $input): ?int
    {
        $daysOption = $input->getOption('days');
        if ($daysOption === null) {
            return null;
        }

        return is_numeric($daysOption) ? (int)$daysOption : 0;
    }
}
