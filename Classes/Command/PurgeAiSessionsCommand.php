<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\Enum\PrivacyDataCategory;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Service\Session\AiSessionRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes conversation sessions (and their messages) that have been inactive
 * longer than the retention window (ADR-083).
 *
 * Session messages carry the conversation content (prompts and replies), so a
 * retention purge is the GDPR counterpart to the telemetry/privacy purges. The
 * window comes from the central privacy policy
 * ({@see PrivacyDataCategory::CONVERSATION}) — this command is the single-table
 * variant of `nrllm:privacy:purge`, not a second policy.
 */
#[AsCommand(
    name: 'nrllm:session:purge',
    description: 'Delete conversation sessions inactive for longer than the configured retention window.',
)]
final class PurgeAiSessionsCommand extends Command
{
    public function __construct(
        private readonly AiSessionRepositoryInterface $repository,
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
            'Delete sessions with no activity for this many days. Defaults to the configured conversation retention window.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $daysOption = $input->getOption('days');
        $days       = $daysOption === null
            ? $this->privacyPolicy->retentionDaysFor(PrivacyDataCategory::CONVERSATION)
            : (is_numeric($daysOption) ? (int)$daysOption : 0);

        if ($days < 1) {
            $io->error('The --days option must be a positive integer.');

            return Command::INVALID;
        }

        $cutoff  = time() - ($days * 86400);
        $deleted = $this->repository->purgeInactiveSince($cutoff);

        $io->success(sprintf('Deleted %d conversation session(s) inactive for %d+ day(s).', $deleted, $days));

        return Command::SUCCESS;
    }
}
