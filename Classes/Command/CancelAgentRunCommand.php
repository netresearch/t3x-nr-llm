<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cancels an agent run that is stuck or no longer wanted (ADR-092).
 *
 * A run can be left non-terminal for reasons outside its own control: the PHP
 * process died mid-loop, a client disconnected before the stream settled, or an
 * approval nobody will ever give is holding the run open. Such a run keeps its
 * resumable transcript and shows up as pending forever. This moves it to
 * CANCELLED — a real state, distinct from FAILED, because nothing went wrong;
 * somebody stopped it.
 *
 * The transition is guarded in the repository: an already-finished run is not
 * touched, and two concurrent cancels cannot both succeed.
 */
#[AsCommand(
    name: 'nrllm:agent:cancel',
    description: 'Cancel an agent run that is still queued, running or awaiting a decision.',
)]
final class CancelAgentRunCommand extends Command
{
    public function __construct(
        // The runtime is the single consumer surface for run lifecycle
        // operations (ADR-101); cancel delegates to the same guarded
        // transition it always did.
        private readonly AgentRuntimeInterface $agentRuntime,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, 'The run uuid, as shown in the playground and the agent-run table.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $uuid = $input->getArgument('uuid');

        if (!is_string($uuid) || $uuid === '') {
            $io->error('A run uuid is required.');

            return Command::INVALID;
        }

        if (!$this->agentRuntime->cancel(AiActorContext::serviceAccount('cli:nrllm:agent:cancel'), $uuid)) {
            $io->warning(sprintf('Run "%s" was not cancelled: it is unknown or already finished.', $uuid));

            return Command::FAILURE;
        }

        $io->success(sprintf('Run "%s" was cancelled.', $uuid));

        return Command::SUCCESS;
    }
}
