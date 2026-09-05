<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\Conformance;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\McpToolRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Service\Tool\Mcp\McpSchemaNormalizer;
use Netresearch\NrLlm\Service\Tool\Mcp\McpTool;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolNameMapper;
use Netresearch\NrLlm\Service\Tool\RemoteApprovalInterface;
use Netresearch\NrLlm\Service\Tool\RemoteToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolResultBounder;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\Conformance\McpConnectionProfile;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\Conformance\RecordingVaultHttpClient;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\RecordedContacts;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\SlowVaultHttpClient;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\DnsResolverInterface;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * The conformance suite every MCP connection nr_llm supports is held to
 * (ADR-161).
 *
 * The scope is deliberately narrow and is NOT "nr_llm can do everything MCP
 * can". It is: everything this extension supports as an MCP client is
 * policy-, audit- and health-integrated. So the checks below run from the
 * handshake to the tool result and out into the classifications the tool gate
 * consumes, and they stop at the edges :ref:`ADR-116 <adr-116>` drew — no
 * stdio, no SSE, no resources, no prompts, no sampling.
 *
 * One case, one connection. A subclass supplies an
 * {@see McpConnectionProfile} and nothing else; every response, failure and
 * bound below is scripted identically for all of them, because a check that
 * varied with the connection would not be a conformance check. Adding a
 * transport means adding a profile and a three-line subclass — and the whole
 * list has to pass for it.
 *
 * **No live network.** Everything runs through a faked PSR-18 client, so the
 * real JSON-RPC encoding, the real status handling and the real handshake
 * ordering are exercised rather than a description of them.
 */
abstract class AbstractMcpConformanceTestCase extends AbstractUnitTestCase
{
    protected RecordedContacts $health;

    /**
     * The connection this case holds the client to.
     */
    abstract protected function connection(): McpConnectionProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->health = new RecordedContacts();
    }

    // -- connect -----------------------------------------------------------

    /**
     * CONNECT. The protocol prescribes one opening sequence and a server may
     * refuse everything until it has arrived: initialize, then the readiness
     * notification, then the request that was actually wanted.
     */
    #[Test]
    public function connectOpensTheSessionAndConfirmsItBeforeAnythingElse(): void
    {
        $fake = $this->connection()->scriptedServer()->willReturn(['tools' => []]);

        $this->clientFor($fake)->listTools($this->connection()->server());

        self::assertSame(['initialize', 'notifications/initialized', 'tools/list'], $fake->methods());
    }

    /**
     * CONNECT. Whatever the server decided about session state is what the
     * following requests carry — a session id it issued, or no header at all
     * when it issued none. Inventing one would be as wrong as dropping one.
     */
    #[Test]
    public function carriesTheServersSessionDecisionThroughTheWholeOperation(): void
    {
        $fake = $this->connection()->scriptedServer()->willReturn(['tools' => []]);

        $this->clientFor($fake)->listTools($this->connection()->server());

        self::assertSame('', $fake->received[0]['session'], 'the handshake itself has no session yet');
        self::assertSame($this->connection()->expectedSessionHeader(), $fake->received[1]['session']);
        self::assertSame($this->connection()->expectedSessionHeader(), $fake->received[2]['session']);
    }

    // -- capability discovery ----------------------------------------------

    /**
     * CAPABILITY DISCOVERY, in both directions.
     *
     * Outbound: this client declares none, because declaring one invites the
     * server to use it and none of them is implemented. Inbound: what the
     * server said about itself is read and reported — including a protocol
     * revision that differs from the one we asked for, which is the single most
     * useful thing a connection test can show an operator.
     */
    #[Test]
    public function offersNoClientCapabilitiesAndReportsWhatTheServerDeclared(): void
    {
        $fake = (new McpTestServer())->willReturn([
            'protocolVersion' => '2025-03-26',
            'capabilities'    => ['tools' => ['listChanged' => true]],
            'serverInfo'      => ['name' => 'Conformance MCP', 'version' => '9.9'],
        ], $this->connection()->sessionId);

        $report = $this->clientFor($fake)->ping($this->connection()->server());

        self::assertStringContainsString('"capabilities":{}', $fake->received[0]['raw']);
        self::assertTrue($report->reachable);
        self::assertSame('2025-03-26', $report->protocolVersion);
        self::assertSame('Conformance MCP', $report->serverName);
        self::assertSame('9.9', $report->serverVersion);
    }

    // -- tool discovery ----------------------------------------------------

    /**
     * TOOL DISCOVERY. A catalogue arrives in pages and the walk must resume
     * from the cursor rather than re-reading the first page.
     */
    #[Test]
    public function discoversTheWholeCatalogueAcrossPages(): void
    {
        $fake = $this->connection()->scriptedServer()
            ->willReturn(['tools' => [['name' => 'alpha']], 'nextCursor' => 'p2'])
            ->willReturn(['tools' => [['name' => 'beta']]]);

        $tools = $this->clientFor($fake)->listTools($this->connection()->server());

        self::assertSame(['alpha', 'beta'], array_column($tools, 'name'));
        self::assertArrayNotHasKey('cursor', $fake->received[2]['body']['params']);
        self::assertSame('p2', $fake->received[3]['body']['params']['cursor']);
    }

    /**
     * TOOL DISCOVERY, bounded. A cursor that never resolves is an unbounded
     * loop driven by a third party, so the walk has a page ceiling.
     */
    #[Test]
    public function stopsWalkingACatalogueThatNeverEnds(): void
    {
        $fake = $this->connection()->scriptedServer();
        for ($page = 0; $page < 60; ++$page) {
            $fake->willReturn(['tools' => [], 'nextCursor' => 'forever']);
        }

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('did not end within 50 pages');

        $this->clientFor($fake)->listTools($this->connection()->server());
    }

    // -- JSON schema normalization -----------------------------------------

    /**
     * JSON SCHEMA NORMALIZATION, on a schema that came off this connection.
     *
     * The five keys a provider can be handed are kept verbatim; the annotations
     * around them are dropped, because every byte of the schema is re-sent on
     * every request that offers the tool.
     */
    #[Test]
    public function normalisesADiscoveredSchemaDownToTheAcceptedSubset(): void
    {
        $fake = $this->connection()->scriptedServer()->willReturn(['tools' => [[
            'name'        => 'read_page',
            'inputSchema' => [
                'type'                 => 'object',
                'title'                => 'Arguments',
                '$id'                  => 'https://mcp.example.com/schemas/read_page',
                'examples'             => [['uid' => 1]],
                'x-vendor-hint'        => 'ignore me',
                'description'          => 'Which page to read',
                'properties'           => ['uid' => ['type' => 'integer']],
                'required'             => ['uid'],
                'additionalProperties' => false,
            ],
        ]]]);

        $advertised = $this->clientFor($fake)->listTools($this->connection()->server());
        self::assertCount(1, $advertised);
        assert(isset($advertised[0]['inputSchema']));

        $normalised = (new McpSchemaNormalizer())->normalise($advertised[0]['inputSchema']);

        self::assertIsArray($normalised);
        self::assertSame(
            ['type', 'description', 'properties', 'required', 'additionalProperties'],
            array_keys($normalised),
        );
        self::assertSame(['uid' => ['type' => 'integer']], $normalised['properties']);
        self::assertSame(['uid'], $normalised['required']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unrepresentableSchemas(): array
    {
        return [
            'a reference into a definition block we do not carry' => [
                ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/$defs/a']]],
            ],
            // A union is representable and IS carried now — the guard this case
            // exists for is the keyword whose removal would widen the schema,
            // and a keyword carried verbatim never gets removed. What stays
            // unrepresentable is the applicator no provider dependably reads.
            'an applicator no provider dependably reads' => [
                ['type' => 'object', 'properties' => ['a' => ['dependentRequired' => ['x' => ['y']]]]],
            ],
            'a conditional' => [
                ['type' => 'object', 'if' => ['required' => ['a']], 'then' => ['required' => ['b']]],
            ],
            'a union root type no provider agrees on' => [
                ['type' => ['object', 'null']],
            ],
            'a root that is not an object at all' => [
                ['type' => 'string'],
            ],
            'nothing usable' => [
                'not a schema',
            ],
            'a schema deeper than the walk allows' => [
                self::nestedSchema(McpSchemaNormalizer::MAX_DEPTH + 2),
            ],
            'a schema larger than a tool declaration may cost' => [
                [
                    'type'        => 'object',
                    'description' => str_repeat('x', McpSchemaNormalizer::MAX_ENCODED_BYTES + 1),
                ],
            ],
        ];
    }

    /**
     * INVALID SCHEMA. Every rejection is total: a schema is never partially
     * repaired, because a silently widened schema makes the model call the tool
     * with arguments the server then refuses — an opaque failure mid-run
     * instead of a missing tool at import time.
     */
    #[Test]
    #[DataProvider('unrepresentableSchemas')]
    public function refusesASchemaItCannotRepresentInsteadOfRepairingIt(mixed $schema): void
    {
        self::assertNull((new McpSchemaNormalizer())->normalise($schema));
    }

    /**
     * INVALID SCHEMA, at the other end of the same rule: a stored schema that
     * no longer decodes to a JSON object has no schema to offer, and a tool
     * without a parameter schema cannot be registered — inventing an empty one
     * would advertise a signature the remote tool does not have.
     *
     * This pins the decode. That the registry then yields nothing for such a
     * row while the row itself survives is a claim about
     * {@see \Netresearch\NrLlm\Service\Tool\Mcp\McpToolProvider} over real
     * rows, and is asserted in
     * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp\McpImportServiceTest}.
     */
    #[Test]
    public function aStoredSchemaThatIsNotAnObjectHasNoSchemaToOffer(): void
    {
        self::assertNull($this->record(inputSchema: '[]')->inputSchemaArray());
        self::assertNull($this->record(inputSchema: 'null')->inputSchemaArray());
        self::assertNull($this->record(inputSchema: '{ broken')->inputSchemaArray());
        self::assertSame([], $this->record(inputSchema: '{}')->inputSchemaArray());
    }

    // -- tool execution ----------------------------------------------------

    /**
     * TOOL EXECUTION. The local name is what the gate, the state and the model
     * see; the remote name is what goes on the wire. Confusing the two calls a
     * tool the server does not have.
     */
    #[Test]
    public function executesAToolUnderItsRemoteNameAndReturnsTheResult(): void
    {
        $fake = $this->connection()->scriptedServer()
            ->willReturn(['content' => [['type' => 'text', 'text' => 'page 3 reads: hello']]]);

        $tool   = $this->toolFor($fake);
        $result = $tool->execute(['uid' => 3], ToolExecutionContext::none());

        self::assertFalse($result->isError);
        self::assertSame('page 3 reads: hello', $result->content);
        self::assertSame('read_page', $fake->received[2]['body']['params']['name']);
        self::assertSame(['uid' => 3], $fake->received[2]['body']['params']['arguments']);
        self::assertSame('mcp_' . $this->connection()->identifier . '_read_page', $tool->getSpec()->name);
    }

    /**
     * TOOL EXECUTION, answered as an event stream.
     *
     * The Streamable HTTP transport lets a server frame the response to a POST
     * as `text/event-stream`, and the public reference servers do exactly that
     * for every request. The answer must read the same as a plain JSON one:
     * same result, same contact recorded — nothing about the framing is
     * visible above the transport (ADR-181).
     */
    #[Test]
    public function readsAnEventStreamFramedAnswerLikeAPlainOne(): void
    {
        $fake = $this->connection()->scriptedServer()->willReturnRaw(
            "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"framed: hello\"}]}}\n\n",
            200,
            'text/event-stream',
        );

        $result = $this->toolFor($fake)->execute(['uid' => 3], ToolExecutionContext::none());

        self::assertFalse($result->isError, $result->content);
        self::assertSame('framed: hello', $result->content);
    }

    /**
     * TOOL EXECUTION, failed by the TOOL rather than by the wire.
     *
     * `isError` on an otherwise successful response is how a working server
     * reports that the tool itself failed, and it is the common failure — far
     * more common than the server being unreachable. It has to reach the step
     * the run persists as a failure, or "how often does this server fail" is
     * still a string search over prose (ADR-161).
     *
     * The server answering is a contact even so: the tool failed, not the
     * server.
     */
    #[Test]
    public function aToolLevelErrorFromTheServerBecomesAFailedToolResult(): void
    {
        $fake = $this->connection()->scriptedServer()->willReturn([
            'isError' => true,
            'content' => [['type' => 'text', 'text' => 'page 3 does not exist']],
        ]);

        $result = $this->toolFor($fake)->execute(['uid' => 3], ToolExecutionContext::none());

        self::assertTrue($result->isError, 'the protocol flag decides the step kind, not what the content reads like');
        self::assertStringContainsString('page 3 does not exist', $result->content);
        self::assertSame([], $result->artifacts, 'a failed call carries nothing run-scoped');
        self::assertCount(1, $this->health->contacts, 'the server answered, so it is alive');
    }

    /**
     * TOOL EXECUTION, honestly reported. An image or an embedded resource has
     * no representation in a tool result here — but the caller is told what was
     * dropped instead of receiving a partial answer that reads like a whole one
     * (ADR-161).
     *
     * The note LEADS the answer. {@see ToolResultBounder} cuts a tool result to
     * a byte bound before the model sees it, so a trailing note is removed by
     * exactly the cut that makes the answer partial.
     */
    #[Test]
    public function namesTheContentBlocksItCouldNotCarryBeforeTheAnswerItself(): void
    {
        // Long enough to be cut on the way to the model, which is the case the
        // note is for: a partial answer the model cannot recognise as partial.
        $answer = str_repeat('the chart shows a number. ', 3000);

        $fake = $this->connection()->scriptedServer()->willReturn(['content' => [
            ['type' => 'text', 'text' => $answer],
            ['type' => 'image', 'data' => 'iVBORw0KGgo='],
            ['type' => 'resource', 'resource' => ['uri' => 'file:///x']],
            ['type' => 'image', 'data' => 'iVBORw0KGgo='],
        ]]);

        $result = $this->toolFor($fake)->execute([], ToolExecutionContext::none());

        self::assertStringStartsWith('[nr_llm reads text only', $result->content);
        self::assertStringContainsString('dropped 3 non-text content blocks', $result->content);
        self::assertStringContainsString('image, resource', $result->content);
        self::assertStringEndsWith($answer, $result->content);

        $bounded = (new ToolResultBounder())->content($result->content);

        self::assertStringContainsString('truncated at', $bounded, 'this answer really is cut');
        self::assertStringStartsWith('[nr_llm reads text only', $bounded, 'and the note survives the cut');
    }

    // -- timeouts ----------------------------------------------------------

    /**
     * TIMEOUTS. An agent run waits on the call synchronously while a backend
     * user watches, so an unreachable server has to give up while the answer is
     * still worth having.
     *
     * Asserted against the client the transport actually builds, not against a
     * constant: the PSR-18 test seam every other check here uses bypasses that
     * construction by design, so this one reaches the private builder directly.
     * Reading a constant would prove it exists, not that it is put on the
     * client.
     */
    #[Test]
    public function putsAFiniteTimeoutOnTheClientItBuilds(): void
    {
        $recording = new RecordingVaultHttpClient();
        $vault     = self::createStub(VaultServiceInterface::class);
        $vault->method('http')->willReturn($recording);

        $transport = new McpHttpTransport(
            $vault,
            $this->createSecureHttpClientFactoryMock(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );

        (new ReflectionMethod(McpHttpTransport::class, 'clientFor'))
            ->invoke(
                $transport,
                $this->connection()->server(),
                McpOperationDeadline::start(new FakeMcpClock(), 20)->legTimeoutSeconds(),
            );

        self::assertIsInt($recording->timeoutSeconds);
        self::assertGreaterThan(0, $recording->timeoutSeconds, 'an unbounded call would hang the run');
        self::assertLessThanOrEqual(30, $recording->timeoutSeconds, 'and one nobody waits out is no bound');
        self::assertIsString($recording->reason);
        self::assertStringContainsString($this->connection()->identifier, $recording->reason);
    }

    /**
     * TIMEOUTS, per OPERATION rather than per request (ADR-170).
     *
     * An operation over this connection is several round trips — the handshake,
     * its confirmation, then the request that carries the work — and a bound
     * that applied to each of them separately multiplied by however many legs
     * the protocol happened to need. One budget is opened per operation, and
     * each leg is granted what the earlier ones left.
     *
     * Driven through the vault path on purpose: the PSR-18 seam bypasses the
     * one place a timeout is applied, so a check on the seam could not see what
     * a leg was granted.
     */
    #[Test]
    public function spendsOneBudgetAcrossTheLegsOfAnOperation(): void
    {
        $clock = new FakeMcpClock();
        $vault = new SlowVaultHttpClient(
            $this->connection()->scriptedServer()->willReturn(['content' => [['type' => 'text', 'text' => 'ok']]]),
            $clock,
            [5.0, 1.0, 2.0],
        );

        $this->vaultBackedClientFor($vault, $clock)->callTool($this->connection()->server(), 'read_page', []);

        self::assertSame(
            [20, 15, 14],
            $vault->grantedTimeouts,
            'the handshake spent six seconds, so the tool call gets fourteen — not a fresh budget',
        );
    }

    /**
     * TIMEOUTS, exhausted. A budget that runs out is its own outcome and says
     * so: nothing was sent, so this is not a far side that failed to answer,
     * and nothing was aborted, so it is not a cancellation either (`#774`).
     */
    #[Test]
    public function anExhaustedBudgetIsItsOwnOutcomeAndNotAServerFailure(): void
    {
        $clock  = new FakeMcpClock();
        $server = $this->connection()->scriptedServer()->willReturn(['content' => []]);
        $vault  = new SlowVaultHttpClient($server, $clock, [11.0, 10.0]);

        try {
            $this->vaultBackedClientFor($vault, $clock)->callTool($this->connection()->server(), 'read_page', []);
            self::fail('a spent budget should have refused the tool call');
        } catch (McpTransportException $e) {
            self::assertSame(1799990217, $e->getCode(), 'not the generic transport-failure code');
            self::assertStringContainsString('operation budget', $e->getMessage());
            self::assertStringContainsString('"' . $this->connection()->identifier . '"', $e->getMessage());
            self::assertStringContainsString('not a server that did not answer', $e->getMessage());
            self::assertStringNotContainsString('cancel', strtolower($e->getMessage()));
        }

        self::assertSame(
            ['initialize', 'notifications/initialized'],
            $server->methods(),
            'the refused leg never went on the wire',
        );
        self::assertSame([], $this->health->contacts);
    }

    /**
     * TIMEOUTS, floored. `withTimeout()` treats a non-positive value as "no
     * override" and falls back to TYPO3's `HTTP.timeout`, whose default is `0`
     * — which Guzzle reads as *wait forever*. So the leg with the least budget
     * left is exactly the leg that must never be handed zero.
     */
    #[Test]
    public function neverGrantsALegATimeoutThatWouldMeanNoTimeout(): void
    {
        $clock = new FakeMcpClock();
        $vault = new SlowVaultHttpClient(
            $this->connection()->scriptedServer()->willReturn(['content' => []]),
            $clock,
            [18.5, 1.2],
        );

        $this->vaultBackedClientFor($vault, $clock)->callTool($this->connection()->server(), 'read_page', []);

        self::assertSame([20, 2, 1], $vault->grantedTimeouts);

        foreach ($vault->grantedTimeouts as $granted) {
            self::assertGreaterThan(0, $granted, 'a zero here is an unbounded request, not a tight one');
        }
    }

    /**
     * TIMEOUTS, from the caller's side. A connection that dies — refused,
     * reset, timed out — is a fact about this call, not a fault in the run: the
     * loop carries on and the model is told what failed.
     */
    #[Test]
    public function aConnectionThatNeverAnswersBecomesAFailedToolResult(): void
    {
        $dead = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('cURL error 28: Operation timed out', 1799990250);
            }
        };

        $result = $this->toolFor($dead)->execute([], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertStringContainsString('"' . $this->connection()->identifier . '"', $result->content);
    }

    // -- cancellation ------------------------------------------------------

    /**
     * CANCELLATION — a gap, pinned by its absence (ADR-161).
     *
     * `AgentRuntime::cancel()` flips persisted state and the loop notices at
     * the NEXT step boundary. Since ADR-190 a call already in flight is told
     * too: a signal travels from the tool call into the transport, and
     * nr-vault tears the socket down when it turns true.
     *
     * Still structural rather than behavioural, and for the same reason as when
     * this asserted the empty list. The behavioural version — a fake client
     * running a closure mid-request — raises nothing cancellable, so it would
     * pass whatever the code did. What is worth pinning is WHERE a cancellation
     * may enter, and the answer is three places: the tool call, and the two
     * transport sends the handshake and the call itself use. A fourth seam is a
     * way in that nothing decided, and this check fails the day one appears,
     * exactly as it failed the day the first three did.
     *
     * The behaviour itself is covered where it can actually be exercised:
     * `McpHttpTransportTest` drives both sends against a client that implements
     * nr-vault's cancellable interface, and `McpToolTest` follows the chain from
     * the tool's execution context to the wire.
     */
    #[Test]
    public function cancellationEntersThroughExactlyTheTwoSeamsAdr190Names(): void
    {
        $seams = [];

        foreach ([McpClient::class, McpHttpTransport::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($this->readsAsCancellation($method->getName())) {
                    $seams[] = $class . '::' . $method->getName() . '()';
                }

                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();

                    if ($this->readsAsCancellation($parameter->getName() . ' ' . ($type?->__toString() ?? ''))) {
                        $seams[] = $class . '::' . $method->getName() . '($' . $parameter->getName() . ')';
                    }
                }
            }
        }

        self::assertSame(
            [
                McpClient::class . '::callTool($cancellation)',
                McpHttpTransport::class . '::call($cancellation)',
                McpHttpTransport::class . '::notify($cancellation)',
            ],
            $seams,
            'ADR-190 names the three places a cancellation may enter this path — the tool call and the '
            . 'two transport sends it drives — and no others. A seam anywhere else is a second way in that '
            . 'nothing decided, which is what this check has always been for; it merely asserted the '
            . 'empty list while the gap was open',
        );
    }

    private function readsAsCancellation(string $subject): bool
    {
        return preg_match('/cancel|abort/i', $subject) === 1;
    }

    // -- server failure ----------------------------------------------------

    /**
     * @return array<string, array{string, int, string}> body, status, content type
     */
    public static function serverFailures(): array
    {
        return [
            'a server error'                => ['{}', 500, 'application/json'],
            'a redirect we refuse to follow' => ['{}', 302, 'application/json'],
            'an authentication refusal'     => ['{}', 401, 'application/json'],
            'a JSON-RPC error object'       => ['{"jsonrpc":"2.0","id":1,"error":{"code":-32601,"message":"Method not found"}}', 200, 'application/json'],
            'a maintenance page'            => ['<html>maintenance</html>', 200, 'text/html'],
            'an event stream with no message' => [": ping\n\n", 200, 'text/event-stream'],
            'an event stream carrying only a server notification' => ["data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/message\",\"params\":{}}\n\n", 200, 'text/event-stream'],
            'a body with neither result nor error' => ['{"jsonrpc":"2.0","id":1}', 200, 'application/json'],
            'an empty body'                 => ['', 200, 'application/json'],
        ];
    }

    /**
     * SERVER FAILURE. Every shape of "the far side did not answer usefully"
     * ends the same way: a failed tool result naming the server, never an
     * exception escaping into the run and never a success the audit would
     * record as one.
     */
    #[Test]
    #[DataProvider('serverFailures')]
    public function everyServerFailureBecomesAFailedToolResult(string $body, int $status, string $contentType): void
    {
        $fake = $this->connection()->scriptedServer()->willReturnRaw($body, $status, $contentType);

        $result = $this->toolFor($fake)->execute([], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertStringContainsString('"' . $this->connection()->identifier . '"', $result->content);
        self::assertSame([], $result->artifacts, 'a failed remote call carries nothing run-scoped');
        self::assertSame([], $this->health->contacts, 'a server that did not answer was not contacted');
    }

    // -- oversized response ------------------------------------------------

    /**
     * OVERSIZED RESPONSE. The read is capped, so the body a hostile or broken
     * server can push into memory is bounded — and a truncated body is refused
     * rather than parsed for whatever survived the cut.
     */
    #[Test]
    public function refusesAResponseLargerThanTheReadCap(): void
    {
        $oversized = '{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"'
            . str_repeat('a', 3 * 1024 * 1024)
            . '"}]}}';

        $fake = $this->connection()->scriptedServer()->willReturnRaw($oversized);

        $result = $this->toolFor($fake)->execute([], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertStringContainsString('"' . $this->connection()->identifier . '"', $result->content);
        self::assertLessThan(
            1000,
            strlen($result->content),
            'and what the far side sent does not become the message we repeat',
        );
    }

    // -- audit -------------------------------------------------------------

    /**
     * AUDIT. Every remote call is audit-critical BY DECLARATION, and that is
     * the whole mechanism (ADR-161).
     *
     * {@see McpTool::getEffect()} is a non-idempotent write for every imported
     * tool, a pure search included, because a remote body is not ours to
     * inspect. {@see ToolEffectResolver} is what the runtime asks before it
     * executes a tool and after it recorded the step, so this one declaration
     * is what arms the ADR-141 fence (no persisted, leased segment ⇒ the call
     * is refused before it happens) and the ADR-111 fail-closed audit (the step
     * cannot be dropped on a store hiccup).
     *
     * That the fence and the audit then actually fire for an MCP tool is a
     * claim about database rows, and is asserted in
     * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp\McpRunAuditTest}.
     */
    #[Test]
    public function everyRemoteCallIsAuditCriticalByDeclaration(): void
    {
        $tool     = $this->toolFor(new McpTestServer());
        $resolver = new ToolEffectResolver(new ToolRegistry([$tool]));

        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $resolver->effectForTool($tool));
        self::assertTrue($resolver->effectForTool($tool)->isWrite(), 'the fence and the fail-closed audit both key on this');
        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $resolver->effectFor($tool->getSpec()->name));
    }

    // -- data classification -----------------------------------------------

    /**
     * DATA CLASSIFICATION. The class and the approval requirement travel on the
     * tool itself, so the gate reads them without a second lookup, and nothing
     * the server sent is consulted to produce either.
     *
     * This pins the tool's declaration. That the declaration is resolved from
     * the operator's server row rather than from the annotations the server
     * wrote about itself happens in
     * {@see \Netresearch\NrLlm\Service\Tool\Mcp\McpToolProvider} and is
     * asserted over real rows in
     * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp\McpImportServiceTest}.
     */
    #[Test]
    public function theDataClassIsTheOperatorsAndTheServerCannotMoveIt(): void
    {
        $tool = $this->toolFor(new McpTestServer(), remoteAnnotations: '{"readOnlyHint":true,"dataClass":"publicContent"}');

        self::assertSame($this->connection()->dataClass, $tool->getDataClass());
        self::assertSame($this->connection()->requiresApproval, $tool->requiresApproval());
    }

    // -- trust-zone enforcement --------------------------------------------

    /**
     * TRUST-ZONE ENFORCEMENT, from the side this pack owns.
     *
     * The gate's own branch — a {@see RemoteToolInterface} tool is refused
     * above the trust-zone ceiling even in `observe` mode — is asserted in
     * `ToolCallPolicyTest` (ADR-154). What has to hold here is that an MCP tool
     * presents itself to that gate as the thing the branch keys on, with the
     * classification the gate compares and the admin requirement it applies
     * first. All four are declarations; none is derived from anything the
     * server sent.
     */
    #[Test]
    public function presentsItselfToTheGateAsARemoteToolWithAnOperatorDeclaredClass(): void
    {
        $tool = $this->toolFor(new McpTestServer());

        self::assertInstanceOf(RemoteToolInterface::class, $tool);
        self::assertInstanceOf(RemoteApprovalInterface::class, $tool);
        self::assertTrue($tool->requiresAdmin(), 'the server is the party the guard exists against');
        self::assertFalse($tool->isEnabledByDefault(), 'importing a catalogue is not granting it');
        self::assertSame('mcp_' . $this->connection()->identifier, $tool->getGroup());
    }

    // -- health ------------------------------------------------------------

    /**
     * HEALTH (ADR-154). One contact per completed OPERATION, whatever the
     * number of round trips it took — and none at all when the operation did
     * not complete, because half a catalogue walk is not an answer.
     */
    #[Test]
    public function recordsOneContactPerCompletedOperationAndNoneForAFailedOne(): void
    {
        $paged = $this->connection()->scriptedServer()
            ->willReturn(['tools' => [], 'nextCursor' => 'p2'])
            ->willReturn(['tools' => []]);

        $this->clientFor($paged)->listTools($this->connection()->server());

        self::assertCount(1, $this->health->contacts);
        self::assertSame($this->connection()->identifier, $this->health->contacts[0]['identifier']);

        $broken = $this->connection()->scriptedServer()->willReturn(['no tools key' => true]);

        try {
            $this->clientFor($broken)->listTools($this->connection()->server());
            self::fail('a listing without a tools array should have been refused');
        } catch (McpTransportException) {
            // expected — the count below is the point.
        }

        self::assertCount(1, $this->health->contacts, 'the failed walk added nothing');
    }

    // -- wiring ------------------------------------------------------------

    protected function clientFor(ClientInterface $http): McpClient
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            $this->createSecureHttpClientFactoryMock(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($http);

        return new McpClient($transport, $this->health, $this->deadlineFactory(new FakeMcpClock()));
    }

    /**
     * A client that reaches its server through the VAULT path, which is the
     * only path on which the per-leg timeout is applied (ADR-170).
     */
    protected function vaultBackedClientFor(SlowVaultHttpClient $vault, FakeMcpClock $clock): McpClient
    {
        $vaultService = self::createStub(VaultServiceInterface::class);
        $vaultService->method('http')->willReturn($vault);

        $transport = new McpHttpTransport(
            $vaultService,
            // No DNS, so the SSRF host gate answers without a network call.
            new SecureHttpClientFactory(new class implements DnsResolverInterface {
                public function resolve(string $host): array
                {
                    return [];
                }
            }),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );

        return new McpClient($transport, $this->health, $this->deadlineFactory($clock));
    }

    /**
     * Nothing configured, so every check runs against the budget a fresh
     * installation actually gets.
     */
    private function deadlineFactory(FakeMcpClock $clock): McpDeadlineFactory
    {
        return new McpDeadlineFactory($clock, self::createStub(ExtensionConfiguration::class));
    }

    protected function toolFor(ClientInterface $http, string $remoteAnnotations = ''): McpTool
    {
        return new McpTool(
            $this->connection()->server(),
            $this->record(remoteAnnotations: $remoteAnnotations),
            ['type' => 'object', 'properties' => ['uid' => ['type' => 'integer']]],
            $this->connection()->dataClass,
            $this->connection()->requiresApproval,
            $this->clientFor($http),
        );
    }

    private function record(string $inputSchema = '{"type":"object","properties":{}}', string $remoteAnnotations = ''): McpToolRecord
    {
        $localName = (new McpToolNameMapper())->localName($this->connection()->identifier, 'read_page');
        self::assertIsString($localName, 'the profile identifier must produce a representable local tool name');

        return new McpToolRecord(
            uid: 7,
            pid: 0,
            server: 1,
            toolName: $localName,
            remoteName: 'read_page',
            description: 'Reads a page',
            inputSchema: $inputSchema,
            remoteAnnotations: $remoteAnnotations,
            orphaned: false,
            tstamp: 0,
            crdate: 0,
        );
    }

    /**
     * A schema nested $levels deep in PHP array levels, used to walk past the
     * normaliser's depth cap.
     *
     * @return array<string, mixed>
     */
    private static function nestedSchema(int $levels): array
    {
        $node = ['type' => 'string'];
        for ($level = 0; $level < $levels; ++$level) {
            $node = ['type' => 'object', 'properties' => ['deeper' => $node]];
        }

        return $node;
    }
}
