<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Command;

use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aSendOutcome;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aSendMessageConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `nrllm:a2a:send <agent> <text>`: send one message, print the task id, exit
 * (spec 051 A2A-C9).
 *
 * The message goes out with `returnImmediately` true, so the command does not
 * wait for the remote's work; `nrllm:a2a:get` reads the task later, from the
 * handle the journal keeps. An unknown outcome is printed with its message id
 * and is never sent again by this command.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsCommand(
    name: 'nrllm:a2a:send',
    description: 'Send one message to a registered A2A remote agent, print the task id and exit.',
)]
final class A2aSendCommand extends Command
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
        $this->addArgument('text', InputArgument::REQUIRED, 'The text of the message.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $reference = $input->getArgument('agent');
        $text      = $input->getArgument('text');
        if (!is_string($reference) || !is_string($text) || trim($text) === '') {
            $io->error('Name an agent and a non-empty text.');

            return Command::INVALID;
        }

        try {
            $agent   = $this->context->agent($reference);
            $owner   = $this->context->owner();
            $message = A2aMessage::userText(A2aClient::newMessageId(), $text);
            $outcome = $this->context->asWorker(
                fn (): A2aSendOutcome => $this->client->sendMessage($agent, $owner, $message, A2aSendMessageConfiguration::returningImmediately()),
            );
        } catch (NrLlmExceptionInterface $e) {
            // May quote the remote (an error message, the bindings a card offers).
            $io->error(A2aTaskPrinter::plain($e->getMessage()));

            return Command::FAILURE;
        }

        return $this->report($io, $outcome);
    }

    private function report(SymfonyStyle $io, A2aSendOutcome $outcome): int
    {
        $intent = $outcome->intent;
        switch ($intent->state) {
            case A2aSendState::ACCEPTED:
                $io->writeln(A2aTaskPrinter::plain($intent->remoteTaskId));
                $io->writeln(sprintf('<comment>Task state %s. Read it later with nrllm:a2a:get.</comment>', A2aTaskPrinter::plain($intent->lastRemoteState)));

                return Command::SUCCESS;

            case A2aSendState::ANSWERED:
                $io->writeln('The agent answered with a message and created no task:');
                $io->writeln(A2aTaskPrinter::plain($outcome->result?->message?->text() ?? ''));

                return Command::SUCCESS;

            case A2aSendState::REFUSED:
                $io->error(sprintf(
                    'Refused%s: %s %s (message id %s)',
                    $intent->errorCode !== null ? ' with JSON-RPC ' . $intent->errorCode : '',
                    A2aTaskPrinter::plain($intent->errorReason),
                    A2aTaskPrinter::plain($intent->errorMessage),
                    $intent->messageId,
                ));

                return Command::FAILURE;

            case A2aSendState::OUTCOME_UNKNOWN:
                $io->warning(sprintf(
                    'Outcome unknown (%s): the message may have reached the agent. It is not sent again; look the task up before resending. Message id %s.',
                    A2aTaskPrinter::plain($intent->errorReason),
                    $intent->messageId,
                ));

                return Command::FAILURE;

            default:
                $io->error(sprintf('The intent %s has no outcome.', $intent->messageId));

                return Command::FAILURE;
        }
    }
}
