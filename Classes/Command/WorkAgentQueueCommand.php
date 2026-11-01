<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Work through the queued agent runs from cron or the scheduler (ADR-234).
 *
 * It reads the same QUEUED rows a Messenger consumer is woken for and claims each
 * through {@see AgentRuntimeInterface::runQueued()}, so the two can run side by
 * side: exactly one wins a row. It exists for the installation that runs a
 * scheduler and no consumer, which is the default.
 *
 * One tick is bounded twice, by run count and by wall clock, so an hourly task
 * cannot overlap itself by much and a long queue is worked off over several
 * ticks. A failed run is that run's outcome and is listed in the background
 * history; only a queue that cannot be read fails the command.
 *
 * Once the budget refuses a user's run, the rest of that user's runs stay queued
 * for a later tick and other users' runs continue (ADR-162).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsCommand(
    name: 'nrllm:agent:work',
    description: 'Execute queued agent runs, oldest first, within a run count and a time limit.',
)]
final class WorkAgentQueueCommand extends Command
{
    private const DEFAULT_LIMIT = 20;

    private const DEFAULT_TIME_LIMIT = 240;

    public function __construct(
        private readonly AgentRunPersister $persister,
        private readonly AgentRuntimeInterface $runtime,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of runs to execute.', (string) self::DEFAULT_LIMIT);
        $this->addOption('time-limit', 't', InputOption::VALUE_REQUIRED, 'Seconds after which no further run starts. A run that has started finishes.', (string) self::DEFAULT_TIME_LIMIT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit     = $this->positive($input->getOption('limit'));
        $timeLimit = $this->positive($input->getOption('time-limit'));
        if ($limit === null || $timeLimit === null) {
            $io->error('--limit and --time-limit must be positive integers.');

            return Command::INVALID;
        }

        $runs = $this->persister->findQueuedRuns($limit);
        if ($runs === null) {
            $io->error('The queue could not be read.');

            return Command::FAILURE;
        }

        $deadline = time() + $timeLimit;
        $tally    = ['executed' => 0, 'taken' => 0, 'held' => 0, 'left' => 0];
        $outcomes = [];
        $refused  = [];

        foreach ($runs as $run) {
            if (time() >= $deadline) {
                ++$tally['left'];
                continue;
            }

            if (isset($refused[$run->beUser])) {
                ++$tally['held'];
                continue;
            }

            $result = $this->runtime->runQueued($run->uuid);
            if (!$result instanceof AgentRunResult) {
                ++$tally['taken'];
                continue;
            }

            ++$tally['executed'];
            $outcomes[$result->outcome->value] = ($outcomes[$result->outcome->value] ?? 0) + 1;

            if ($result->deniedByBudget()) {
                $refused[$run->beUser] = true;
            }
        }

        $io->writeln(sprintf(
            'Executed %d, claimed by another worker %d, held back after a budget refusal %d, left for the next tick %d.',
            $tally['executed'],
            $tally['taken'],
            $tally['held'],
            $tally['left'],
        ));
        foreach ($outcomes as $outcome => $count) {
            $io->writeln(sprintf('  %s: %d', $outcome, $count));
        }

        return Command::SUCCESS;
    }

    private function positive(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
