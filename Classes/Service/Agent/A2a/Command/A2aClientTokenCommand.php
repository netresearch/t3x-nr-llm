<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Command;

use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClientRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Issue the bearer token of an A2A client record (spec 051 A2A-SEC1,
 * ADR-280).
 *
 * The token is printed once and stored nowhere in clear: the record keeps its
 * public prefix and its sha256. Running the command again rotates the token
 * on the same record; the old one stops working at once, and the client's
 * tasks stay its own.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsCommand(
    name: 'nrllm:a2a:client:token',
    description: 'Issue (or rotate) the bearer token of an A2A client record and print it once.',
)]
final class A2aClientTokenCommand extends Command
{
    public function __construct(
        private readonly A2aInboundClientRepository $clients,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client', InputArgument::REQUIRED, 'The uid of the tx_nrllm_a2a_client record.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = $input->getArgument('client');
        if (!is_string($uid) || !ctype_digit($uid) || (int) $uid < 1) {
            $output->writeln('<error>The client must be a record uid.</error>');

            return Command::INVALID;
        }

        // The token of an editor's own record is issued by its owner (ADR-294):
        // an operator who could issue it would hold a credential that acts as
        // that editor, with no owner asking for it.
        if ($this->clients->findRawByUid((int) $uid)?->selfService === true) {
            $output->writeln("<error>This client record belongs to an editor's own account; its owner issues the token in the backend module.</error>");

            return Command::FAILURE;
        }

        try {
            $token = $this->clients->issueToken((int) $uid);
        } catch (A2aException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln($token, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
