<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Domain\ValueObject\McpToolRecord;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Builtin\FetchLogsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\McpTool;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * End-to-end agent loop over a REAL builtin tool.
 *
 * The unit {@see \Netresearch\NrLlm\Tests\Unit\Service\Tool\ToolLoopServiceTest}
 * drives the loop with an in-memory FakeTool and a hand-written availability
 * double. Here the loop runs the actual admin-curated {@see FetchLogsTool}
 * through the REAL {@see ToolRegistry} and the REAL DB-backed
 * {@see ToolAvailabilityService} (its `tx_nrllm_tool_state` overrides), with the
 * acting admin resolved from `$GLOBALS['BE_USER']`. Only the LLM manager is
 * stubbed (it scripts one tool call followed by a final answer — no network);
 * everything from the availability gate through the admin gate down into
 * `FetchLogsTool::execute()` reading real `sys_log` rows is production code, and
 * the trace is asserted to carry the tool's REAL formatted output.
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceBuiltinTest extends AbstractFunctionalTestCase
{
    private ConnectionPool $connectionPool;

    /** @var BackendUserAuthentication|object|null */
    private mixed $beUserBackup = null;

    /**
     * The admin backend user the tool loop authorises against in setUp; the same
     * object is threaded into the run's {@see ToolExecutionContext} so the REAL
     * admin gate reads it exactly as it read the ambient `$GLOBALS['BE_USER']`.
     */
    private BackendUserAuthentication $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        // FetchLogsTool is admin-only; the runtime RBAC gate reads the acting
        // backend user from $GLOBALS['BE_USER'] and fails closed when absent.
        $this->beUserBackup = $GLOBALS['BE_USER'] ?? null;
        $admin              = new BackendUserAuthentication();
        $admin->user        = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $admin;
        $this->actingUser   = $admin;
    }

    protected function tearDown(): void
    {
        $GLOBALS['BE_USER'] = $this->beUserBackup;
        parent::tearDown();
    }

    #[Test]
    public function realFetchLogsToolExecutesThroughLoopAndTraceRecordsItsOutput(): void
    {
        $this->importFixture('sys_log_tools.csv');

        // Script the LLM: round 1 asks for the tool, round 2 (after the real tool
        // result is fed back) gives the final answer.
        $queue = [
            $this->response('', [new ToolCall('call_1', 'fetch_logs', ['limit' => 2])]),
            $this->response('Here is a summary of the recent logs.'),
        ];
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback(function () use (&$queue): CompletionResponse {
                $next = array_shift($queue);
                if (!$next instanceof CompletionResponse) {
                    throw new RuntimeException('Scripted response queue underflow.', 1799990001);
                }

                return $next;
            });

        $service = $this->buildService($mgr);
        $result  = $service->runLoop([$this->userTurn('show me the logs')], $this->localConfiguration(), $this->contextFor($this->actingUser), null);

        // The real builtin tool ran exactly once and its REAL output is in the trace.
        self::assertCount(1, $result->trace);
        self::assertSame('fetch_logs', $result->trace[0]->name);
        self::assertFalse($result->trace[0]->isError, 'the real tool executed without error');

        $toolOutput = $result->trace[0]->result;
        // FetchLogsTool formats newest-first; the limit=2 it was called with
        // surfaces the two newest fixture rows (see FetchLogsToolTest).
        self::assertStringContainsString('Cache cleared', $toolOutput);
        self::assertStringContainsString('Login failed', $toolOutput);
        // PII redaction in the real tool still holds end-to-end.
        self::assertStringNotContainsString('203.0.113.55', $toolOutput);

        self::assertSame('Here is a summary of the recent logs.', $result->finalContent);
        self::assertFalse($result->truncated);
    }

    #[Test]
    public function adminOnlyBuiltinToolIsNotOfferedToNonAdminEndToEnd(): void
    {
        // Flip the acting user to a non-admin: the REAL admin gate must drop the
        // admin-only FetchLogsTool, leaving no tools ⇒ a single plain completion,
        // and the LLM's tools path is never taken.
        $nonAdmin           = new BackendUserAuthentication();
        $nonAdmin->user     = ['uid' => 2, 'admin' => 0];
        $GLOBALS['BE_USER'] = $nonAdmin;

        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        $mgr->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->response('plain answer'));
        $mgr->expects(self::never())->method('chatWithToolsForConfiguration');

        $service = $this->buildService($mgr);
        // The caller even explicitly allows the tool — the admin gate still wins.
        $result = $service->runLoop([$this->userTurn('show me the logs')], $this->localConfiguration(), $this->contextFor($nonAdmin), ['fetch_logs']);

        self::assertSame('plain answer', $result->finalContent);
        self::assertSame([], $result->trace);
        self::assertSame(1, $result->iterations);
    }

    /**
     * A tool that declares a write and carries NO approval marker suspends the
     * run through the REAL gate stack (ADR-134). No builtin writes today — that
     * is what {@see ToolEffectCoverageTest} pins — so the first one that does is
     * modelled here rather than waited for: the guarantee has to hold on the day
     * it lands, not after someone remembers the marker.
     */
    #[Test]
    public function aWriteDeclaringToolWithoutTheMarkerSuspendsEndToEnd(): void
    {
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturn($this->response('', [new ToolCall('call_1', 'write_thing', ['id' => 7])]));

        $service = $this->buildService($mgr, [$this->writeTool()]);

        $this->expectException(ToolApprovalRequiredException::class);
        $service->runLoop([$this->userTurn('write it')], $this->localConfiguration(), $this->contextFor($this->actingUser), null);
    }

    /**
     * The same guarantee over the REAL first writing builtin (ADR-135).
     *
     * {@see UpdatePageMetadataTool} carries no approval marker; it declares
     * IDEMPOTENT_WRITE and nothing else. The loop must therefore suspend BEFORE
     * `execute()` runs — the whole point of coupling the two declarations is
     * that a writer cannot ship without the pause by forgetting the marker.
     */
    #[Test]
    public function theWritingBuiltinSuspendsBeforeItExecutes(): void
    {
        $tool = new UpdatePageMetadataTool($this->connectionPool);
        // It ships disabled, so the REAL availability service would not offer it.
        (new ToolStateRepository($this->connectionPool))->setEnabled('update_page_metadata', true);

        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturn($this->response('', [
                new ToolCall('call_1', 'update_page_metadata', ['uid' => 1, 'title' => 'Rewritten']),
            ]));

        $service = $this->buildService($mgr, [$tool]);

        $this->expectException(ToolApprovalRequiredException::class);
        $service->runLoop([$this->userTurn('fix the title')], $this->localConfiguration(), $this->contextFor($this->actingUser), null);
    }

    /**
     * The same guarantee over the SECOND writing builtin (ADR-135).
     *
     * The coupling is a property of the declaration, not of the first tool that
     * happened to carry it: `set_file_alternative_text` also declares only
     * IDEMPOTENT_WRITE and no approval marker, and the loop must suspend before
     * `execute()` — before any DataHandler, any storage gate, any file row.
     */
    #[Test]
    public function theSecondWritingBuiltinAlsoSuspendsBeforeItExecutes(): void
    {
        $tool = new SetFileAlternativeTextTool($this->connectionPool, $this->getService(FalStorageGate::class));
        // It ships disabled, so the REAL availability service would not offer it.
        (new ToolStateRepository($this->connectionPool))->setEnabled('set_file_alternative_text', true);

        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturn($this->response('', [
                new ToolCall('call_1', 'set_file_alternative_text', ['uid' => 1, 'alternative' => 'A cat']),
            ]));

        $service = $this->buildService($mgr, [$tool]);

        $this->expectException(ToolApprovalRequiredException::class);
        $service->runLoop([$this->userTurn('describe the image')], $this->localConfiguration(), $this->contextFor($this->actingUser), null);
    }

    /**
     * The remote exemption, over the REAL {@see McpTool} rather than a stand-in
     * for it (ADR-134), for a server whose operator did NOT set the approval
     * flag.
     *
     * `McpTool::getEffect()` is NON_IDEMPOTENT_WRITE for every imported tool,
     * this scripted `search` included. That value is a fail-closed assumption
     * about a body this codebase cannot inspect, not the tool's own statement,
     * so it must not double as an approval trigger: if it did, every MCP tool
     * would suspend on every call and the shipped client would be unusable.
     * The write effect is unchanged here — only the server's own flag decides.
     */
    #[Test]
    public function aRemoteMcpToolWithAWriteEffectDoesNotSuspendEndToEnd(): void
    {
        $tool = $this->mcpTool('mcp_srv_search', false);
        self::assertTrue($tool->getEffect()->isWrite(), 'the effect must still be a write, or this proves nothing');
        // An imported tool is inert until an operator enables it, so the real
        // availability service would otherwise never offer it.
        (new ToolStateRepository($this->connectionPool))->setEnabled('mcp_srv_search', true);

        $queue = [
            $this->response('', [new ToolCall('call_1', 'mcp_srv_search', ['q' => 'typo3'])]),
            $this->response('Found it.'),
        ];
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback(function () use (&$queue): CompletionResponse {
                $next = array_shift($queue);
                if (!$next instanceof CompletionResponse) {
                    throw new RuntimeException('Scripted response queue underflow.', 1799990002);
                }

                return $next;
            });

        $service = $this->buildService($mgr, [$tool]);
        $result  = $service->runLoop([$this->userTurn('search for typo3')], $this->localConfiguration(), $this->contextFor($this->actingUser), null);

        // It ran synchronously — no suspension — and the remote text came back
        // through the real client and transport.
        self::assertSame('Found it.', $result->finalContent);
        self::assertCount(1, $result->trace);
        self::assertSame('mcp_srv_search', $result->trace[0]->name);
        self::assertSame('REMOTE OK', $result->trace[0]->result);
    }

    /**
     * The other half of the same switch: the operator ticked "requires
     * approval" on the server, so the very same {@see McpTool} suspends
     * before the call goes out (ADR-134).
     *
     * The tool, the client and the transport are identical to the test above —
     * only the server row differs. That is the point: nothing about the tool
     * itself, and certainly nothing the server said about itself, decides this.
     */
    #[Test]
    public function aRemoteMcpToolOnAnApprovalRequiringServerSuspendsEndToEnd(): void
    {
        $tool = $this->mcpTool('mcp_srv_search', true);
        (new ToolStateRepository($this->connectionPool))->setEnabled('mcp_srv_search', true);

        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturn($this->response('', [new ToolCall('call_1', 'mcp_srv_search', ['q' => 'typo3'])]));

        $service = $this->buildService($mgr, [$tool]);

        $this->expectException(ToolApprovalRequiredException::class);
        $service->runLoop([$this->userTurn('search for typo3')], $this->localConfiguration(), $this->contextFor($this->actingUser), null);
    }

    /**
     * A tool that declares a write effect and nothing else — no approval marker,
     * no remote provenance.
     */
    private function writeTool(): ToolInterface
    {
        return new class implements ToolInterface, ToolEffectInterface {
            public function getEffect(): ToolEffect
            {
                return ToolEffect::NON_IDEMPOTENT_WRITE;
            }

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('write_thing', 'changes a thing', ['type' => 'object', 'properties' => []]);
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function execute(array $arguments, ToolExecutionContext $context): ToolResult
            {
                return ToolResult::text('WROTE');
            }

            public function isEnabledByDefault(): bool
            {
                return true;
            }

            public function requiresAdmin(): bool
            {
                return false;
            }

            public function getGroup(): string
            {
                return 'test';
            }
        };
    }

    /**
     * A real {@see McpTool} over a scripted server: only the HTTP client is
     * faked, so the client, the transport and the tool itself are production
     * code.
     *
     * The server record is read back from a real row through the real
     * {@see McpServerRepository}, so the approval flag travels the production
     * path — column, hydration, {@see McpServerRecord::approvalRequired()} —
     * rather than being asserted into place.
     */
    private function mcpTool(string $localName, bool $requiresApproval): McpTool
    {
        $server = $this->storedServer($requiresApproval);

        $record = new McpToolRecord(
            1,
            0,
            $server->uid,
            $localName,
            'search',
            'searches the server',
            '{"type":"object","properties":{}}',
            // The server calls itself read-only. No resolver reads this, and the
            // approval scan must not start doing so: a remote server cannot be
            // allowed to decide its own authorisation.
            '{"readOnlyHint":true}',
            false,
            0,
            0,
        );

        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['content' => [['type' => 'text', 'text' => 'REMOTE OK']]]);

        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            new SecureHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($fake);

        return new McpTool(
            $server,
            $record,
            ['type' => 'object', 'properties' => []],
            ToolDataClass::PUBLIC_CONTENT,
            $server->approvalRequired(),
            new McpClient($transport),
        );
    }

    /**
     * One MCP server row, read back as the repository hydrates it.
     */
    private function storedServer(bool $requiresApproval): McpServerRecord
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_nrllm_mcp_server');
        $connection->insert('tx_nrllm_mcp_server', [
            'pid'               => 0,
            'identifier'        => 'srv',
            'name'              => 'A server',
            'description'       => '',
            'url'               => 'https://mcp.example.com/rpc',
            'auth_credential'   => '',
            'auth_placement'    => 'bearer',
            'auth_header_name'  => '',
            'data_class'        => 'publicContent',
            'requires_approval' => $requiresApproval ? 1 : 0,
            'enabled'           => 1,
            'import_status'     => 'ok',
            'import_error'      => '',
            'last_imported'     => 1,
            'tool_count'        => 1,
            'tstamp'            => 0,
            'crdate'            => 0,
            'deleted'           => 0,
            'hidden'            => 0,
        ]);

        $server = (new McpServerRepository($this->connectionPool))->findByUid((int)$connection->lastInsertId());
        self::assertInstanceOf(McpServerRecord::class, $server);
        self::assertSame($requiresApproval, $server->approvalRequired());

        return $server;
    }

    /**
     * Wire the loop with the real registry + real DB-backed availability service
     * over the single real {@see FetchLogsTool}, or over the given tools.
     *
     * @param list<ToolInterface>|null $tools
     */
    private function buildService(LlmServiceManagerInterface $mgr, ?array $tools = null): ToolLoopService
    {
        $registry     = new ToolRegistry($tools ?? [new FetchLogsTool($this->connectionPool)]);
        $availability = new ToolAvailabilityService($registry, new ToolStateRepository($this->connectionPool), new ToolGroupStateRepository($this->connectionPool));

        // The REAL composite gate (ADR-094), not a double: this test exists to
        // run production code, and the gate is production code.
        $policy = new ToolCallPolicy(
            $registry,
            $availability,
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        return new ToolLoopService($mgr, $registry, $policy);
    }

    /**
     * A configuration whose provider sits in the LOCAL trust zone.
     *
     * `fetch_logs` is SYSTEM_DIAGNOSTICS by group, and a configuration without a
     * provider fails closed to EXTERNAL_GLOBAL, whose ceiling is EDITOR_CONTENT
     * — so the composite gate denies the tool there, correctly. The bare
     * `new LlmConfiguration()` these tests used was a placeholder that only ever
     * passed because no gate was wired; stating the zone says what they always
     * assumed, and lets the trust-zone axis genuinely run and allow.
     */
    private function localConfiguration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        return $configuration;
    }

    /**
     * Build the run's execution context from the same live backend user the REAL
     * admin gate authorises against, so the tool loop scopes exactly as it did
     * when it read the ambient `$GLOBALS['BE_USER']`.
     */
    private function contextFor(BackendUserAuthentication $user): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($user);
    }

    /**
     * @param list<ToolCall>|null $toolCalls
     */
    private function response(string $content, ?array $toolCalls = null): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: UsageStatistics::fromTokens(0, 0),
            toolCalls: $toolCalls,
        );
    }

    /**
     * @return array<string, string>
     */
    private function userTurn(string $content): array
    {
        return ['role' => 'user', 'content' => $content];
    }
}
