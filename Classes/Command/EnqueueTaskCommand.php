<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Agent\TaskRunRequestFactory;
use Netresearch\NrLlm\Service\Task\TaskInputResolverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Put one AI Task on the queue (ADR-234).
 *
 * The producer half: it stores a run and returns, `nrllm:agent:work` or a
 * Messenger consumer executes it. Schedulable, so a task can recur. The run acts
 * as a service account holding only `configuration:use`, because a task calls no
 * tool and writes nothing; it is not the `_cli_` user, whose admin flag would
 * say nothing about who asked.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsCommand(
    name: 'nrllm:task:enqueue',
    description: 'Queue one AI Task for background execution.',
)]
final class EnqueueTaskCommand extends Command
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly TaskInputResolverInterface $inputResolver,
        private readonly TaskRunRequestFactory $requestFactory,
        private readonly AgentRuntimeInterface $runtime,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('task', InputArgument::REQUIRED, 'The identifier of the AI Task.');
        $this->addOption(
            'input',
            'i',
            InputOption::VALUE_REQUIRED,
            'The input of a task with manual input. Other tasks read their source when they are queued.',
            '',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifier = $input->getArgument('task');
        $task       = is_string($identifier) ? $this->taskRepository->findOneByIdentifier($identifier) : null;
        if (!$task instanceof Task || !$task->isActive()) {
            $io->error('No active AI Task has this identifier.');

            return Command::INVALID;
        }

        $inputOption = $input->getOption('input');
        $text        = is_string($inputOption) ? $inputOption : '';
        if ($task->requiresManualInput() && trim($text) === '') {
            $io->error('This AI Task takes manual input: pass it with --input.');

            return Command::INVALID;
        }

        if (!$task->requiresManualInput()) {
            $text = $this->inputResolver->resolve($task);
        }

        $request = $this->requestFactory->build(
            $task,
            $text,
            AiActorContext::serviceAccount('cli:nrllm:task:enqueue', [ServiceAccountScope::CONFIGURATION_USE]),
        );
        if (!$request instanceof AgentRunRequest) {
            $io->error('Neither the AI Task nor the installation names a configuration to run it on.');

            return Command::FAILURE;
        }

        try {
            $uuid = $this->runtime->defer($request);
        } catch (RunEnqueueFailedException) {
            $io->error('The run could not be stored.');

            return Command::FAILURE;
        }

        $io->success(sprintf('Queued run %s.', $uuid));

        return Command::SUCCESS;
    }
}
