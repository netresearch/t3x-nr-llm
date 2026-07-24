<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\Enum\PrivacyDataCategory;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prunes old rows from the governance-event log (tx_nrllm_governance_event).
 *
 * The governance log appends one row per denied or gated decision, so the table
 * grows with agent activity. This command bounds that growth by deleting rows
 * older than the retention window taken from the central privacy policy
 * ({@see PrivacyDataCategory::GOVERNANCE}). Run it from the scheduler or cron —
 * or schedule `nrllm:privacy:purge`, which covers every table at once.
 */
#[AsCommand(
    name: 'nrllm:governance:purge',
    description: 'Delete governance-event rows older than the configured retention window.',
)]
final class PurgeGovernanceCommand extends Command
{
    public function __construct(
        private readonly GovernanceEventRepositoryInterface $repository,
        private readonly PrivacyPolicyInterface $privacyPolicy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_REQUIRED,
            'Delete governance-event rows older than this many days. Defaults to the configured governance retention window.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $daysOption = $input->getOption('days');
        $days       = $daysOption === null
            ? $this->privacyPolicy->retentionDaysFor(PrivacyDataCategory::GOVERNANCE)
            : (is_numeric($daysOption) ? (int)$daysOption : 0);

        if ($days < 1) {
            $io->error('The --days option must be a positive integer.');

            return Command::INVALID;
        }

        $cutoff  = time() - ($days * 86400);
        $deleted = $this->repository->purgeOlderThan($cutoff);

        $io->success(sprintf('Deleted %d governance-event row(s) older than %d day(s).', $deleted, $days));

        return Command::SUCCESS;
    }
}
