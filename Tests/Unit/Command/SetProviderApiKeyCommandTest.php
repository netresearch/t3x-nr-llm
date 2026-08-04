<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\SetProviderApiKeyCommand;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryVaultService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

#[CoversClass(SetProviderApiKeyCommand::class)]
final class SetProviderApiKeyCommandTest extends TestCase
{
    private const SECRET = 'sk-test-0123456789';

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function storesTheKeyAndLinksItToTheProvider(): void
    {
        $provider = $this->provider('openai', apiKey: '');
        $vault    = new InMemoryVaultService();

        $exit = $this->runCommand($this->command($provider, $vault), 'openai', self::SECRET);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotSame('', $provider->getApiKey(), 'the provider must end up referencing an identifier');
        self::assertSame(self::SECRET, $vault->secrets[$provider->getApiKey()] ?? null);
        self::assertSame([$provider->getApiKey()], $vault->storeCalls);
        self::assertSame([], $vault->rotateCalls);
    }

    #[Test]
    public function recordsProvenanceUnderTheMetadataOption(): void
    {
        $provider = $this->provider('openai', apiKey: '');
        $vault    = new InMemoryVaultService();

        $this->runCommand($this->command($provider, $vault), 'openai', self::SECRET);

        // nr-vault drops unknown top-level option keys — provenance only
        // survives when it is nested under `metadata`.
        self::assertSame(
            ['table' => 'tx_nrllm_provider', 'field' => 'api_key', 'source' => 'console'],
            $vault->storeOptions[$provider->getApiKey()]['metadata'] ?? null,
        );
    }

    #[Test]
    public function replacesAnExistingKeyWithoutChangingTheIdentifier(): void
    {
        $identifier                  = '0198c0de-dead-7000-8000-000000000001';
        $provider                    = $this->provider('openai', apiKey: $identifier);
        $vault                       = new InMemoryVaultService();
        $vault->secrets[$identifier] = 'sk-the-old-one';

        $exit = $this->runCommand($this->command($provider, $vault), 'openai', self::SECRET);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame($identifier, $provider->getApiKey(), 'the identifier must survive a replacement');
        self::assertSame(self::SECRET, $vault->secrets[$identifier]);
        self::assertSame([['identifier' => $identifier, 'reason' => 'nrllm:provider:set-key']], $vault->rotateCalls);
        self::assertSame([], $vault->storeCalls, 'an existing secret is rotated, not stored again');
    }

    #[Test]
    public function storesUnderTheReferencedIdentifierWhenTheSecretIsGone(): void
    {
        // The provider points at an identifier the vault no longer knows, e.g.
        // after a restore that carried the tables but not the vault.
        $identifier = '0198c0de-dead-7000-8000-000000000002';
        $provider   = $this->provider('openai', apiKey: $identifier);
        $vault      = new InMemoryVaultService();

        $exit = $this->runCommand($this->command($provider, $vault), 'openai', self::SECRET);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame($identifier, $provider->getApiKey());
        self::assertSame([$identifier], $vault->storeCalls);
    }

    #[Test]
    public function failsOnAnUnknownProvider(): void
    {
        $vault = new InMemoryVaultService();

        $exit = $this->runCommand($this->command(null, $vault), 'nope', self::SECRET);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('"nope"', $this->output->fetch());
        self::assertSame([], $vault->storeCalls);
    }

    #[Test]
    public function rejectsEmptyStdin(): void
    {
        $provider = $this->provider('openai', apiKey: '');
        $vault    = new InMemoryVaultService();

        $exit = $this->runCommand($this->command($provider, $vault), 'openai', '');

        self::assertSame(Command::INVALID, $exit);
        self::assertSame('', $provider->getApiKey(), 'nothing may be written when no key arrived');
        self::assertSame([], $vault->storeCalls);
    }

    #[Test]
    public function stripsOnlyTheTrailingNewlineAPipeAdds(): void
    {
        $provider = $this->provider('openai', apiKey: '');
        $vault    = new InMemoryVaultService();

        $this->runCommand($this->command($provider, $vault), 'openai', "  sk-with-padding  \n");

        self::assertSame('  sk-with-padding  ', $vault->secrets[$provider->getApiKey()] ?? null);
    }

    #[Test]
    public function reportsAFailedWriteWithoutLeakingTheSecret(): void
    {
        $provider       = $this->provider('openai', apiKey: '');
        $vault          = new InMemoryVaultService();
        $vault->throwOn = 'store';

        $exit = $this->runCommand($this->command($provider, $vault), 'openai', self::SECRET);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringNotContainsString(self::SECRET, $this->output->fetch());
        self::assertSame('', $provider->getApiKey(), 'a failed write must not link an identifier');
    }

    #[Test]
    public function neverEchoesTheSecretOnSuccess(): void
    {
        $provider = $this->provider('openai', apiKey: '');

        $this->runCommand($this->command($provider, new InMemoryVaultService()), 'openai', self::SECRET);

        self::assertStringNotContainsString(self::SECRET, $this->output->fetch());
    }

    /**
     * Runs the command with $stdin piped in.
     *
     * The input is built here rather than through CommandTester::setInputs():
     * the tester creates its input inside execute(), so a stream set before
     * that call is discarded — and leaving the stream unset would fall through
     * to the real STDIN of the test run.
     */
    private function runCommand(SetProviderApiKeyCommand $command, string $provider, string $stdin): int
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $stdin);
        rewind($stream);

        $input = new ArrayInput(['provider' => $provider]);
        $input->setStream($stream);

        return $command->run($input, $this->output);
    }

    private function command(?Provider $provider, InMemoryVaultService $vault): SetProviderApiKeyCommand
    {
        $repository = $this->createMock(ProviderRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($provider);

        return new SetProviderApiKeyCommand(
            $repository,
            self::createStub(PersistenceManagerInterface::class),
            $vault,
            new NullLogger(),
        );
    }

    private function provider(string $identifier, string $apiKey): Provider
    {
        $provider = new Provider();
        $provider->setIdentifier($identifier);
        $provider->setApiKey($apiKey);

        return $provider;
    }
}
