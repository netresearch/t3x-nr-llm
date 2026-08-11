<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\ValueObject\McpImportReport;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpImportService;
use Netresearch\NrLlm\Service\Tool\Mcp\McpSchemaNormalizer;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolNameMapper;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolProvider;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolRepository;
use Netresearch\NrLlm\Service\Tool\RemoteToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\RecordedContacts;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * The import, end to end: a scripted server, a real database, real rows (ADR-116).
 *
 * Functional rather than unit because the repositories are `final readonly` and
 * the interesting behaviour is what ends up in the table. Only the HTTP client
 * is faked, so the JSON-RPC encoding, the handshake and the reconciliation are
 * all the production ones.
 */
#[CoversClass(McpImportService::class)]
#[CoversClass(McpToolProvider::class)]
final class McpImportServiceTest extends AbstractFunctionalTestCase
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

    private function importer(McpTestServer $fake, ToolRegistry $registry = new ToolRegistry()): McpImportService
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            new SecureHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($fake);

        return new McpImportService(
            new McpClient($transport, new RecordedContacts()),
            $this->servers,
            $this->catalogue,
            new McpToolNameMapper(),
            new McpSchemaNormalizer(),
            $registry,
            new NullLogger(),
            new Context(),
        );
    }

    /**
     * @param array<string, mixed> ...$tools
     */
    private function advertising(array ...$tools): McpTestServer
    {
        return (new McpTestServer())->willHandshake()->willReturn(['tools' => array_values($tools)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description = 'does a thing'): array
    {
        return [
            'name'        => $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ];
    }

    #[Test]
    public function writesTheAdvertisedToolsIntoTheCatalogue(): void
    {
        $server = $this->insertServer();

        $report = $this->importer($this->advertising($this->tool('read'), $this->tool('write')))->import($server);

        self::assertFalse($report->refused);
        self::assertSame(2, $report->imported);
        self::assertSame(0, $report->skipped);
        self::assertSame(
            ['mcp_srv_read', 'mcp_srv_write'],
            array_map(static fn(object $t): string => $t->toolName, $this->catalogue->findLiveByServer($server->uid)),
        );
    }

    #[Test]
    public function recordsTheOutcomeOnTheServerRow(): void
    {
        $server = $this->insertServer();

        $this->importer($this->advertising($this->tool('read')))->import($server);

        $after = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $after);
        self::assertSame('ok', $after->importStatus);
        self::assertSame('', $after->importError);
        self::assertSame(1, $after->toolCount);
        self::assertGreaterThan(0, $after->lastImported);
    }

    /**
     * An unclassified server cannot be classified by guessing, so it supplies
     * nothing and says why.
     */
    #[Test]
    public function refusesAServerWithoutADeclaredDataClass(): void
    {
        $server = $this->insertServer(['data_class' => '']);

        $report = $this->importer($this->advertising($this->tool('read')))->import($server);

        self::assertTrue($report->refused);
        self::assertStringContainsString('data class', $report->skipReasons[0]);
        self::assertSame([], $this->catalogue->findAllByServer($server->uid));
    }

    /**
     * A server we could not reach has not told us its tools are gone. Orphaning
     * them on a network failure would disable an operator's working tools by
     * accident.
     */
    #[Test]
    public function anUnreachableServerLeavesTheExistingCatalogueAlone(): void
    {
        $server = $this->insertServer();
        $this->importer($this->advertising($this->tool('read')))->import($server);

        $broken = (new McpTestServer())->willReturnRaw('gateway down', 502);
        $report = $this->importer($broken)->import($server);

        self::assertTrue($report->refused);
        self::assertCount(1, $this->catalogue->findLiveByServer($server->uid), 'the working tool survives');

        $after = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $after);
        self::assertSame('error', $after->importStatus);
        self::assertStringContainsString('502', $after->importError);
    }

    /**
     * A rejected tool must be reported, not merely absent: absence looks
     * identical to a tool the server never offered.
     */
    #[Test]
    public function reportsWhyEachRejectedToolWasSkipped(): void
    {
        $server = $this->insertServer();

        $report = $this->importer($this->advertising(
            $this->tool('good'),
            $this->tool('has a space'),
            ['description' => 'nameless'],
        ))->import($server);

        self::assertSame(1, $report->imported);
        self::assertSame(2, $report->skipped);
        self::assertCount(2, $report->skipReasons);
        self::assertStringContainsString('has a space', implode(' ', $report->skipReasons));
        self::assertStringContainsString('no name', implode(' ', $report->skipReasons));

        $after = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $after);
        self::assertSame('partial', $after->importStatus);
    }

    /**
     * A remote tool must never be able to take a builtin's name: the local name
     * is what the gate, the tool state and the model all key on.
     */
    #[Test]
    public function refusesARemoteToolThatWouldShadowABuiltin(): void
    {
        $server   = $this->insertServer(['identifier' => 'x']);
        $registry = new ToolRegistry([new FakeTool('mcp_x_read')]);

        $report = $this->importer($this->advertising($this->tool('read')), $registry)->import($server);

        self::assertSame(0, $report->imported);
        self::assertStringContainsString('collide with a builtin', $report->skipReasons[0]);
    }

    /**
     * Two enabled servers sharing an identifier would generate the same local
     * names and the second import would hit the catalogue's unique index
     * halfway through. Refused up front, naming the setting that is wrong.
     */
    #[Test]
    public function refusesTwoServersClaimingTheSameIdentifier(): void
    {
        $this->insertServer(['identifier' => 'twin', 'name' => 'The first one']);
        $second = $this->insertServer(['identifier' => 'twin', 'name' => 'The second one']);

        $report = $this->importer($this->advertising($this->tool('read')))->import($second);

        self::assertTrue($report->refused);
        self::assertStringContainsString('The first one', $report->skipReasons[0]);
    }

    #[Test]
    public function aSecondImportRemovesWhatTheServerNoLongerOffers(): void
    {
        $server = $this->insertServer();
        $this->importer($this->advertising($this->tool('read'), $this->tool('write')))->import($server);

        $report = $this->importer($this->advertising($this->tool('read')))->import($server);

        self::assertSame(1, $report->imported);
        self::assertSame(1, $report->orphaned);
        self::assertCount(1, $this->catalogue->findLiveByServer($server->uid));
    }

    /**
     * The whole point of the import: the tools it wrote become registered tools
     * on the next registry build, with the operator's classification on them.
     */
    #[Test]
    public function theImportedToolsBecomeRegisteredRemoteTools(): void
    {
        $server = $this->insertServer();
        $this->importer($this->advertising($this->tool('read')))->import($server);

        $provider = new McpToolProvider($this->servers, $this->catalogue, $this->createClient());
        $registry = new ToolRegistry([new FakeTool('a_builtin')], [$provider]);

        self::assertSame(['a_builtin', 'mcp_srv_read'], $registry->names());
        self::assertSame(['a_builtin'], $registry->builtinNames(), 'the coverage tests must not see a remote tool');

        $tool = $registry->get('mcp_srv_read');
        self::assertInstanceOf(RemoteToolInterface::class, $tool);
        self::assertSame('mcp_srv', $tool->getGroup());
        self::assertTrue($tool->requiresAdmin());
        self::assertFalse($tool->isEnabledByDefault(), 'import configures, it does not grant');
    }

    /**
     * An unclassified server is inert at run time too, not only at import: a
     * classification removed after an import must take its tools out of the
     * registry.
     */
    #[Test]
    public function withdrawingTheClassificationWithdrawsTheTools(): void
    {
        $server = $this->insertServer();
        $this->importer($this->advertising($this->tool('read')))->import($server);

        $this->connectionPool->getConnectionForTable('tx_nrllm_mcp_server')
            ->update('tx_nrllm_mcp_server', ['data_class' => ''], ['uid' => $server->uid]);

        $provider = new McpToolProvider($this->servers, $this->catalogue, $this->createClient());

        self::assertSame([], (new ToolRegistry([], [$provider]))->names());
    }

    /**
     * DATA CLASSIFICATION at the seam that resolves it (ADR-161).
     *
     * The class the gate compares comes off the server row the operator
     * declared. A server writing a contrary claim into its own annotations
     * changes nothing: the annotations are stored verbatim for display and read
     * by no resolver. Asserted here rather than in the conformance pack because
     * that pack constructs an {@see McpTool} directly — it can pin what the tool
     * declares, not what turns a row into that declaration.
     */
    #[Test]
    public function theServerRowDecidesTheDataClassNotTheToolsOwnAnnotations(): void
    {
        $server = $this->insertServer(['data_class' => 'internalConfiguration']);
        $this->importer($this->advertising(
            $this->tool('read') + ['annotations' => ['readOnlyHint' => true, 'dataClass' => 'publicContent']],
        ))->import($server);

        $provider = new McpToolProvider($this->servers, $this->catalogue, $this->createClient());
        $resolver = new ToolDataClassResolver(new ToolRegistry([], [$provider]));

        self::assertStringContainsString(
            'publicContent',
            $this->catalogue->findLiveByServer($server->uid)[0]->remoteAnnotations,
            "the server's claim is stored, so the assertion below is about it being ignored",
        );
        self::assertSame(ToolDataClass::INTERNAL_CONFIGURATION, $resolver->classFor('mcp_srv_read'));
    }

    /**
     * INVALID SCHEMA at the other end of the same rule (ADR-161): a stored
     * schema that no longer decodes to a JSON object yields no callable tool.
     * The catalogue row survives so an operator can see it; the registry gets
     * nothing, because inventing an empty schema would advertise a signature
     * the remote tool does not have.
     */
    #[Test]
    public function aStoredSchemaThatIsNoLongerAnObjectYieldsNoCallableTool(): void
    {
        $server = $this->insertServer();
        $this->importer($this->advertising($this->tool('read')))->import($server);

        $this->connectionPool->getConnectionForTable('tx_nrllm_mcp_tool')
            ->update('tx_nrllm_mcp_tool', ['input_schema' => '[]'], ['server' => $server->uid]);

        $provider = new McpToolProvider($this->servers, $this->catalogue, $this->createClient());

        self::assertSame([], (new ToolRegistry([], [$provider]))->names());
        self::assertCount(1, $this->catalogue->findLiveByServer($server->uid), 'the row stays visible to the operator');
    }

    private function createClient(): McpClient
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            new SecureHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );

        return new McpClient($transport, new RecordedContacts());
    }

    /**
     * A refusal is not an import that wrote nothing: the catalogue was never
     * reconciled, and the report has to keep the two apart.
     */
    #[Test]
    public function distinguishesARefusalFromAnEmptyCatalogue(): void
    {
        $server = $this->insertServer();

        $empty = $this->importer($this->advertising())->import($server);

        self::assertInstanceOf(McpImportReport::class, $empty);
        self::assertFalse($empty->refused);
        self::assertSame(0, $empty->imported);
    }
}
