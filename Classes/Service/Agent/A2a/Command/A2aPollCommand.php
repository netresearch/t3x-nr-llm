<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Command;

use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDependencyPoller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One tick of the A2A polling coordinator (spec 051 A2A-R10). Schedule it
 * like `nrllm:agent:work`, at least as often as the shortest minimum poll
 * interval of the remote agent records.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsCommand(
    name: 'nrllm:a2a:poll',
    description: 'Look at the remote A2A tasks waiting runs depend on, and resume the runs whose task settled or whose deadline passed.',
)]
final class A2aPollCommand extends Command
{
    public function __construct(
        private readonly A2aDependencyPoller $poller,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many waiting runs one tick looks at.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        if (!is_string($limit) || !ctype_digit($limit) || (int) $limit < 1) {
            $output->writeln('<error>--limit must be a positive integer.</error>');

            return Command::INVALID;
        }

        $report = $this->poller->tick(time(), (int) $limit);
        $output->writeln(sprintf(
            'Resumed %d, timed out %d, still waiting %d, not due %d, failed %d.',
            $report->resumed,
            $report->timedOut,
            $report->waiting,
            $report->notDue,
            $report->failed,
        ));

        return Command::SUCCESS;
    }
}
