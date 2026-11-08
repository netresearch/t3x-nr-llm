<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
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
 * A run the budget would refuse stays queued for a later tick, and other runs
 * continue (ADR-162).
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
        private readonly BudgetServiceInterface $budget,
        private readonly LlmConfigurationRepository $configurationRepository,
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

        while ($runs !== []) {
            foreach ($runs as $run) {
                if ($tally['executed'] >= $limit || time() >= $deadline) {
                    ++$tally['left'];
                    continue;
                }

                if ($this->budgetRefuses($run, $refused)) {
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
            }

            $runs = $tally['executed'] < $limit && time() < $deadline ? $this->persister->findQueuedRuns(
                $limit,
                $tally['held'] + $tally['taken'],
            ) : [];
            if ($runs === null) {
                $io->error(
                    sprintf(
                        'The queue could not be read after executing %d runs; %d were held by the budget.',
                        $tally['executed'],
                        $tally['held'],
                    ),
                );

                return Command::FAILURE;
            }
        }

        $io->writeln(sprintf(
            'Executed %d, claimed by another worker %d, held back by the budget %d, left for the next tick %d.',
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

    /**
     * Whether this run would only meet an empty budget. Asked before the claim,
     * because a refusal after it costs the run: a refused tool-free run is dead-
     * lettered and a refused tool run settles as finished (ADR-162). A refusal
     * holds for the tick; an allowance is asked again, because runs spend.
     *
     * @param array<string, true> $refused who the budget refused in this tick
     */
    private function budgetRefuses(AgentRun $run, array &$refused): bool
    {
        $key = $run->beUser . ':' . $run->configurationUid;
        if (isset($refused[$key])) {
            return true;
        }

        $configuration = $this->configurationRepository->findByUid($run->configurationUid);
        if ($this->budget->check($run->beUser, 0.0, $configuration)->allowed) {
            return false;
        }

        $refused[$key] = true;

        return true;
    }

    private function positive(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
