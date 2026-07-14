<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prunes old rows from the telemetry log (ADR-058).
 *
 * Telemetry appends one row per provider pipeline run, so the table grows with
 * traffic. This command bounds that growth by deleting rows older than a
 * retention window (default 30 days). Run it from the scheduler or cron.
 */
#[AsCommand(
    name: 'nrllm:telemetry:purge',
    description: 'Delete provider telemetry rows older than the retention window (default 30 days).',
)]
final class PurgeTelemetryCommand extends Command
{
    private const DEFAULT_DAYS = 30;

    public function __construct(
        private readonly TelemetryRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_REQUIRED,
            'Delete telemetry rows older than this many days.',
            self::DEFAULT_DAYS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $daysOption = $input->getOption('days');
        $days       = is_numeric($daysOption) ? (int)$daysOption : 0;

        if ($days < 1) {
            $io->error('The --days option must be a positive integer.');

            return Command::INVALID;
        }

        $cutoff  = time() - ($days * 86400);
        $deleted = $this->repository->purgeOlderThan($cutoff);

        $io->success(sprintf('Deleted %d telemetry row(s) older than %d day(s).', $deleted, $days));

        return Command::SUCCESS;
    }
}
