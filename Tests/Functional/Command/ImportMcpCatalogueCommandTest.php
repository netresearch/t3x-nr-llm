<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Command;

use Netresearch\NrLlm\Command\ImportMcpCatalogueCommand;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpImportService;
use Netresearch\NrLlm\Service\Tool\Mcp\McpSchemaNormalizer;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolNameMapper;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolRepository;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\RecordedContacts;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * The command against a scripted server and a real database.
 *
 * Functional rather than unit for the same reason
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp\McpImportServiceTest}
 * is: the importer and both repositories are `final readonly`, so a faked
 * import service is not constructible — and the acceptance criterion that
 * matters most, that a second run changes nothing, cannot be shown by a fake
 * that never writes a row. Only the HTTP client is scripted.
 */
#[CoversClass(ImportMcpCatalogueCommand::class)]
final class ImportMcpCatalogueCommandTest extends AbstractFunctionalTestCase
{
    private ConnectionPool $connectionPool;

    private McpServerRepository $servers;

    private McpToolRepository $catalogue;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $this->connectionPool = $connectionPool;
        $this->servers        = new McpServerRepository($connectionPool);
        $this->catalogue      = new McpToolRepository($connectionPool);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertServer(array $overrides = []): McpServerRecord
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_nrllm_mcp_server');
        $connection->insert('tx_nrllm_mcp_server', $overrides + [
            'pid'              => 0,
            'identifier'       => 'srv',
            'name'             => 'A server',
            'description'      => '',
            'url'              => 'https://mcp.example.com/rpc',
            'auth_credential'  => '',
            'auth_placement'   => 'bearer',
            'auth_header_name' => '',
            'data_class'       => 'publicContent',
            'enabled'          => 1,
            'import_status'    => 'never_imported',
            'import_error'     => '',
            'last_imported'    => 0,
            'tool_count'       => 0,
            'tstamp'           => 0,
            'crdate'           => 0,
            'deleted'          => 0,
            'hidden'           => 0,
        ]);

        $server = $this->servers->findByUid((int)$connection->lastInsertId());
        self::assertInstanceOf(McpServerRecord::class, $server);

        return $server;
    }

    /**
     * The scripted server answers a fixed QUEUE of requests, one entry per
     * contact, so a test that imports two servers has to script two — passed in
     * rather than defaulted, because getting it wrong reads as a refusal from
     * the second server rather than as an exhausted fixture.
     *
     * @param array<string, mixed> ...$tools
     */
    private function tester(int $contacts, array ...$tools): CommandTester
    {
        $fake = new McpTestServer();
        for ($i = 0; $i < $contacts; ++$i) {
            $fake = $fake->willHandshake()->willReturn(['tools' => array_values($tools)]);
        }

        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            new SecureHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($fake);

        $importer = new McpImportService(
            new McpClient($transport, new RecordedContacts(), $this->get(McpDeadlineFactory::class)),
            $this->servers,
            $this->catalogue,
            new McpToolNameMapper(),
            new McpSchemaNormalizer(),
            new ToolRegistry(),
            new NullLogger(),
            new Context(),
        );

        return new CommandTester(new ImportMcpCatalogueCommand($importer, $this->servers));
    }

    /**
     * @return array<string, mixed>
     */
    private function tool(string $name): array
    {
        return [
            'name'        => $name,
            'description' => 'does a thing',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ];
    }

    /**
     * @return list<string>
     */
    private function liveToolNames(int $serverUid): array
    {
        return array_map(
            static fn(object $tool): string => $tool->toolName,
            $this->catalogue->findLiveByServer($serverUid),
        );
    }

    #[Test]
    public function importsTheCatalogueOfTheNamedServer(): void
    {
        $server = $this->insertServer(['identifier' => 'deepwiki']);
        $tester = $this->tester(1, $this->tool('read'), $this->tool('write'));

        $exit = $tester->execute(['identifier' => 'deepwiki']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('deepwiki', $tester->getDisplay());
        self::assertStringContainsString('2 imported', $tester->getDisplay());
        self::assertSame(['mcp_deepwiki_read', 'mcp_deepwiki_write'], $this->liveToolNames($server->uid));
    }

    /**
     * The acceptance criterion a faked importer cannot show: running twice
     * against an unchanged catalogue reconciles to the same rows rather than
     * adding a second copy of each tool.
     */
    #[Test]
    public function aSecondRunAgainstAnUnchangedCatalogueAddsNothing(): void
    {
        $server = $this->insertServer(['identifier' => 'deepwiki']);

        $first = $this->tester(1, $this->tool('read'), $this->tool('write'));
        self::assertSame(Command::SUCCESS, $first->execute(['identifier' => 'deepwiki']));
        $afterFirst = $this->liveToolNames($server->uid);

        $second = $this->tester(1, $this->tool('read'), $this->tool('write'));
        self::assertSame(Command::SUCCESS, $second->execute(['identifier' => 'deepwiki']));

        self::assertSame($afterFirst, $this->liveToolNames($server->uid));
        self::assertCount(2, $afterFirst);
    }

    #[Test]
    public function allWalksEveryEnabledServer(): void
    {
        $one = $this->insertServer(['identifier' => 'one']);
        $two = $this->insertServer(['identifier' => 'two']);

        $tester = $this->tester(2, $this->tool('read'));
        $exit   = $tester->execute(['--all' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['mcp_one_read'], $this->liveToolNames($one->uid));
        self::assertSame(['mcp_two_read'], $this->liveToolNames($two->uid));
    }

    /**
     * A disabled server is not part of `--all`, so the catalogue it may already
     * hold is neither refreshed nor orphaned by this command.
     */
    #[Test]
    public function allSkipsADisabledServer(): void
    {
        $enabled  = $this->insertServer(['identifier' => 'on']);
        $disabled = $this->insertServer(['identifier' => 'off', 'enabled' => 0]);

        $tester = $this->tester(1, $this->tool('read'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--all' => true]));

        self::assertSame(['mcp_on_read'], $this->liveToolNames($enabled->uid));
        self::assertSame([], $this->liveToolNames($disabled->uid));
        self::assertStringNotContainsString('off', $tester->getDisplay());
    }

    /**
     * A deploy that imports every server must not stop at the first bad one,
     * and must not report success when one of them refused.
     */
    #[Test]
    public function oneRefusingServerDoesNotStopTheOthersButFailsTheRun(): void
    {
        $broken  = $this->insertServer(['identifier' => 'broken', 'data_class' => '']);
        $working = $this->insertServer(['identifier' => 'working']);

        $tester = $this->tester(1, $this->tool('read'));
        $exit   = $tester->execute(['--all' => true]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('data class', $tester->getDisplay());
        self::assertSame([], $this->liveToolNames($broken->uid));
        self::assertSame(['mcp_working_read'], $this->liveToolNames($working->uid), 'The healthy server must still have been imported.');
        self::assertStringContainsString('1 of 2', $tester->getDisplay());
    }

    #[Test]
    public function refusesWhenNeitherAnIdentifierNorAllIsGiven(): void
    {
        $tester = $this->tester(0, $this->tool('read'));

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('--all', $tester->getDisplay());
    }

    #[Test]
    public function refusesWhenBothAnIdentifierAndAllAreGiven(): void
    {
        $this->insertServer(['identifier' => 'one']);
        $tester = $this->tester(0, $this->tool('read'));

        self::assertSame(Command::INVALID, $tester->execute(['identifier' => 'one', '--all' => true]));
        self::assertStringContainsString('not both', $tester->getDisplay());
    }

    #[Test]
    public function failsOnAnIdentifierNoEnabledServerCarries(): void
    {
        $this->insertServer(['identifier' => 'one']);
        $tester = $this->tester(0, $this->tool('read'));

        self::assertSame(Command::FAILURE, $tester->execute(['identifier' => 'other']));
        self::assertStringContainsString('"other"', $tester->getDisplay());
    }

    /**
     * The table has no unique index on the identifier — soft-deleted rows keep
     * theirs — so two enabled twins are reachable. Reported once, in the terms
     * the operator has to act on, rather than as two unrelated refusals.
     */
    #[Test]
    public function namesTheAmbiguityWhenTwoEnabledServersShareAnIdentifier(): void
    {
        $this->insertServer(['identifier' => 'twin']);
        $this->insertServer(['identifier' => 'twin']);

        $tester = $this->tester(0, $this->tool('read'));

        self::assertSame(Command::FAILURE, $tester->execute(['identifier' => 'twin']));
        self::assertStringContainsString('2 enabled servers share', $tester->getDisplay());
    }

    /**
     * A deploy runs `--all` unconditionally. An installation that carries no
     * enabled server yet is a valid state, not a red pipeline.
     */
    #[Test]
    public function allSucceedsWithNothingToImport(): void
    {
        $tester = $this->tester(0, $this->tool('read'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--all' => true]));
        self::assertStringContainsString('No enabled MCP server', $tester->getDisplay());
    }
}
