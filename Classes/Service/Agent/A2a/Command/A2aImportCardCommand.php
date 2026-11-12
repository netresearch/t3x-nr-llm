<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Command;

use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aCardImporter;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aCardImportResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `nrllm:a2a:import <agent>`: import or re-import the agent card of a remote
 * agent record (spec 051 A2A-C2). Re-import is explicit, and this is it.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsCommand(
    name: 'nrllm:a2a:import',
    description: 'Import the agent card of a registered A2A remote agent.',
)]
final class A2aImportCardCommand extends Command
{
    public function __construct(
        private readonly A2aCommandContext $context,
        private readonly A2aCardImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('agent', InputArgument::REQUIRED, 'The identifier or uid of the remote agent record.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $reference = $input->getArgument('agent');
        if (!is_string($reference)) {
            return Command::INVALID;
        }

        try {
            $agent  = $this->context->agent($reference);
            $result = $this->context->asWorker(fn (): A2aCardImportResult => $this->importer->import($agent));
        } catch (NrLlmExceptionInterface $e) {
            // May quote the remote (an error message, the bindings a card offers).
            $io->error(A2aTaskPrinter::plain($e->getMessage()));

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Agent' => A2aTaskPrinter::line($result->card->name . ' ' . $result->card->version)],
            ['Interface' => A2aTaskPrinter::line($result->interface->url)],
            ['Tenant' => $result->interface->tenant !== '' ? A2aTaskPrinter::line($result->interface->tenant) : '-'],
            ['Capabilities' => $result->card->capabilities->fingerprint()],
        );
        if ($result->confirmationRequired) {
            $io->warning('The interface or the capabilities changed. The client refuses this agent until the record is saved again in the backend.');
        } else {
            $io->success('Card imported.');
        }

        return Command::SUCCESS;
    }
}
