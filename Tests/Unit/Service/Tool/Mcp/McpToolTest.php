<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\McpToolRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpTool;
use Netresearch\NrLlm\Service\Tool\RemoteApprovalInterface;
use Netresearch\NrLlm\Service\Tool\RemoteToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\RecordedContacts;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

#[CoversClass(McpTool::class)]
final class McpToolTest extends AbstractUnitTestCase
{
    private function record(string $description = 'Reads a page'): McpToolRecord
    {
        return new McpToolRecord(
            uid: 5,
            pid: 0,
            server: 1,
            toolName: 'mcp_srv_read_page',
            remoteName: 'read_page',
            description: $description,
            inputSchema: '{"type":"object","properties":{}}',
            remoteAnnotations: '',
            orphaned: false,
            tstamp: 0,
            crdate: 0,
        );
    }

    private function toolFor(McpTestServer $fake, ToolDataClass $dataClass = ToolDataClass::PUBLIC_CONTENT, bool $requiresApproval = true): McpTool
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            $this->createSecureHttpClientFactoryMock(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($fake);

        return new McpTool(
            McpTestServer::server(),
            $this->record(),
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            $dataClass,
            $requiresApproval,
            new McpClient(
                $transport,
                new RecordedContacts(),
                new McpDeadlineFactory(new FakeMcpClock(), self::createStub(ExtensionConfiguration::class)),
            ),
        );
    }

    #[Test]
    public function isMarkedAsRemote(): void
    {
        self::assertInstanceOf(RemoteToolInterface::class, $this->toolFor(new McpTestServer()));
    }

    #[Test]
    public function takesItsDataClassFromTheOperatorDeclaration(): void
    {
        $tool = $this->toolFor(new McpTestServer(), ToolDataClass::INTERNAL_CONFIGURATION);

        self::assertSame(ToolDataClass::INTERNAL_CONFIGURATION, $tool->getDataClass());
    }

    /**
     * The inverse of the builtin default. A remote body is not ours to inspect,
     * so the assumption has to be that the call changed something and must not
     * be replayed.
     */
    #[Test]
    public function countsAsANonIdempotentWrite(): void
    {
        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $this->toolFor(new McpTestServer())->getEffect());
    }

    /**
     * The approval answer is the operator's, carried in from the server row
     * (ADR-134). It is deliberately NOT derived from
     * {@see McpTool::getEffect()}: that value is a fail-closed assumption about
     * a body nobody here can inspect, so reading it as consent-worthy would
     * suspend every remote call including a pure search.
     */
    #[Test]
    public function carriesTheOperatorsApprovalDeclaration(): void
    {
        self::assertTrue($this->toolFor(new McpTestServer(), requiresApproval: true)->requiresApproval());
        self::assertFalse($this->toolFor(new McpTestServer(), requiresApproval: false)->requiresApproval());
    }

    /**
     * The declaration interface extends the remote marker, so a builtin cannot
     * reach for it to opt out of ADR-134's write coupling without also claiming
     * its behaviour lives outside this codebase.
     */
    #[Test]
    public function declaresItsApprovalAsARemoteTool(): void
    {
        self::assertInstanceOf(RemoteApprovalInterface::class, $this->toolFor(new McpTestServer()));
        self::assertContains(
            RemoteToolInterface::class,
            (new ReflectionClass(RemoteApprovalInterface::class))->getInterfaceNames(),
            'dropping the extends would let a builtin declare its way out of ADR-134',
        );
    }

    #[Test]
    public function requiresAdminAndIsOffUntilEnabled(): void
    {
        $tool = $this->toolFor(new McpTestServer());

        self::assertTrue($tool->requiresAdmin());
        self::assertFalse($tool->isEnabledByDefault());
    }

    #[Test]
    public function livesInItsOwnServerGroup(): void
    {
        self::assertSame('mcp_srv', $this->toolFor(new McpTestServer())->getGroup());
    }

    #[Test]
    public function usesTheLocalNameAndTheStoredSchemaInItsSpec(): void
    {
        $spec = $this->toolFor(new McpTestServer())->getSpec();

        self::assertSame('mcp_srv_read_page', $spec->name);
        self::assertSame(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]], $spec->parameters);
    }

    /**
     * The description is written by a third party and read by a model that
     * treats it as instruction. Naming the origin first does not make it safe,
     * but the model is never told the sentence came from this installation.
     */
    #[Test]
    public function statesTheOriginAheadOfTheRemoteDescription(): void
    {
        $spec = $this->toolFor(new McpTestServer())->getSpec();

        self::assertStringStartsWith('[via the external MCP server "Test server"]', $spec->description);
        self::assertStringContainsString('Reads a page', $spec->description);
    }

    #[Test]
    public function callsTheRemoteNameNotTheLocalOne(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['content' => [['type' => 'text', 'text' => 'done']]]);

        $result = $this->toolFor($fake)->execute(['id' => 3], ToolExecutionContext::none());

        self::assertSame('done', $result->content);
        self::assertSame('read_page', $fake->received[2]['body']['params']['name']);
    }

    /**
     * A server that is down is a fact about this call, not a fault in the run:
     * the loop carries on and the model is told plainly what failed.
     */
    #[Test]
    public function turnsAnUnreachableServerIntoAResultRatherThanAnException(): void
    {
        $fake = (new McpTestServer())->willReturnRaw('nope', 503);

        $result = $this->toolFor($fake)->execute([], ToolExecutionContext::none());

        self::assertStringContainsString('503', $result->content);
        self::assertStringContainsString('"srv"', $result->content);
    }
}
