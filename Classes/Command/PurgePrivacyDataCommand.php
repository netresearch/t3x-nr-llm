<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Service\Skill\SkillAuditRepositoryInterface;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enforces data retention across the per-request log tables (ADR-064).
 *
 * The central privacy model governs how long the extension keeps the content
 * and metadata it logs. This command deletes rows older than the retention
 * window from all three log tables — evaluation results, the skill audit trail
 * and telemetry — reporting the count purged per table. The window defaults to
 * the configured `privacy.retentionDays` and is overridable with `--days`. Run
 * it from the scheduler or cron.
 *
 * The existing `nrllm:telemetry:purge` command stays as-is for backward
 * compatibility; this command generalises it across every log table.
 */
#[AsCommand(
    name: 'nrllm:privacy:purge',
    description: 'Delete per-request log rows (eval results, skill audit, telemetry) older than the retention window.',
)]
final class PurgePrivacyDataCommand extends Command
{
    public function __construct(
        private readonly PrivacyPolicyInterface $privacyPolicy,
        private readonly EvaluationResultRepositoryInterface $evaluationResults,
        private readonly SkillAuditRepositoryInterface $skillAudit,
        private readonly TelemetryRepositoryInterface $telemetry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_REQUIRED,
            'Delete rows older than this many days. Defaults to the configured privacy retention window.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = $this->resolveDays($input);
        if ($days < 1) {
            $io->error('The --days option must be a positive integer.');

            return Command::INVALID;
        }

        $cutoff = time() - ($days * 86400);

        $evalDeleted      = $this->evaluationResults->purgeOlderThan($cutoff);
        $auditDeleted     = $this->skillAudit->purgeOlderThan($cutoff);
        $telemetryDeleted = $this->telemetry->purgeOlderThan($cutoff);

        $io->success(sprintf('Purged log rows older than %d day(s).', $days));
        $io->listing([
            sprintf('Evaluation results: %d', $evalDeleted),
            sprintf('Skill audit: %d', $auditDeleted),
            sprintf('Telemetry: %d', $telemetryDeleted),
        ]);

        return Command::SUCCESS;
    }

    /**
     * The retention window in days: the `--days` option when given, else the
     * configured privacy retention default. A supplied but non-numeric value
     * yields 0 so {@see execute()} rejects it, matching PurgeTelemetryCommand.
     */
    private function resolveDays(InputInterface $input): int
    {
        $daysOption = $input->getOption('days');
        if ($daysOption === null) {
            return $this->privacyPolicy->retentionDays();
        }

        return is_numeric($daysOption) ? (int)$daysOption : 0;
    }
}
