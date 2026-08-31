<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Service\Outcome\ObservedOutcomeDeriver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Decides what became of the records this extension wrote (ADR-185).
 *
 * The observed half of the per-call outcome signal. It looks at writes whose
 * observation window has closed, asks `sys_history` what happened to those
 * records afterwards, and records one outcome each. Out of request by design:
 * the question is what an editor did over days, and nothing on a send path can
 * answer it.
 *
 * Run it from the scheduler. A run that finds nothing is the normal case, since
 * most writes are still inside their window.
 */
#[AsCommand(
    name: 'nrllm:outcome:derive',
    description: 'Record what became of the records written by AI runs whose observation window has closed.',
)]
/**
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class DeriveObservedOutcomesCommand extends Command
{
    public function __construct(
        private readonly ObservedOutcomeDeriver $deriver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_REQUIRED,
            sprintf(
                'How long a record is watched before it is judged. Defaults to %d; anything below %d is raised to it, because a window of zero reports every write as untouched.',
                ObservedOutcomeDeriver::DEFAULT_WINDOW_DAYS,
                ObservedOutcomeDeriver::MIN_WINDOW_DAYS,
            ),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = $input->getOption('days');
        $window = is_numeric($days) ? (int)$days : ObservedOutcomeDeriver::DEFAULT_WINDOW_DAYS;

        $counts = $this->deriver->derive($window);

        if ($counts === []) {
            $io->success('No write has left its observation window since the last run.');

            return Command::SUCCESS;
        }

        // Printed by case rather than as a total: "42 outcomes" says nothing an
        // operator can act on, and the interesting number here is how often the
        // answer was UNKNOWN.
        $rows = [];
        foreach ($counts as $outcome => $count) {
            $rows[] = [$outcome, $count];
        }

        $io->table(['Outcome', 'Writes'], $rows);

        return Command::SUCCESS;
    }
}
