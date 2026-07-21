<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Reclaims queued agent runs abandoned by a dead worker (ADR-104).
 *
 * A worker that claims a queued run stamps a lease and renews it at every step
 * boundary while it runs (the heartbeat). If the worker dies — the PHP process
 * is killed, the container is recycled, the machine reboots — the run stays
 * RUNNING with a lease nobody renews. This command finds those runs (lease
 * expired) and either puts them back on the queue for another worker or, once
 * the requeue budget is spent, dead-letters them so they stop occupying the
 * RUNNING set forever.
 *
 * Interactive run()/approve() runs never take a lease, so the reaper never
 * touches them; a foreground run abandoned by a dying client is reaped by the
 * separate age-based retention path ({@see PurgePrivacyDataCommand}).
 *
 * Every mutation is staleness-guarded in the repository: a run whose lease was
 * renewed between this command's read and its write is skipped, so a worker
 * that was merely slow (not dead) is never disturbed. Schedulable from
 * EXT:scheduler or cron.
 */
#[AsCommand(
    name: 'nrllm:agent:reap',
    description: 'Reclaim or dead-letter queued agent runs whose worker lease has expired.',
)]
final class ReapStaleAgentRunsCommand extends Command
{
    /** Default upper bound on runs handled per invocation, so one tick is bounded. */
    private const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly AgentRunPersister $persister,
        private readonly ?MessageBusInterface $messageBus = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_REQUIRED,
            'Maximum number of stale runs to handle in this run.',
            (string)self::DEFAULT_LIMIT,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->messageBus === null) {
            $io->error('No message bus is available; queued runs cannot be reclaimed.');

            return Command::FAILURE;
        }

        $limitOption = $input->getOption('limit');
        $limit       = is_numeric($limitOption) ? (int)$limitOption : 0;
        if ($limit < 1) {
            $io->error('The --limit option must be a positive integer.');

            return Command::INVALID;
        }

        $now        = time();
        $staleRuns  = $this->persister->findStaleRunning($now, $limit);
        $requeued   = 0;
        $deadLetter = 0;
        $skipped    = 0;

        foreach ($staleRuns as $run) {
            // Budget spent -> dead-letter; otherwise reclaim onto the queue. Both
            // writes re-check staleness in the repository, so a heartbeat renewal
            // between findStaleRunning() and here wins and the run is skipped.
            if ($run->requeueCount >= AgentRuntime::MAX_REQUEUES) {
                if ($this->persister->settleDeadLetteredStale($run, $now, AgentRunTerminationReason::RETRIES_EXHAUSTED)) {
                    ++$deadLetter;
                } else {
                    ++$skipped;
                }

                continue;
            }

            if (!$this->persister->requeueStale($run, $now)) {
                ++$skipped;

                continue;
            }

            // The row is QUEUED again: wake a worker. A dispatch failure leaves a
            // QUEUED row a later tick (or a still-live consumer) picks up, so it
            // is counted as reclaimed, logged, and not treated as fatal.
            try {
                $this->messageBus->dispatch(new AgentRunQueuedMessage($run->uuid));
            } catch (Throwable $exception) {
                $io->warning(sprintf('Run "%s" was reclaimed but its wake-up could not be dispatched: %s', $run->uuid, $exception->getMessage()));
            }
            ++$requeued;
        }

        $io->success(sprintf(
            'Reaped %d stale run(s): %d requeued, %d dead-lettered, %d skipped (renewed or already moved on).',
            count($staleRuns),
            $requeued,
            $deadLetter,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
