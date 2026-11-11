<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Command;

use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `nrllm:a2a:get <agent> <task-id>`: print a remote task (spec 051 A2A-C9).
 *
 * Only a task this installation's worker user created through
 * `nrllm:a2a:send` can be read (A2A-C7).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsCommand(
    name: 'nrllm:a2a:get',
    description: 'Print a task of a registered A2A remote agent that was sent from here.',
)]
final class A2aGetCommand extends Command
{
    public function __construct(
        private readonly A2aCommandContext $context,
        private readonly A2aClient $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('agent', InputArgument::REQUIRED, 'The identifier or uid of the remote agent record.');
        $this->addArgument('task-id', InputArgument::REQUIRED, 'The task id nrllm:a2a:send printed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $reference = $input->getArgument('agent');
        $taskId    = $input->getArgument('task-id');
        if (!is_string($reference) || !is_string($taskId) || $taskId === '') {
            $io->error('Name an agent and a task id.');

            return Command::INVALID;
        }

        try {
            $agent = $this->context->agent($reference);
            $owner = $this->context->owner();
            $task  = $this->context->asWorker(fn (): A2aTask => $this->client->getTask($agent, $owner, $taskId));
        } catch (NrLlmExceptionInterface $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        A2aTaskPrinter::print($io, $task);

        return Command::SUCCESS;
    }
}
