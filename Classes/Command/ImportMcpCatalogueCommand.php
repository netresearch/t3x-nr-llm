<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\ValueObject\McpImportReport;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpImportService;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports an MCP server's advertised catalogue from the CLI.
 *
 * A server record can be seeded, but until this existed its catalogue could
 * only arrive by a person opening the MCP Servers module and pressing **Import
 * catalogue**. An instance rebuilt by a deploy therefore had one manual step
 * after every fresh install, and no tools for that server until somebody
 * clicked.
 *
 * This is the module's button, not a second path: it calls the same
 * {@see McpImportService::import()} and adds nothing. Every refusal reason, the
 * SSRF gate, the operation budget (ADR-170) and the catalogue reconciliation
 * live in that service, which is why this class holds no policy of its own.
 *
 * It lives in `Classes/Command/` with every other command but belongs to the
 * tool module, and `ModuleSeamTest` names it for that reason: in a package
 * split it moves to nr_llm_tools with the MCP code it drives (ADR-090).
 *
 * Servers are addressed by identifier rather than uid, because a uid is not
 * knowable to whoever writes the deploy script and an identifier is what the
 * seed sets. Identifiers are not unique in the table — soft-deleted rows keep
 * theirs, which is why the service refuses an import when two ENABLED servers
 * share one rather than relying on a database constraint. The same ambiguity
 * can reach this command, so it is reported here rather than resolved by
 * picking a row.
 */
#[AsCommand(
    name: 'nrllm:mcp:import',
    description: "Import an MCP server's advertised tool catalogue.",
)]
/**
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class ImportMcpCatalogueCommand extends Command
{
    public function __construct(
        private readonly McpImportService $importer,
        private readonly McpServerRepository $servers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'identifier',
            InputArgument::OPTIONAL,
            "The MCP server's identifier, as set on the record. Omit it and pass --all instead.",
        );

        $this->addOption(
            'all',
            'a',
            InputOption::VALUE_NONE,
            'Import every enabled server. One failing server does not stop the others.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $identifier = $input->getArgument('identifier');
        $identifier = is_string($identifier) ? $identifier : null;

        $all = $input->getOption('all') === true;

        if ($all && $identifier !== null) {
            $io->error('Pass either an identifier or --all, not both.');

            return Command::INVALID;
        }

        if (!$all && ($identifier === null || $identifier === '')) {
            $io->error('Name a server identifier, or pass --all to import every enabled server.');

            return Command::INVALID;
        }

        $servers = $all ? $this->servers->findEnabled() : $this->resolve($identifier ?? '', $io);
        if ($servers === null) {
            return Command::FAILURE;
        }

        if ($servers === []) {
            // Not a failure: an installation with no enabled server is a valid
            // state, and a deploy that runs --all unconditionally must not go
            // red because this one has none yet.
            $io->warning('No enabled MCP server to import.');

            return Command::SUCCESS;
        }

        $refused = 0;
        foreach ($servers as $server) {
            if (!$this->report($this->importer->import($server), $server, $io)) {
                ++$refused;
            }
        }

        if ($refused > 0) {
            $io->error(sprintf('%d of %d server(s) refused the import.', $refused, count($servers)));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * The one enabled server carrying this identifier, or null with the reason printed.
     *
     * @return list<McpServerRecord>|null
     */
    private function resolve(string $identifier, SymfonyStyle $io): ?array
    {
        $matches = array_values(array_filter(
            $this->servers->findEnabled(),
            static fn(McpServerRecord $server): bool => $server->identifier === $identifier,
        ));

        if ($matches === []) {
            $io->error(sprintf('No enabled MCP server has the identifier "%s".', $identifier));

            return null;
        }

        if (count($matches) > 1) {
            // The service refuses this case too, but it would refuse it once per
            // row and read as two unrelated failures. Said here, once, in the
            // terms the operator has to act on.
            $io->error(sprintf(
                '%d enabled servers share the identifier "%s". Identifiers name the imported tools, so they must be unique among enabled servers.',
                count($matches),
                $identifier,
            ));

            return null;
        }

        return $matches;
    }

    /**
     * Prints one server's outcome. Returns false when the import was refused.
     */
    private function report(McpImportReport $report, McpServerRecord $server, SymfonyStyle $io): bool
    {
        if ($report->refused) {
            $io->writeln(sprintf(
                '<error>%s: refused</error> — %s',
                $server->identifier,
                $report->skipReasons[0] ?? 'no reason given',
            ));

            return false;
        }

        // Printed even when all three are zero: "imported 0" after a successful
        // contact means the catalogue matched what was already stored, which is
        // the expected result of the second run and worth seeing.
        $io->writeln(sprintf(
            '<info>%s</info>: %d imported, %d skipped, %d orphaned',
            $server->identifier,
            $report->imported,
            $report->skipped,
            $report->orphaned,
        ));

        foreach ($report->skipReasons as $reason) {
            $io->writeln('    skipped: ' . $reason);
        }

        return true;
    }
}
