<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Throwable;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Stores a provider API key from an unattended context (ADR-124).
 *
 * The setup wizard is the interactive way to hand nr-llm a key: it takes the
 * plaintext, stores it and writes the resulting identifier onto the provider
 * record. A Docker entrypoint, a DDEV install script or a CI provisioning step
 * cannot operate a wizard, and without this command their only option was to
 * call nr-vault directly and hand-write the identifier — pushing knowledge of
 * where nr-llm keeps its secrets into every consuming extension.
 *
 * The secret is read from STDIN only. Passing it as an argument would put it in
 * the process list and the shell history, which no option flag can undo.
 */
#[AsCommand(
    name: 'nrllm:provider:set-key',
    description: 'Store a provider API key read from STDIN and link it to the provider record.',
)]
final class SetProviderApiKeyCommand extends Command
{
    /**
     * Recorded with the secret so an audit can tell a scripted install from a
     * wizard run. Mirrors the metadata {@see SetupWizardController} writes.
     *
     * Note the nesting: nr-vault's `store()` reads provenance from the
     * `metadata` option and ignores every unknown top-level key.
     */
    private const SECRET_OPTIONS = [
        'metadata' => [
            'table'  => 'tx_nrllm_provider',
            'field'  => 'api_key',
            'source' => 'console',
        ],
    ];

    public function __construct(
        private readonly ProviderRepository $providerRepository,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly VaultServiceInterface $vault,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'provider',
            InputArgument::REQUIRED,
            'Identifier of the provider record the key belongs to.',
        );

        $this->setHelp(
            <<<'HELP'
                Reads the API key from STDIN and links it to an existing provider record:

                  printf '%s' "$OPENAI_API_KEY" | vendor/bin/typo3 nrllm:provider:set-key openai

                Running it again for the same provider replaces the stored key while keeping
                the identifier, so anything already referencing that identifier keeps working.
                HELP
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $providerIdentifier = $input->getArgument('provider');
        $providerIdentifier = is_string($providerIdentifier) ? $providerIdentifier : '';

        $provider = $this->providerRepository->findOneByIdentifier($providerIdentifier);
        if ($provider === null) {
            $io->error(sprintf('No provider record has the identifier "%s".', $providerIdentifier));

            return Command::FAILURE;
        }

        $stream = $this->resolveInputStream($input);
        if ($stream === null) {
            $io->error('Refusing to read the key from a terminal. Pipe it in, for example: printf \'%s\' "$KEY" | vendor/bin/typo3 nrllm:provider:set-key ' . $providerIdentifier);

            return Command::INVALID;
        }

        $secret = $this->readSecret($stream);
        if ($secret === '') {
            $io->error('STDIN carried no key. Nothing was stored and the provider was left untouched.');

            return Command::INVALID;
        }

        $identifier = $provider->getApiKey();
        $isNewKey   = $identifier === '' || !$this->vault->exists($identifier);

        try {
            if ($isNewKey) {
                if ($identifier === '') {
                    $identifier = Uuid::v7()->toRfc4122();
                }
                $this->vault->store($identifier, $secret, self::SECRET_OPTIONS);
            } else {
                $this->vault->rotate($identifier, $secret, 'nrllm:provider:set-key');
            }
        } catch (Throwable $e) {
            // The exception may carry the identifier or provider context, so it
            // goes to the log rather than to the terminal.
            $this->logger->error('nrllm:provider:set-key: failed to store the API key', [
                'provider'  => $providerIdentifier,
                'exception' => $e,
            ]);
            $io->error('Failed to store the API key. See the system log for details.');

            return Command::FAILURE;
        }

        if ($provider->getApiKey() !== $identifier) {
            $provider->setApiKey($identifier);
            $this->providerRepository->update($provider);
            $this->persistenceManager->persistAll();
        }

        $io->success(
            $isNewKey
                ? sprintf('Stored the API key for provider "%s" under identifier %s.', $providerIdentifier, $identifier)
                : sprintf('Replaced the API key for provider "%s". Identifier %s is unchanged.', $providerIdentifier, $identifier),
        );

        return Command::SUCCESS;
    }

    /**
     * Returns the stream to read the secret from, or null when there is none.
     *
     * A terminal counts as "none": reading it would block on a prompt that a
     * provisioning script can never answer, which looks like a hung install.
     *
     * @return resource|null
     */
    private function resolveInputStream(InputInterface $input)
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;

        if ($stream === null) {
            if (!defined('STDIN')) {
                return null;
            }
            $stream = STDIN;
        }

        if (!is_resource($stream)) {
            return null;
        }

        return stream_isatty($stream) ? null : $stream;
    }

    /**
     * @param resource $stream
     */
    private function readSecret($stream): string
    {
        $raw = stream_get_contents($stream);

        // A key piped with `echo` carries a trailing newline; one piped with
        // `printf '%s'` does not. Everything else is kept verbatim — trimming
        // further would silently mangle a key rather than reject it.
        return $raw === false ? '' : rtrim($raw, "\r\n");
    }
}
