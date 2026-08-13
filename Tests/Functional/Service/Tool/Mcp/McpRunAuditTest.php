<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\McpToolRecord;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\Exception\WriteWithoutDurableExecutionException;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpTool;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\RecordedContacts;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\ScriptedToolAdapter;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * The audit half of the MCP conformance pack (ADR-161).
 *
 * The unit suite can show that {@see McpTool} DECLARES itself a non-idempotent
 * write, which is the input the runtime's fence and its fail-closed audit key
 * on. Whether a row then appears in `tx_nrllm_agentrun_event` is a claim about
 * a database, so it is asserted here, against the real persister, the real
 * repository and the real agent runtime — with only the provider and the HTTP
 * client faked.
 *
 * Four statements, and the second is the load-bearing one:
 *
 * 1. an executed remote call becomes a TOOL event naming the local tool;
 * 2. a segment that cannot audit the call does not make it AT ALL — the fence
 *    refuses before the request goes out, so "an MCP tool ran unaudited" is not
 *    a state this codebase can reach through the runtime;
 * 3. a call the SERVER could not answer is audited AS a failure, not as a
 *    successful step whose content happens to be an error sentence;
 * 4. and so is a call the server answered with the protocol's own `isError` —
 *    which is the common failure, and the one a string search over content was
 *    really being asked to find.
 */
#[CoversClass(McpTool::class)]
#[CoversClass(AgentRunPersister::class)]
final class McpRunAuditTest extends AbstractFunctionalTestCase
{
    use LlmServiceManagerTestFactory;

    /** The run owner: an administrator, because every MCP tool requires one. */
    private const OWNER = 1;

    private const SERVER = 'audit';

    private const TOOL = 'mcp_audit_read_page';

    private ConnectionPool $connectionPool;

    private LlmConfiguration $configuration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->getService(ConnectionPool::class);
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(self::OWNER);

        $this->configuration = $this->localConfiguration();
    }

    /**
     * The remote call leaves a row. Not "a log line was written" — the event
     * stream an operator reads back names the tool that ran.
     */
    #[Test]
    public function anExecutedRemoteCallBecomesAToolEventInTheRunsAuditStream(): void
    {
        $server = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['content' => [['type' => 'text', 'text' => 'page 1 reads: hello']]]);

        $runtime = $this->runtime($server);
        $result  = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string)$result->error?->getMessage());
        self::assertSame('tools/call', $server->methods()[2] ?? null, 'the call actually went out');

        $tool = $this->firstEventOfKind($result->runUuid, AgentEventKind::TOOL);
        self::assertSame(self::TOOL, $tool->payload['toolName'] ?? null);
        self::assertNotTrue($tool->payload['toolIsError'] ?? null);
    }

    /**
     * The fence (ADR-141), read through the MCP client.
     *
     * Every imported tool declares a non-idempotent write, so a segment holding
     * no persisted run cannot fence it — and the call is refused BEFORE it
     * happens. That is what makes "an MCP tool executed and nothing was
     * recorded" unreachable: not a rule about writing rows after the fact, but
     * a refusal to make the call without a row to write against.
     */
    #[Test]
    public function aRunThatCannotBeAuditedNeverReachesTheServer(): void
    {
        $server = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['content' => [['type' => 'text', 'text' => 'this must never be produced']]]);

        // The run row cannot be written, so `begin()` yields no handle and the
        // interactive path passes no lease owner — the two things the fence
        // needs.
        $unstartable = self::createStub(AgentRunRepositoryInterface::class);
        $unstartable->method('startRun')->willThrowException(
            new RuntimeException('the run row could not be written', 1799990260),
        );

        $result = $this->runtime($server, $unstartable)->run($this->request());

        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertInstanceOf(WriteWithoutDurableExecutionException::class, $result->error);
        self::assertSame([], $server->received, 'the refusal happened before anything went on the wire');
    }

    /**
     * A call the server could not answer is audited as a failed step (ADR-161).
     *
     * `isError` is what the persisted step carries and what a reader counts. A
     * transport failure returned as ordinary text would be stored as a
     * SUCCESSFUL tool step whose content happens to read like an error, and
     * "how often does this server fail" would have no answer that does not
     * involve parsing prose.
     */
    #[Test]
    public function anUnreachableServerIsAuditedAsAFailedStep(): void
    {
        $server = (new McpTestServer())
            ->willHandshake()
            ->willReturnRaw('{}', 503);

        $result = $this->runtime($server)->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, 'a server that is down is not a failed run');

        $tool = $this->firstEventOfKind($result->runUuid, AgentEventKind::TOOL);
        self::assertSame(self::TOOL, $tool->payload['toolName'] ?? null);
        self::assertTrue($tool->payload['toolIsError'] ?? null);
    }

    /**
     * And so is the failure a WORKING server reports (ADR-161).
     *
     * MCP puts a tool-level failure inside a successful JSON-RPC response, as
     * `isError` on the result. It is the ordinary way a remote tool says it
     * failed — the page was missing, the query was rejected — and it is far
     * more common than the server being down. Storing it as a successful step
     * is the same defect as storing a transport failure that way, on the more
     * frequent path.
     */
    #[Test]
    public function aToolLevelErrorFromAWorkingServerIsAuditedAsAFailedStep(): void
    {
        $server = (new McpTestServer())
            ->willHandshake()
            ->willReturn([
                'isError' => true,
                'content' => [['type' => 'text', 'text' => 'page 1 does not exist']],
            ]);

        $result = $this->runtime($server)->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string)$result->error?->getMessage());
        self::assertSame('tools/call', $server->methods()[2] ?? null, 'the call did go out');

        $tool = $this->firstEventOfKind($result->runUuid, AgentEventKind::TOOL);
        self::assertSame(self::TOOL, $tool->payload['toolName'] ?? null);
        self::assertTrue($tool->payload['toolIsError'] ?? null);
    }

    // --- wiring -----------------------------------------------------------

    /**
     * The real runtime over a real persister, with the provider and the HTTP
     * client faked. The middleware pipeline is real but empty, as in
     * {@see \Netresearch\NrLlm\Tests\Functional\Service\Agent\WritePathAcceptanceTest}.
     */
    private function runtime(ClientInterface $http, ?AgentRunRepositoryInterface $repository = null): AgentRuntime
    {
        $registry = new ToolRegistry([$this->mcpTool($http)]);
        (new ToolStateRepository($this->connectionPool))->setEnabled(self::TOOL, true);

        $adapterRegistry = self::createStub(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn(
            new ScriptedToolAdapter('The page was read.', self::TOOL, ['uid' => 1]),
        );

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        $policy = new ToolCallPolicy(
            $registry,
            new ToolAvailabilityService(
                $registry,
                new ToolStateRepository($this->connectionPool),
                new ToolGroupStateRepository($this->connectionPool),
            ),
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        $loop = new ToolLoopService(
            $this->createLlmServiceManager(
                $extensionConfiguration,
                new NullLogger(),
                $adapterRegistry,
                new MiddlewarePipeline([]),
                self::createStub(CacheManagerInterface::class),
            ),
            $registry,
            $policy,
            new NullLogger(),
        );

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($this->configuration);

        return new AgentRuntime(
            toolLoop: $loop,
            persister: $this->persister($repository),
            configurationRepository: $configurationRepository,
            logger: new NullLogger(),
            actingBackendUserResolver: new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($registry),
            toolPolicy: $policy,
        );
    }

    /**
     * One imported tool of one server, built exactly as
     * {@see \Netresearch\NrLlm\Service\Tool\Mcp\McpToolProvider} builds it — the
     * catalogue tables are not involved, because what is under test is the run,
     * not the import.
     */
    private function mcpTool(ClientInterface $http): McpTool
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            new SecureHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($http);

        return new McpTool(
            McpTestServer::server(self::SERVER, ToolDataClass::PUBLIC_CONTENT->value, '0'),
            new McpToolRecord(
                uid: 1,
                pid: 0,
                server: 1,
                toolName: self::TOOL,
                remoteName: 'read_page',
                description: 'Reads a page',
                inputSchema: '{"type":"object","properties":{"uid":{"type":"integer"}}}',
                remoteAnnotations: '',
                orphaned: false,
                tstamp: 0,
                crdate: 0,
            ),
            ['type' => 'object', 'properties' => ['uid' => ['type' => 'integer']]],
            ToolDataClass::PUBLIC_CONTENT,
            // The operator released this server from the approval requirement,
            // so the first pass executes the call instead of suspending — which
            // is the path whose audit this class is about.
            false,
            new McpClient($transport, new RecordedContacts(), $this->get(McpDeadlineFactory::class)),
        );
    }

    private function persister(?AgentRunRepositoryInterface $repository = null): AgentRunPersister
    {
        return new AgentRunPersister(
            $repository ?? new AgentRunRepository($this->connectionPool, $this->getService(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
    }

    private function request(): AgentRunRequest
    {
        return new AgentRunRequest(
            $this->configuration,
            [ChatMessage::user('Read page 1 through the external server.')],
            AiActorContext::backendUser(self::OWNER, isAdmin: true),
        );
    }

    /**
     * A configuration in the LOCAL trust zone, so the zone ceiling permits the
     * server's declared class and the run is about the audit rather than about
     * data classes.
     */
    private function localConfiguration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setIdentifier('fake-provider');
        $provider->setAdapterType('openai');
        $provider->setTrustZoneEnum(TrustZone::LOCAL);
        $provider->setApiKey('nr_mcp_audit_vault_key');

        $model = new Model();
        $model->setModelId('scripted-model');
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('cfg-mcp-audit');
        $configuration->setLlmModel($model);

        return $configuration;
    }

    private function firstEventOfKind(string $runUuid, AgentEventKind $kind): AgentRunEvent
    {
        $persister = $this->persister();
        $run       = $persister->findRun($runUuid);
        self::assertNotNull($run, 'the run must have been persisted');

        foreach ($persister->findEvents($run->uid) as $event) {
            if ($event->kindEnum() === $kind) {
                return $event;
            }
        }

        self::fail(sprintf('The run recorded no %s event', $kind->value));
    }
}
