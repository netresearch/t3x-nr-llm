<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClientPushSettings;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aPushReceiver;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aPushRegistrationStore;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDelegationGate;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDelegationTool;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDelegationToolProvider;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDependencyCanceller;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDependencyPoller;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aPushRegistrar;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aAgentCardBuilder;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcServer;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerMiddleware;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerSettings;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\DependencyResumeCoordinator;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixture\AgentRunRepositoryFactory;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server\A2aOneSiteFinder;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The push receiver of the client role (spec 051 A2A-PUSH6 to A2A-PUSH8,
 * ADR-285), end to end: a run delegates, nr-llm registers a callback at the
 * remote, the remote calls the endpoint, and the poller reconciles by
 * GetTask as the owner.
 */
#[CoversClass(A2aPushReceiver::class)]
#[CoversClass(A2aPushRegistrationStore::class)]
#[CoversClass(A2aPushRegistrar::class)]
#[CoversClass(A2aClientPushSettings::class)]
#[CoversClass(A2aServerMiddleware::class)]
#[CoversClass(A2aDependencyPoller::class)]
#[CoversClass(A2aDelegationTool::class)]
#[CoversClass(A2aClient::class)]
final class A2aPushReceiverTest extends AbstractFunctionalTestCase
{
    private const OWNER = 1;

    private const BASE = 'https://agent.example.org/a2a';

    private const PUSH_URL = 'https://agent.example.org/a2a/push';

    /** Every key named, so no template default leaks in between tests. */
    private const CLIENT_SETTINGS = ['push' => '1', 'pushSite' => 'agent', 'maxPushInboxPerTask' => '2', 'maxPushInbox' => '100', 'maxPushBytes' => '2048'];

    private ScriptedA2aTransport $transport;

    private AgentRunPersister $persister;

    private A2aSendJournal $journal;

    private LlmConfiguration $configuration;

    private A2aOneSiteFinder $sites;

    private ?ToolLoopService $loop = null;

    /** @var list<AiActorContext> */
    private array $recordedActors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(2);
        $pool            = $this->getService(ConnectionPool::class);
        $this->transport = new ScriptedA2aTransport();
        $this->journal   = new A2aSendJournal($pool);
        $this->persister = new AgentRunPersister(
            AgentRunRepositoryFactory::create($pool, $this->getService(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
        $this->configuration = new LlmConfiguration();
        $this->configuration->_setProperty('uid', 1);
        $this->configuration->setIsActive(true);

        $this->sites = new A2aOneSiteFinder(new Site('agent', 1, ['base' => 'https://agent.example.org/', 'settings' => ['nrllm' => ['a2a' => ['baseUrl' => self::BASE]]]]));
        $this->settings(['server' => ['enabled' => '0'], 'client' => self::CLIENT_SETTINGS]);
    }

    protected function tearDown(): void
    {
        $this->settings(null);
        parent::tearDown();
    }

    /**
     * A2A-OPS2: with `a2a.client.push` off (the default) a delegation
     * registers nothing and sends nothing beyond SendMessage, and the
     * endpoint answers as if it did not exist: the request passes through to
     * the next handler, with the server role off and on.
     */
    #[Test]
    public function withPushOffNothingRegistersAndTheEndpointDoesNotExist(): void
    {
        $this->settings(['server' => ['enabled' => '0'], 'client' => ['push' => '0'] + self::CLIENT_SETTINGS]);
        $this->agent();
        $this->suspendedRun();

        self::assertSame(['SendMessage'], $this->methods());
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::TABLE));

        foreach (['0', '1'] as $server) {
            $this->settings(['server' => ['enabled' => $server, 'streaming' => '0'], 'client' => ['push' => '0'] + self::CLIENT_SETTINGS]);
            $response = $this->notify('{"task":{}}', 'Bearer nrpush_0123456789abcdef_' . str_repeat('A', 43));
            self::assertSame('1', $response->getHeaderLine('X-Test-Passed-Through'), 'server role ' . $server);
        }
    }

    /**
     * A2A-PUSH7 without an embedded config: send, store the remote task id,
     * create the local registration, create the remote one, then GetTask
     * once. The callback credential travels in `authentication` only, is
     * stored as prefix and hash, and the remote's config id is kept.
     */
    #[Test]
    public function aDelegationRegistersItsCallbackInTheFixedOrder(): void
    {
        $this->agent();
        [$run] = $this->suspendedRun();

        self::assertSame(['SendMessage', 'CreateTaskPushNotificationConfig', 'GetTask'], $this->methods());
        $params = ScriptedA2aTransport::envelopeOf($this->transport->requests[1])['params'] ?? null;
        self::assertIsArray($params);
        self::assertSame('task-1', $params['taskId'] ?? null);
        self::assertSame(self::PUSH_URL, $params['url'] ?? null);
        self::assertArrayNotHasKey('token', $params);
        $credential = $this->credentialOf(1);
        self::assertIsArray($params['authentication'] ?? null);
        self::assertSame('Bearer', $params['authentication']['scheme'] ?? null);
        self::assertMatchesRegularExpression('/^nrpush_[a-f0-9]{16}_[A-Za-z0-9_-]{43}$/', $credential);

        $row = $this->registration();
        self::assertSame(['active', 'remote-config-1', 'task-1', self::OWNER, $run->uid], [$row['state'], $row['remote_config_id'], $row['remote_task_id'], (int) $row['owner_uid'], (int) $row['run_uid']]);
        self::assertSame(hash('sha256', $credential), $row['credential_hash']);
        foreach ($this->db(A2aPushRegistrationStore::TABLE)->select(['*'], A2aPushRegistrationStore::TABLE)->fetchAllAssociative() as $stored) {
            self::assertStringNotContainsString(substr($credential, 24), (string) json_encode($stored));
        }
    }

    /**
     * A record whose card does not declare push notifications is never asked
     * for a config.
     */
    #[Test]
    public function aCardWithoutPushIsNotAskedToRegister(): void
    {
        $this->agent(push: false);
        $this->suspendedRun();

        self::assertSame(['SendMessage'], $this->methods());
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::TABLE));
    }

    /**
     * A2A-PUSH6: a missing, malformed, unknown or wrong credential is 401 and
     * stores nothing, with one answer for all. `X-A2A-Notification-Token`
     * neither authenticates nor refuses, and neither `A2A-Version` nor a
     * content type is required (a2a-python 1.3.0 sends neither).
     */
    #[Test]
    public function onlyTheRegisteredCredentialIsAccepted(): void
    {
        $this->agent();
        $this->suspendedRun();
        $credential = $this->credentialOf(1);
        $wrong      = substr($credential, 0, -4) . (str_ends_with($credential, 'AAAA') ? 'BBBB' : 'AAAA');
        $unknown    = 'nrpush_0123456789abcdef_' . substr($credential, 24);

        $answers = [];
        foreach (['' => [], 'malformed' => ['Authorization' => 'Bearer x'], 'unknown' => ['Authorization' => 'Bearer ' . $unknown], 'wrong' => ['Authorization' => 'Bearer ' . $wrong], 'header only' => ['X-A2A-Notification-Token' => $credential]] as $case => $headers) {
            $response = $this->notify($this->statusBody('TASK_STATE_COMPLETED'), null, $headers);
            self::assertSame(401, $response->getStatusCode(), (string) $case);
            self::assertSame('Bearer realm="a2a-push"', $response->getHeaderLine('WWW-Authenticate'), (string) $case);
            $answers[] = (string) $response->getBody();
        }

        self::assertCount(1, array_unique($answers), 'one answer for every refusal');
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));

        $accepted = $this->notify($this->statusBody('TASK_STATE_COMPLETED'), 'Bearer ' . $credential, ['X-A2A-Notification-Token' => 'anything else', 'Content-Type' => 'application/json']);
        self::assertSame(202, $accepted->getStatusCode());
        self::assertSame(1, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));

        $body = $this->db(A2aPushRegistrationStore::INBOX_TABLE)->select(['body'], A2aPushRegistrationStore::INBOX_TABLE)->fetchOne();
        self::assertIsString($body);
        self::assertStringNotContainsString('TASK_STATE_COMPLETED', $body, 'sealed at rest');
        self::assertSame($this->statusBody('TASK_STATE_COMPLETED'), $this->getService(AgentStateCodec::class)->decode($body, AgentStateCodec::PURPOSE_A2A_PUSH_INBOX));
    }

    /**
     * A2A-SEC6 for the receiver: a body above `a2a.client.maxPushBytes` is
     * 413 and stores nothing. A declared length over the limit is refused
     * before a byte is read; without a declared length the read stops one
     * byte past the limit.
     */
    #[Test]
    public function anOversizedCallbackIsRefusedBeforeItIsRead(): void
    {
        $this->agent();
        $this->suspendedRun();
        $credential = 'Bearer ' . $this->credentialOf(1);

        $stream   = new A2aRecordingStream(str_repeat('x', 5000));
        $response = $this->notify($stream, $credential, ['Content-Length' => '5000']);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(0, $stream->bytesRead, 'refused before the body is read');

        $stream   = new A2aRecordingStream('{"task":{"id":"task-1","pad":"' . str_repeat('x', 5000) . '"}}');
        $response = $this->notify($stream, $credential);
        self::assertSame(413, $response->getStatusCode());
        self::assertLessThanOrEqual(2049, $stream->bytesRead, 'read up to one byte past the limit');

        $response = $this->notify('not json', $credential);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
    }

    /**
     * A2A-PUSH8: a callback that arrives while the remote's answer to the
     * create is still on its way is kept against the pending registration.
     */
    #[Test]
    public function aCallbackBeforeTheCreateIsAcknowledgedIsKept(): void
    {
        $this->agent();
        $early = null;
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_SUBMITTED')]]);
        $this->transport->then(function (A2aHttpRequest $request) use (&$early): A2aTransportResult {
            $params = ScriptedA2aTransport::envelopeOf($request)['params'] ?? [];
            $auth   = is_array($params) && is_array($params['authentication'] ?? null) ? $params['authentication'] : [];
            self::assertSame('pending', $this->registration()['state']);
            $early = $this->notify($this->statusBody('TASK_STATE_WORKING'), 'Bearer ' . self::text($auth['credentials'] ?? null))->getStatusCode();

            return A2aTransportResult::response(200, 'application/json', '', (string) json_encode(['jsonrpc' => '2.0', 'id' => ScriptedA2aTransport::idOf($request), 'result' => ['id' => 'remote-config-1', 'taskId' => 'task-1', 'url' => self::PUSH_URL]]), false);
        });
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        $this->suspendedRun(scripted: true);

        self::assertSame(202, $early);
        self::assertSame(1, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
        self::assertSame('active', $this->registration()['state']);
    }

    /**
     * A2A-PUSH8: the inbox is bounded per registration and in total; over a
     * bound the callback is 429 and nothing is stored. A look deletes the
     * callbacks it handled, so they no longer count.
     */
    #[Test]
    public function theInboxIsBoundedPerRegistrationAndInTotal(): void
    {
        $this->agent();
        [$run, $checkpoint] = $this->suspendedRun();
        $credential         = 'Bearer ' . $this->credentialOf(1);

        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode());
        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode());
        $full = $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential);
        self::assertSame(429, $full->getStatusCode());
        self::assertNotSame('', $full->getHeaderLine('Retry-After'));
        self::assertSame(2, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));

        // Handled callbacks are deleted and free the bound.
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        self::assertSame(1, $this->poller()->tick($this->lastLookAt() + 10)->waiting);
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode());

        // The bound in total: 1 callback across the installation.
        $this->settings(['server' => ['enabled' => '0'], 'client' => ['maxPushInbox' => '1'] + self::CLIENT_SETTINGS]);
        self::assertSame(429, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode());
        self::assertSame(1, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $this->runOf($run->uuid)->statusEnum());
        self::assertSame('task-1', $checkpoint->remoteTaskId);
    }

    /**
     * A2A-PUSH8: a stored callback is a signal. It brings the next GetTask
     * forward, never closer than the record's minimum interval after the
     * last look; GetTask as the owner decides the state, not the body. A
     * duplicate or late callback never resumes the run a second time.
     */
    #[Test]
    public function aCallbackBringsTheNextGetTaskForwardAndGetTaskDecides(): void
    {
        $this->agent();
        [$run]      = $this->suspendedRun();
        $poller     = $this->poller();
        $start      = $this->lastLookAt();
        $credential = 'Bearer ' . $this->credentialOf(1);

        // Two looks without news: next poll at start + 10 + 20.
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        self::assertSame(1, $poller->tick($start + 10)->waiting);
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        self::assertSame(1, $poller->tick($start + 10)->notDue, 'not due without a callback');
        self::assertSame(['SendMessage', 'CreateTaskPushNotificationConfig', 'GetTask', 'GetTask'], $this->methods());
        self::assertSame(1, $poller->tick($start + 20)->waiting);
        self::assertSame($start + 20, $this->lastLookAt());

        // The body claims COMPLETED; it is only a signal.
        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_COMPLETED'), $credential)->getStatusCode());
        self::assertSame(1, $poller->tick($start + 25)->notDue, 'never closer than the minimum interval after the last look');
        self::assertCount(5, $this->transport->requests);

        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        self::assertSame(1, $poller->tick($start + 30)->waiting, 'brought forward from start + 40');
        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $this->runOf($run->uuid)->statusEnum(), 'GetTask said WORKING');
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE), 'the look deleted the callback');

        // GetTask says COMPLETED: the run resumes once, as its owner.
        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_COMPLETED'), $credential)->getStatusCode());
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_COMPLETED', 'Three things.')]);
        self::assertSame(1, $poller->tick($start + 40)->resumed);
        self::assertSame(AgentRunStatus::COMPLETED, $this->runOf($run->uuid)->statusEnum());
        self::assertCount(1, $this->recordedActors);
        self::assertSame(self::OWNER, $this->recordedActors[0]->backendUserUid);
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::TABLE), 'the registration closes with the dependency');
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));

        // A late duplicate: refused, and nothing runs again.
        self::assertSame(401, $this->notify($this->statusBody('TASK_STATE_COMPLETED'), $credential)->getStatusCode());
        self::assertSame(0, $poller->tick($start + 100)->resumed);
        self::assertSame(1, array_count_values($this->methods())['SendMessage']);
        self::assertCount(1, $this->recordedActors);
    }

    /**
     * A2A-SEC7: a create whose outcome is unknown is never sent again; the
     * remote may hold the config, so its callbacks are still accepted, and
     * polling stays the repair path.
     */
    #[Test]
    public function aCreateWithAnUnknownOutcomeIsNotResent(): void
    {
        $this->agent();
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_SUBMITTED')]]);
        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::maybeSent('TIMEOUT', 'no answer within the read timeout'));
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        $this->suspendedRun(scripted: true);

        self::assertSame(['outcome_unknown', ''], [$this->registration()['state'], $this->registration()['remote_config_id']]);
        $start = $this->lastLookAt();
        foreach ([10, 20, 40] as $offset) {
            $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
            $this->poller()->tick($start + $offset);
        }

        self::assertSame(1, array_count_values($this->methods())['CreateTaskPushNotificationConfig']);
        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), 'Bearer ' . $this->credentialOf(1))->getStatusCode());
    }

    /**
     * A refused create leaves a registration the remote never received: its
     * credential is no longer accepted, and the run waits by polling. No
     * GetTask closes a gap that push never opened.
     */
    #[Test]
    public function aRefusedCreateFallsBackToPolling(): void
    {
        $this->agent();
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_SUBMITTED')]]);
        $this->transport->thenJson(['error' => ['code' => -32003, 'message' => 'Push notifications are not supported.']]);
        [$run] = $this->suspendedRun(scripted: true);

        self::assertSame(['SendMessage', 'CreateTaskPushNotificationConfig'], $this->methods());
        self::assertSame('refused', $this->registration()['state']);
        self::assertSame(401, $this->notify($this->statusBody('TASK_STATE_WORKING'), 'Bearer ' . $this->credentialOf(1))->getStatusCode());
        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $this->runOf($run->uuid)->statusEnum());
    }

    /**
     * A remote whose task settled between the send and the registration: the
     * one GetTask after the create returns the result without suspending.
     */
    #[Test]
    public function theGapClosingGetTaskReturnsASettledTask(): void
    {
        $this->agent();
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_SUBMITTED')]]);
        $this->transport->thenJson(['result' => ['id' => 'remote-config-1', 'taskId' => 'task-1', 'url' => self::PUSH_URL]]);
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_COMPLETED', 'Done early.')]);

        $runtime = $this->runtime([
            $this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'summarise'])]),
            $this->finalTurn('done'),
        ]);

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::TABLE), 'nothing left to wait for');
    }

    /**
     * The run purge removes a delegation's registration and its callbacks
     * with the run.
     */
    #[Test]
    public function theRunPurgeRemovesTheRegistrationAndItsCallbacks(): void
    {
        $this->agent();
        $this->suspendedRun();
        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), 'Bearer ' . $this->credentialOf(1))->getStatusCode());

        $purged = AgentRunRepositoryFactory::create($this->getService(ConnectionPool::class), $this->getService(AgentStateCodec::class))->purgeUnfinishedOlderThan(time() + 60);

        self::assertSame(1, $purged);
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::TABLE));
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
    }

    /**
     * Cancelling a waiting run closes its registration with it: a later
     * callback with the right credential is 401 and stores nothing, and the
     * callbacks already stored go too.
     */
    #[Test]
    public function cancellingAWaitingRunClosesItsRegistration(): void
    {
        $this->agent();
        [$run]      = $this->suspendedRun();
        $credential = 'Bearer ' . $this->credentialOf(1);
        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode());
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_CANCELED')]);

        self::assertTrue($this->canceller()->cancelWaiting($this->runOf($run->uuid)));

        self::assertSame(0, $this->rows(A2aPushRegistrationStore::TABLE));
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
        self::assertSame(401, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode());
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
    }

    /**
     * At the deadline the run resumes with "timed out" and its registration
     * closes.
     */
    #[Test]
    public function theDeadlineClosesTheRegistration(): void
    {
        $this->agent();
        [, $checkpoint] = $this->suspendedRun();
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_CANCELED')]);

        self::assertSame(1, $this->poller()->tick($checkpoint->deadline)->timedOut);

        self::assertSame(0, $this->rows(A2aPushRegistrationStore::TABLE));
        self::assertSame(401, $this->notify($this->statusBody('TASK_STATE_WORKING'), 'Bearer ' . $this->credentialOf(1))->getStatusCode());
    }

    /**
     * A run that ended any other way (failed, reaped, settled by another
     * path) no longer waits: its registration accepts no callback, and the
     * next poller tick deletes the registration and its callbacks, so they
     * no longer count against the inbox bound.
     */
    #[Test]
    public function aRegistrationOfARunThatNoLongerWaitsAcceptsNothing(): void
    {
        $this->agent();
        [$run]      = $this->suspendedRun();
        $credential = 'Bearer ' . $this->credentialOf(1);
        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode());
        $this->db('tx_nrllm_agentrun')->update('tx_nrllm_agentrun', ['status' => AgentRunStatus::FAILED->value], ['uid' => $run->uid]);

        self::assertSame(401, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode());
        self::assertSame(1, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));

        $this->poller()->tick(time());

        self::assertSame(0, $this->rows(A2aPushRegistrationStore::TABLE));
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
    }

    /**
     * A2A-PUSH8: a look deletes the callbacks it handled, so the bound per
     * delegation also bounds what is stored. A remote that keeps its task
     * working and fills the bound after every look never holds more rows
     * than the bound.
     */
    #[Test]
    public function handledCallbacksAreDeletedSoTheBoundAlsoBoundsStorage(): void
    {
        $this->agent();
        $this->suspendedRun();
        $poller     = $this->poller();
        $credential = 'Bearer ' . $this->credentialOf(1);

        foreach ([1, 2, 3] as $round) {
            self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode(), 'round ' . $round);
            self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode(), 'round ' . $round);
            self::assertSame(429, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode(), 'round ' . $round);
            self::assertSame(2, $this->rows(A2aPushRegistrationStore::INBOX_TABLE), 'never more than the bound, round ' . $round);

            $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
            self::assertSame(1, $poller->tick($this->lastLookAt() + 10)->waiting, 'round ' . $round);
            self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE), 'the look deletes what it handled, round ' . $round);
        }
    }

    /**
     * A2A-PUSH8: a look handles only the callbacks received until it
     * started. One received later stays and brings the next look forward.
     */
    #[Test]
    public function aCallbackReceivedAfterTheLookStaysForTheNextOne(): void
    {
        $this->agent();
        $this->suspendedRun();
        $poller = $this->poller();
        $start  = $this->lastLookAt();
        $uid    = $this->registration()['uid'] ?? null;
        self::assertIsNumeric($uid);

        // Two looks without news: the next one is due at start + 40.
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        self::assertSame(1, $poller->tick($start + 10)->waiting);
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);

        // Received at start + 25, while the look at start + 20 was under way.
        self::assertSame(A2aPushRegistrationStore::STORED, $this->store()->store((int) $uid, $this->statusBody('TASK_STATE_WORKING'), $start + 25, 2, 100));
        self::assertSame(1, $poller->tick($start + 20)->waiting);
        self::assertSame(1, $this->rows(A2aPushRegistrationStore::INBOX_TABLE), 'received after the look began');

        self::assertSame(1, $poller->tick($start + 25)->notDue);
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        self::assertSame(1, $poller->tick($start + 30)->waiting, 'brought forward from start + 40');
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
    }

    /**
     * A2A-PUSH8: when the remote's result continues the run, the
     * delegation's registration closes at once, even when the run goes on to
     * wait for a second delegation: the first credential is refused and
     * stores nothing, the second one is accepted.
     */
    #[Test]
    public function aResolvedDelegationClosesItsRegistrationWhileTheRunWaitsAgain(): void
    {
        [$first, $second] = $this->runThatDelegatesTwice(deadline: false);

        self::assertSame(401, $this->notify($this->statusBody('TASK_STATE_WORKING'), $first)->getStatusCode());
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), $second)->getStatusCode());
        self::assertSame(1, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
    }

    /**
     * The same at the deadline: the run continues with "timed out", waits
     * for a second delegation, and the first registration is closed.
     */
    #[Test]
    public function aTimedOutDelegationClosesItsRegistrationWhileTheRunWaitsAgain(): void
    {
        [$first, $second] = $this->runThatDelegatesTwice(deadline: true);

        self::assertSame(401, $this->notify($this->statusBody('TASK_STATE_WORKING'), $first)->getStatusCode());
        self::assertSame(0, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), $second)->getStatusCode());
        self::assertSame(1, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
    }

    /**
     * A2A-PUSH6: a callback for a delegation to a remote agent record that is
     * disabled, deleted or gone is 401 and stores nothing; enabling the
     * record again accepts the credential again.
     */
    #[Test]
    public function aDisabledOrDeletedAgentTakesNoCallback(): void
    {
        $agent = $this->agent();
        $this->suspendedRun();
        $credential = 'Bearer ' . $this->credentialOf(1);
        $records    = $this->db('tx_nrllm_a2a_agent');

        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode());

        $records->update('tx_nrllm_a2a_agent', ['enabled' => 0], ['uid' => $agent->getUid()]);
        self::assertSame(401, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode(), 'disabled');
        self::assertSame(1, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));

        $records->update('tx_nrllm_a2a_agent', ['enabled' => 1], ['uid' => $agent->getUid()]);
        self::assertSame(202, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode(), 'enabled again');
        self::assertSame(2, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));

        $records->update('tx_nrllm_a2a_agent', ['deleted' => 1], ['uid' => $agent->getUid()]);
        self::assertSame(401, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode(), 'deleted');

        $records->delete('tx_nrllm_a2a_agent', ['uid' => $agent->getUid()]);
        self::assertSame(401, $this->notify($this->statusBody('TASK_STATE_WORKING'), $credential)->getStatusCode(), 'gone');
        self::assertSame(2, $this->rows(A2aPushRegistrationStore::INBOX_TABLE));
    }

    // ------------------------------------------------------------------

    /**
     * A run delegates, suspends, is continued by the poller (with the
     * remote's result, or at the deadline) and delegates again, so it waits
     * for the second remote task when the tick ends.
     *
     * @return array{string, string} the Authorization headers of the first and the second registration
     */
    private function runThatDelegatesTwice(bool $deadline): array
    {
        $this->agent();
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_SUBMITTED')]]);
        $this->transport->thenJson(['result' => ['id' => 'remote-config-1', 'taskId' => 'task-1', 'url' => self::PUSH_URL]]);
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);

        $runtime = $this->runtime([
            $this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'summarise the report'])]),
            $this->toolTurn([new ToolCall('d2', $this->skillTool(), ['message' => 'summarise the appendix'])]),
            $this->finalTurn('both summaries are in'),
        ]);
        $suspended = $runtime->run($this->request());
        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $suspended->outcome, (string) $suspended->error);
        $run     = $this->runOf($suspended->runUuid);
        $decoded = json_decode((string) $run->suspendedState, true);
        self::assertIsArray($decoded);
        $checkpoint = DependencyCheckpoint::fromStoredState($decoded);
        self::assertInstanceOf(DependencyCheckpoint::class, $checkpoint);

        // The look (or, at the deadline, CancelTask), then the second delegation.
        $this->transport->thenJson(['result' => $this->task('task-1', $deadline ? 'TASK_STATE_CANCELED' : 'TASK_STATE_COMPLETED', 'Three things.')]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-2', 'TASK_STATE_SUBMITTED')]]);
        $this->transport->thenJson(['result' => ['id' => 'remote-config-2', 'taskId' => 'task-2', 'url' => self::PUSH_URL]]);
        $this->transport->thenJson(['result' => $this->task('task-2', 'TASK_STATE_WORKING')]);

        $report = $this->poller()->tick($deadline ? $checkpoint->deadline : $this->lastLookAt() + 10);

        self::assertSame(1, $deadline ? $report->timedOut : $report->resumed);
        self::assertSame(['SendMessage', 'CreateTaskPushNotificationConfig', 'GetTask', $deadline ? 'CancelTask' : 'GetTask', 'SendMessage', 'CreateTaskPushNotificationConfig', 'GetTask'], $this->methods());
        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $this->runOf($run->uuid)->statusEnum(), 'waits for the second delegation');
        self::assertSame(1, $this->rows(A2aPushRegistrationStore::TABLE), 'only the second registration is left');

        return ['Bearer ' . $this->credentialOf(1), 'Bearer ' . $this->credentialOf(5)];
    }

    /**
     * Runs the delegation until it suspends. Without $scripted, the remote
     * accepts the task, acknowledges the create and answers WORKING to the
     * one GetTask.
     *
     * @return array{AgentRun, DependencyCheckpoint}
     */
    private function suspendedRun(bool $scripted = false): array
    {
        if (!$scripted) {
            $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_SUBMITTED')]]);
            $this->transport->thenJson(['result' => ['id' => 'remote-config-1', 'taskId' => 'task-1', 'url' => self::PUSH_URL]]);
            $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        }

        $runtime = $this->runtime([
            $this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'summarise the report'])]),
            $this->toolTurn([new ToolCall('r1', 'record_actor', [])]),
            $this->finalTurn('the summary is in'),
        ]);
        $suspended = $runtime->run($this->request());
        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $suspended->outcome, (string) $suspended->error);
        $run = $this->runOf($suspended->runUuid);

        $decoded = json_decode((string) $run->suspendedState, true);
        self::assertIsArray($decoded);
        $checkpoint = DependencyCheckpoint::fromStoredState($decoded);
        self::assertInstanceOf(DependencyCheckpoint::class, $checkpoint);

        return [$run, $checkpoint];
    }

    /**
     * @param array<string, string> $headers
     */
    private function notify(string|StreamInterface $body, ?string $authorization, array $headers = []): ResponseInterface
    {
        if ($authorization !== null) {
            $headers['Authorization'] = $authorization;
        }

        if (is_string($body)) {
            $stream = new Stream('php://temp', 'rw');
            $stream->write($body);
            $stream->rewind();
            $body = $stream;
        }

        $server  = ['HTTP_HOST' => 'agent.example.org', 'REMOTE_ADDR' => '203.0.113.9', 'REQUEST_METHOD' => 'POST', 'SERVER_PORT' => 443, 'HTTPS' => 'on'];
        $request = new ServerRequest(self::PUSH_URL, 'POST', $body, $headers, $server);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        return $this->middleware()->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://temp', 200, ['X-Test-Passed-Through' => '1']);
            }
        });
    }

    private function middleware(): A2aServerMiddleware
    {
        $server = new A2aServerSettings($this->getService(ExtensionConfiguration::class), $this->sites);

        return new A2aServerMiddleware(
            $server,
            $this->sites,
            $this->getService(A2aAgentCardBuilder::class),
            $this->getService(A2aRpcServer::class),
            $this->receiver(),
            $this->pushSettings(),
        );
    }

    private function receiver(): A2aPushReceiver
    {
        return new A2aPushReceiver($this->pushSettings(), $this->store(), $this->limiterStorage ??= new InMemoryStorage(), new NullLogger());
    }

    private ?InMemoryStorage $limiterStorage = null;

    private function pushSettings(): A2aClientPushSettings
    {
        return new A2aClientPushSettings($this->getService(ExtensionConfiguration::class), $this->sites, new A2aServerSettings($this->getService(ExtensionConfiguration::class), $this->sites));
    }

    private function store(): A2aPushRegistrationStore
    {
        return new A2aPushRegistrationStore($this->getService(ConnectionPool::class), $this->getService(AgentStateCodec::class));
    }

    /**
     * The extension configuration `a2a`; null removes it.
     *
     * @param array<string, array<string, string>>|null $a2a
     */
    private function settings(?array $a2a): void
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $configuration = is_array($configuration) ? $configuration : [];

        $extensions = is_array($configuration['EXTENSIONS'] ?? null) ? $configuration['EXTENSIONS'] : [];
        $nrLlm      = is_array($extensions['nr_llm'] ?? null) ? $extensions['nr_llm'] : [];
        if ($a2a === null) {
            unset($nrLlm['a2a']);
        } else {
            $nrLlm['a2a'] = $a2a;
        }

        $extensions['nr_llm']        = $nrLlm;
        $configuration['EXTENSIONS'] = $extensions;
        $GLOBALS['TYPO3_CONF_VARS']  = $configuration;
    }

    /**
     * @param list<CompletionResponse> $responses
     */
    private function runtime(array $responses): AgentRuntime
    {
        $registry = $this->registry();
        $policy   = $this->policy();
        $manager  = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(static function () use (&$responses): CompletionResponse {
            $next = array_shift($responses);
            self::assertInstanceOf(CompletionResponse::class, $next, 'The model was asked more often than scripted.');

            return $next;
        });
        $manager->method('chatWithConfiguration')->willReturn($this->finalTurn('closing'));
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $this->loop = new ToolLoopService($manager, $registry, $policy);

        return new AgentRuntime(
            $this->loop,
            $this->persister,
            $this->configurations(),
            new NullLogger(),
            $bus,
            actingBackendUserResolver: new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($registry),
            toolPolicy: $policy,
            dependencyCanceller: $this->canceller(),
        );
    }

    private function poller(): A2aDependencyPoller
    {
        self::assertInstanceOf(ToolLoopService::class, $this->loop);
        $registry = $this->registry();

        return new A2aDependencyPoller(
            new DependencyResumeCoordinator(
                $this->persister,
                $this->configurations(),
                $this->loop,
                new AgentRunExecutor($this->loop, $this->persister, new NullLogger(), new ActingBackendUserResolver(), new ToolEffectResolver($registry)),
            ),
            $this->journal,
            $this->getService(A2aRemoteAgentRepository::class),
            $this->client(),
            $this->canceller(),
            $this->vaultScope(),
            pushInbox: $this->store(),
            jitter: static fn (int $interval): int => 0,
        );
    }

    private function canceller(): A2aDependencyCanceller
    {
        return new A2aDependencyCanceller($this->persister, $this->journal, $this->getService(A2aRemoteAgentRepository::class), $this->client(), $this->vaultScope(), pushRegistrations: $this->store());
    }

    private function registry(): ToolRegistry
    {
        $recorder = new class ($this) implements ToolInterface {
            public function __construct(private readonly A2aPushReceiverTest $test) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('record_actor', 'Records who runs it.', ['type' => 'object', 'properties' => new stdClass()]);
            }

            public function execute(array $arguments, ToolExecutionContext $context): ToolResult
            {
                $this->test->record($context->actor);

                return ToolResult::text('recorded');
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

        $provider = new A2aDelegationToolProvider(
            $this->getService(A2aRemoteAgentRepository::class),
            $this->client(),
            $this->journal,
            new A2aResponseParser(),
            new A2aDelegationGate(null, static fn (): ?string => null),
            null,
            new A2aPushRegistrar($this->pushSettings(), $this->store(), $this->client(), new NullLogger()),
        );

        return new ToolRegistry([$recorder], [$provider]);
    }

    public function record(AiActorContext $actor): void
    {
        $this->recordedActors[] = $actor;
    }

    private function policy(): ToolCallPolicyInterface
    {
        $policy = self::createStub(ToolCallPolicyInterface::class);
        $policy->method('skillAllowListForRun')->willReturn(new SkillToolAllowList(null));
        $policy->method('filterOfferable')->willReturnCallback(fn (?array $names): array => array_values($names ?? ['record_actor', $this->skillTool()]));
        $policy->method('decide')->willReturnCallback(static fn (string $name): ToolPolicyDecision => new ToolPolicyDecision($name, true, ToolDataClass::PUBLIC_CONTENT, TrustZone::LOCAL, ToolDataClass::PUBLIC_CONTENT));

        return $policy;
    }

    private function configurations(): LlmConfigurationRepository
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findByUid')->willReturn($this->configuration);

        return $repository;
    }

    private function client(): A2aClient
    {
        return new A2aClient($this->journal, $this->transport, new A2aResponseParser());
    }

    private function vaultScope(): WorkerVaultScope
    {
        return new WorkerVaultScope(self::createStub(TechnicalActorContextInterface::class), self::createStub(ExtensionConfiguration::class), false);
    }

    private function request(): AgentRunRequest
    {
        return new AgentRunRequest($this->configuration, [['role' => 'user', 'content' => 'please delegate']], AiActorContext::backendUser(self::OWNER, workspaceId: 0));
    }

    /**
     * @param list<ToolCall> $calls
     */
    private function toolTurn(array $calls): CompletionResponse
    {
        return new CompletionResponse('', 'fake', UsageStatistics::fromTokens(0, 0), toolCalls: $calls);
    }

    private function finalTurn(string $text): CompletionResponse
    {
        return new CompletionResponse($text, 'fake', UsageStatistics::fromTokens(0, 0));
    }

    /**
     * @return array<string, mixed>
     */
    private function task(string $id, string $state, string $artifact = ''): array
    {
        $task = ['id' => $id, 'contextId' => 'ctx-1', 'status' => ['state' => $state]];
        if ($artifact !== '') {
            $task['artifacts'] = [['artifactId' => 'a1', 'parts' => [['text' => $artifact]]]];
        }

        return $task;
    }

    /**
     * A plain StreamResponse, as an a2a-python 1.3.0 sender posts it.
     */
    private function statusBody(string $state): string
    {
        return json_encode(['task' => $this->task('task-1', $state)], JSON_THROW_ON_ERROR);
    }

    private function skillTool(): string
    {
        $uid = $this->db('tx_nrllm_a2a_agent')->select(['uid'], 'tx_nrllm_a2a_agent', ['identifier' => 'remote'])->fetchOne();

        return A2aDelegationTool::nameFor(is_numeric($uid) ? (int) $uid : 0, 'remote', 'summarise');
    }

    private function agent(bool $push = true): A2aRemoteAgent
    {
        $connection = $this->db('tx_nrllm_a2a_agent');
        $connection->insert('tx_nrllm_a2a_agent', [
            'identifier'                => 'remote',
            'title'                     => 'Remote',
            'requires_approval'         => 0,
            'min_poll_interval_seconds' => 10,
            'max_poll_interval_seconds' => 60,
            'max_wait_seconds'          => 3600,
            'card_url'                  => 'https://agent.example/.well-known/agent-card.json',
            'interface_url'             => 'https://agent.example/a2a/jsonrpc',
            'card_capabilities'         => sprintf('streaming=0;pushNotifications=%d;extendedAgentCard=0', $push ? 1 : 0),
            'card_json'                 => json_encode([
                'name'                => 'Remote',
                'description'         => 'A remote agent',
                'version'             => '1.0.0',
                'supportedInterfaces' => [['url' => 'https://agent.example/a2a/jsonrpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']],
                'capabilities'        => ['pushNotifications' => $push],
                'defaultInputModes'   => ['text/plain'],
                'defaultOutputModes'  => ['text/plain'],
                'skills'              => [['id' => 'summarise', 'name' => 'Summarise', 'description' => 'Summarises a text.', 'tags' => ['text']]],
            ], JSON_THROW_ON_ERROR),
            'credential' => 'vault-credential-id',
            'data_class' => ToolDataClass::PUBLIC_CONTENT->value,
            'enabled'    => 1,
        ]);

        $agent = $this->getService(A2aRemoteAgentRepository::class)->findByUid((int) $connection->lastInsertId());
        self::assertInstanceOf(A2aRemoteAgent::class, $agent);

        return $agent;
    }

    /**
     * The callback credential the create request with this index carried.
     */
    private function credentialOf(int $index): string
    {
        $params = ScriptedA2aTransport::envelopeOf($this->transport->requests[$index])['params'] ?? null;
        self::assertIsArray($params);
        self::assertIsArray($params['authentication'] ?? null);

        return self::text($params['authentication']['credentials'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(): array
    {
        $row = $this->db(A2aPushRegistrationStore::TABLE)->select(['*'], A2aPushRegistrationStore::TABLE)->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function lastLookAt(): int
    {
        $value = $this->registration()['last_look_at'] ?? null;
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function rows(string $table): int
    {
        return $this->db($table)->count('*', $table, []);
    }

    /**
     * @return list<string>
     */
    private function methods(): array
    {
        return array_map(static fn (A2aHttpRequest $request): string => self::text(ScriptedA2aTransport::envelopeOf($request)['method'] ?? null), $this->transport->requests);
    }

    private function runOf(string $uuid): AgentRun
    {
        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    private function db(string $table): Connection
    {
        return $this->getService(ConnectionPool::class)->getConnectionForTable($table);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
