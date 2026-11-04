<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\LogExceptionEntry;
use Netresearch\NrLlm\Service\Tool\ExceptionHintService;
use Netresearch\NrLlm\Service\Tool\LogExceptionReader;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `nrllm:exception:hint`: the newest error from the TYPO3 file logs, and a
 * model's hint for its cause and next step (ADR-258).
 *
 * Shows the entry as `get_last_exception` heads it (class, sanitised
 * message, throw site) and the hint below, labelled a guess unless it is
 * anchored in a project frame. Whoever runs it already reads the log files.
 */
final class ExceptionHintCommand extends Command
{
    use ErrorMessageSanitizerTrait;

    public function __construct(
        private readonly LogExceptionReader $reader,
        private readonly ExceptionHintService $hints,
        private readonly LlmConfigurationRepository $configurations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('index', 'i', InputOption::VALUE_REQUIRED, 'Which error: 0 = newest', '0')
            ->addOption('search', 's', InputOption::VALUE_REQUIRED, 'Only errors whose message, class or component contains this')
            ->addOption('configuration', 'c', InputOption::VALUE_REQUIRED, 'Identifier of the LLM configuration to ask (default: the default configuration)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $index  = max(0, (int) $this->option($input, 'index'));
        $search = trim($this->option($input, 'search'));

        $entries = $this->reader->read($index + 1, $search !== '' ? $search : null);
        $entry   = $entries[$index] ?? null;
        if (!$entry instanceof LogExceptionEntry) {
            $output->writeln($entries === []
                ? 'No error-level entries found in the TYPO3 file logs.'
                : sprintf('Only %d matching error(s); index %d is out of range.', count($entries), $index));

            return Command::FAILURE;
        }

        $identifier    = trim($this->option($input, 'configuration'));
        $configuration = $identifier !== '' ? $this->configurations->findOneByIdentifier($identifier) : $this->configurations->findDefault();
        if (!$configuration instanceof LlmConfiguration) {
            $output->writeln($identifier !== ''
                ? sprintf('No LLM configuration "%s".', $identifier)
                : 'No default LLM configuration; name one with --configuration.');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('[%s UTC] %s %s', gmdate('Y-m-d H:i:s', $entry->timestamp), $entry->level, $entry->component));
        $output->writeln('Exception: ' . ($entry->exceptionClass ?? '(not recorded)'));
        $output->writeln('Message: ' . $this->sanitizeErrorMessage($entry->message));
        if (isset($entry->frames[0])) {
            $output->writeln(sprintf('At: %s:%d', $entry->frames[0]['file'], $entry->frames[0]['line']));
        }

        $output->writeln('');

        $hint = $this->hints->hint($entry, $configuration);
        if ($hint->isRefused()) {
            $output->writeln('No hint: ' . $hint->refusal);

            return Command::FAILURE;
        }

        $output->writeln($hint->guess ? 'Hint (a guess, not anchored in a project frame):' : 'Hint:');
        $output->writeln('  Likely cause: ' . $hint->cause);
        $output->writeln('  Next step: ' . $hint->nextStep);
        if ($hint->frame !== null) {
            $frame = $entry->frames[$hint->frame];
            $output->writeln(sprintf('  Frame #%d: %s:%d', $hint->frame, $frame['file'], $frame['line']));
        }

        if ($hint->messageWithheld) {
            $output->writeln("  Made without the message: the provider's trust zone does not admit exception messages.");
        }

        return Command::SUCCESS;
    }

    private function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_scalar($value) ? (string) $value : '';
    }
}
