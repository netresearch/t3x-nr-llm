<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Domain\ValueObject\McpToolRecord;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\CorruptSuspendedStateException;
use Netresearch\NrLlm\Service\Agent\InputSubmission;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpAuthenticationSessionFactoryInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpCredentialSessionInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Service\Tool\Mcp\McpTool;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\RecordedContacts;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeInputTool;
use Netresearch\NrVault\Http\CancellationSignalInterface;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

#[CoversClass(AgentRuntime::class)]
#[CoversClass(McpClient::class)]
final class AgentRunDelegatedMcpTest extends AbstractFunctionalTestCase
{
    private AgentRunPersister $persister;

    private AgentStateCodec $codec;

    private Connection $connection;

    private LlmConfiguration $configuration;

    /** @var list<AiActorContext> */
    private array $actors = [];

    private int $closed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(2);
        $this->codec = $this->getService(AgentStateCodec::class);
        $pool = $this->getService(ConnectionPool::class);
        $this->connection = $pool->getConnectionForTable('tx_nrllm_agentrun');
        $this->persister = new AgentRunPersister(
            new AgentRunRepository($pool, $this->codec),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
        $this->configuration = new LlmConfiguration();
        $this->configuration->_setProperty('uid', 1);
    }

    #[Test]
    public function queuedBackendRunUsesItsOwnerUnderAnotherAmbientUser(): void
    {
        $runtime = $this->runtime();
        $uuid = $runtime->enqueue($this->request(AiActorContext::backendUser(1)));
        $queued = $this->storedRun($uuid);
        self::assertStringContainsString($uuid, $queued->queuedRequest ?? '');
        self::assertStringNotContainsString(
            'ephemeral-test',
            $queued->queuedRequest ?? '',
        );
        $result = $runtime->runQueued($uuid);
        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        self::assertSame(1, $this->capturedActor()->backendUserUid);
        self::assertSame(1, $this->closed);
    }

    #[Test]
    public function approvalRestoresTheOriginalServiceActor(): void
    {
        $runtime = $this->runtime(approval: true);
        $result = $runtime->run($this->request(AiActorContext::serviceAccount('indexer')));
        self::assertSame(AgentRunOutcome::AWAITING_APPROVAL, $result->outcome);
        self::assertSame([], $this->actors);
        $state = $this->state($result->runUuid);
        self::assertSame('indexer', $state->initiatingActor?->serviceAccount);
        self::assertSame($result->runUuid, $state->initiatingRunUuid);
        $resumed = $runtime->approve(
            $this->approver(),
            $result->runUuid,
            $this->decision($state),
        );
        self::assertSame(AgentRunOutcome::COMPLETED, $resumed->outcome);
        self::assertSame('indexer', $this->capturedActor()->serviceAccount);
        self::assertSame(0, $this->capturedActor()->backendUserUid);
        self::assertSame(1, $this->closed);
    }

    #[Test]
    public function inputResumeAlsoRetainsTheServiceActorForFollowingMcpCalls(): void
    {
        $runtime = $this->runtime(input: true);
        $result = $runtime->run(
            $this->request(AiActorContext::serviceAccount('input-worker')),
        );
        self::assertSame(AgentRunOutcome::AWAITING_INPUT, $result->outcome);
        $state = $this->state($result->runUuid);
        $resumed = $runtime->submitInput(
            $this->approver(),
            $result->runUuid,
            new InputSubmission(
                ['city' => 'Leipzig'],
                2,
                (new PendingTurnDigest())->forInputState($state),
            ),
        );
        self::assertSame(AgentRunOutcome::COMPLETED, $resumed->outcome);
        self::assertSame('input-worker', $this->capturedActor()->serviceAccount);
        self::assertSame(1, $this->closed);
    }

    #[Test]
    public function copyingCiphertextBetweenNewServiceQueuesCannotChangeTheActor(): void
    {
        $runtime = $this->runtime();
        $first = $runtime->enqueue(
            $this->request(AiActorContext::serviceAccount('first')),
        );
        $second = $runtime->enqueue(
            $this->request(AiActorContext::serviceAccount('second')),
        );
        $this->copyCiphertext($first, $second, 'queued_request');
        $result = $runtime->runQueued($second);
        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertSame([], $this->actors);
        self::assertSame(0, $this->closed);
    }

    #[Test]
    public function copyingSuspendedCiphertextBetweenServiceRunsIsRefusedBeforeClaim(): void
    {
        $runtime = $this->runtime(approval: true);
        $first = $runtime->run($this->request(AiActorContext::serviceAccount('first')));
        $second = $this
            ->runtime(approval: true)
            ->run($this->request(AiActorContext::serviceAccount('second')));
        $state = $this->state($first->runUuid);
        $this->copyCiphertext(
            $first->runUuid,
            $second->runUuid,
            'suspended_state',
        );
        $this->assertCorruptWaiting($runtime, $second->runUuid, $state);
    }

    #[Test]
    public function backendOwnerMismatchAndExplicitMalformedActorAreRefused(): void
    {
        foreach ([AiActorContext::backendUser(2)->toArray(), 'invalid', null] as $actor) {
            $runtime = $this->runtime(approval: true);
            $result = $runtime->run($this->request(AiActorContext::backendUser(1)));
            $state = $this->state($result->runUuid);
            $data = $state->toArray();
            $data['initiatingActor'] = $actor;
            if ($actor === null) {
                unset($data['initiatingRunUuid']);
            }

            $this->writeState($result->runUuid, $data);
            $this->assertCorruptWaiting($runtime, $result->runUuid, $state);
        }
    }

    #[Test]
    public function legacyUnboundBackendStatesRemainResumable(): void
    {
        $runtime = $this->runtime(approval: true);
        $result = $runtime->run($this->request(AiActorContext::backendUser(1)));
        $state = $this->state($result->runUuid);
        $data = $state->toArray();
        unset($data['initiatingActor'], $data['initiatingRunUuid']);
        $this->writeState($result->runUuid, $data);
        $resumed = $runtime->approve(
            $this->approver(),
            $result->runUuid,
            $this->decision($state),
        );
        self::assertSame(AgentRunOutcome::COMPLETED, $resumed->outcome);
        self::assertSame(1, $this->capturedActor()->backendUserUid);
    }

    private function storedRun(string $uuid): AgentRun
    {
        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        return $run;
    }

    private function state(string $uuid): SuspendedRunState
    {
        $data = json_decode(
            $this->storedRun($uuid)->suspendedState ?? '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($data);
        /** @var array<string, mixed> $data */
        return SuspendedRunState::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeState(string $uuid, array $data): void
    {
        $sealed = $this->codec->encode(
            json_encode($data, JSON_THROW_ON_ERROR),
            AgentStateCodec::PURPOSE_SUSPENDED_STATE,
        );
        $this->connection->update(
            'tx_nrllm_agentrun',
            ['suspended_state' => $sealed],
            ['uuid' => $uuid],
        );
    }

    private function copyCiphertext(
        string $from,
        string $to,
        string $column,
    ): void {
        $sealed = $this->connection
            ->select([$column], 'tx_nrllm_agentrun', ['uuid' => $from])
            ->fetchOne();
        self::assertIsString($sealed);
        self::assertStringNotContainsString('serviceAccount', $sealed);
        $this->connection->update(
            'tx_nrllm_agentrun',
            [$column => $sealed],
            ['uuid' => $to],
        );
    }

    private function assertCorruptWaiting(
        AgentRuntime $runtime,
        string $uuid,
        SuspendedRunState $state,
    ): void {
        try {
            $runtime->approve($this->approver(), $uuid, $this->decision($state));
            self::fail('A substituted actor or run binding must be refused.');
        } catch (CorruptSuspendedStateException) {
            self::assertSame(
                AgentRunStatus::WAITING_FOR_APPROVAL,
                $this->storedRun($uuid)->statusEnum(),
            );
            self::assertSame([], $this->actors);
        }
    }

    private function approver(): AiActorContext
    {
        return AiActorContext::backendUser(
            2,
            grants: [BackendUserGrant::AGENT_APPROVE],
        );
    }

    private function decision(SuspendedRunState $state): ApprovalDecision
    {
        return new ApprovalDecision(
            true,
            2,
            (new PendingTurnDigest())->forState($state),
        );
    }

    private function request(AiActorContext $actor): AgentRunRequest
    {
        return new AgentRunRequest(
            $this->configuration,
            [['role' => 'user', 'content' => 'run']],
            $actor,
        );
    }

    private function runtime(
        bool $approval = false,
        bool $input = false,
    ): AgentRuntime {
        $tool = $this->mcpTool($approval);
        $registry = new ToolRegistry($input ? [new FakeInputTool(), $tool] : [$tool]);
        $policy = $this->policy($input);
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $calls = [new ToolCall('mcp1', 'mcp_srv_run', [])];
        if ($input) {
            array_unshift($calls, new ToolCall('input1', 'ask_user', []));
        }

        $manager
            ->method('chatWithToolsForConfiguration')
            ->willReturnOnConsecutiveCalls(
                new CompletionResponse(
                    '',
                    'fake',
                    UsageStatistics::fromTokens(0, 0),
                    toolCalls: $calls,
                ),
                new CompletionResponse(
                    'complete',
                    'fake',
                    UsageStatistics::fromTokens(0, 0),
                ),
            );
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findByUid')->willReturn($this->configuration);
        $bus = self::createStub(MessageBusInterface::class);
        $bus
            ->method('dispatch')
            ->willReturnCallback(static fn(object $message): Envelope => new Envelope($message));
        return new AgentRuntime(
            new ToolLoopService($manager, $registry, $policy),
            $this->persister,
            $repository,
            new NullLogger(),
            $bus,
            actingBackendUserResolver: new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($registry),
            toolPolicy: $policy,
        );
    }

    private function policy(bool $input): ToolCallPolicyInterface
    {
        // Identity propagation is isolated here; the production composite gate's
        // permissions and its wiring have their own functional contract tests.
        $policy = self::createStub(ToolCallPolicyInterface::class);
        $policy
            ->method('skillAllowListForRun')
            ->willReturn(new SkillToolAllowList(null));
        $policy
            ->method('filterOfferable')
            ->willReturn($input ? ['ask_user', 'mcp_srv_run'] : ['mcp_srv_run']);
        $policy
            ->method('decide')
            ->willReturnCallback(
                static fn(
                    string $name,
                ): ToolPolicyDecision => new ToolPolicyDecision(
                    $name,
                    true,
                    ToolDataClass::PUBLIC_CONTENT,
                    TrustZone::LOCAL,
                    ToolDataClass::PUBLIC_CONTENT,
                ),
            );
        return $policy;
    }

    private function mcpTool(bool $approval): McpTool
    {
        $wire = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['content' => [['type' => 'text', 'text' => 'remote done']]]);
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            new SecureHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($wire);

        $factory = self::createStub(McpAuthenticationSessionFactoryInterface::class);
        $factory
            ->method('openForExecution')
            ->willReturnCallback(
                function (
                    McpServerRecord $server,
                    ?AiActorContext $actor,
                    McpOperationDeadline $deadline,
                    ?CancellationSignalInterface $cancellation = null,
                ): McpCredentialSessionInterface {
                    self::assertSame('delegated', $server->authMode);
                    self::assertNull($cancellation);
                    self::assertNotNull($actor);
                    self::assertFalse($deadline->isExhausted());
                    $this->actors[] = $actor;
                    $session = self::createMock(McpCredentialSessionInterface::class);
                    $session->method('credentialIdentifier')->willReturn(
                        'ephemeral-test',
                    );
                    $session->expects(self::once())->method('close')->willReturnCallback(
                        function (): void {
                            ++$this->closed;
                        },
                    );
                    return $session;
                },
            );
        $client = new McpClient(
            $transport,
            new RecordedContacts(),
            new McpDeadlineFactory(
                new FakeMcpClock(),
                self::createStub(ExtensionConfiguration::class),
            ),
            $factory,
        );
        $server = new McpServerRecord(
            1,
            0,
            'srv',
            'Server',
            '',
            'https://mcp.example.com',
            '',
            'bearer',
            '',
            'publicContent',
            $approval ? '1' : '0',
            true,
            'ok',
            '',
            0,
            1,
            0,
            0,
            0,
            0,
            'delegated',
            'test',
            'mcp',
            'read',
        );
        $record = new McpToolRecord(
            1,
            0,
            1,
            'mcp_srv_run',
            'run',
            '',
            '{"type":"object","properties":{}}',
            '',
            false,
            0,
            0,
        );
        return new McpTool(
            $server,
            $record,
            ['type' => 'object', 'properties' => []],
            ToolDataClass::PUBLIC_CONTENT,
            $approval,
            $client,
        );
    }

    #[Test]
    public function legacyQueueWithoutAnActorStillUsesItsStoredBackendOwner(): void
    {
        $runtime = $this->runtime();
        $uuid = $runtime->enqueue($this->request(AiActorContext::backendUser(1)));
        $data = json_decode(
            $this->storedRun($uuid)->queuedRequest ?? '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($data);
        unset($data['actor'], $data['initiatingRunUuid']);
        $sealed = $this->codec->encode(
            json_encode($data, JSON_THROW_ON_ERROR),
            AgentStateCodec::PURPOSE_QUEUED_REQUEST,
        );
        $this->connection->update(
            'tx_nrllm_agentrun',
            ['queued_request' => $sealed],
            ['uuid' => $uuid],
        );
        $result = $runtime->runQueued($uuid);
        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        self::assertSame(1, $this->capturedActor()->backendUserUid);
    }

    #[Test]
    public function explicitBadQueuedActorsNeverBecomeTheStoredBackendOwner(): void
    {
        foreach ([null, 'invalid', AiActorContext::backendUser(2)->toArray()] as $actor) {
            $runtime = $this->runtime();
            $uuid = $runtime->enqueue($this->request(AiActorContext::backendUser(1)));
            $data = json_decode(
                $this->storedRun($uuid)->queuedRequest ?? '',
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($data);
            $data['actor'] = $actor;
            $sealed = $this->codec->encode(
                json_encode($data, JSON_THROW_ON_ERROR),
                AgentStateCodec::PURPOSE_QUEUED_REQUEST,
            );
            $this->connection->update(
                'tx_nrllm_agentrun',
                ['queued_request' => $sealed],
                ['uuid' => $uuid],
            );
            $result = $runtime->runQueued($uuid);
            self::assertNotNull($result);
            self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
            self::assertSame([], $this->actors);
        }
    }

    private function capturedActor(): AiActorContext
    {
        $actor = $this->actors[0] ?? null;
        self::assertInstanceOf(AiActorContext::class, $actor);
        return $actor;
    }
}
